<?php

namespace Rx\Thruway\Subject;

use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Rx\React\RejectedPromiseException;
use Thruway\Serializer\JsonSerializer;
use React\EventLoop\LoopInterface;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Rx\ObserverInterface;
use Rx\Subject\Subject;

final class WebSocketSubject extends Subject
{
    private $url, $protocols, $socket, $openObserver, $closeObserver, $closingObserver, $output, $loop, $serializer;

    public function __construct(string $url, array $protocols = [], Subject $openObserver = null, Subject $closeObserver = null, Subject $closingObserver = null, WebSocket $socket = null, LoopInterface $loop = null)
    {
        $this->url             = $url;
        $this->protocols       = $protocols;
        $this->socket          = $socket;
        $this->openObserver    = $openObserver;
        $this->closeObserver   = $closeObserver;
        $this->closingObserver = $closingObserver;
        $this->loop            = $loop ?: \EventLoop\getLoop();
        $this->serializer      = new JsonSerializer();
        $this->output          = new Subject();
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $this->output = new Subject();

        if (!$this->socket) {
            $this->connectSocket();
        }

        $disposable = new CompositeDisposable();

        $disposable->add($this->output->subscribe($observer, $scheduler));

        $disposable->add(new CallbackDisposable(function () use (&$wsDisposable) {
            if (!$this->output->hasObservers() && $this->socket) {
                $this->socket->close();
                $this->socket = null;
            }
        }));

        return $disposable;
    }

    private function connectSocket()
    {
        try {
            $connector = new Connector($this->loop);

            $connector($this->url, $this->protocols)->then(
                function (WebSocket $ws) {
                    $this->socket = $ws;

                    $ws->on("error", [$this->output, "onError"]);
                    $ws->on("close", function ($reason) {

                        if ($this->closeObserver) {
                            $this->closeObserver->onNext($reason);
                        }

                        if ($reason === 1000) {
                            $this->output->onCompleted();
                            return;
                        }
                        $this->output->onError(new \Exception($reason));
                    });

                    $ws->on("message", function ($message) {
                        $this->output->onNext($this->serializer->deserialize($message));
                    });

                    if ($this->openObserver) {
                        $this->openObserver->onNext($this);
                    }
                },
                function ($error) {
                    $error = $error instanceof \Exception ? $error : new RejectedPromiseException($error);
                    $this->output->onError($error);
                });

        } catch (\Exception $ex) {
            $this->output->onError($ex);
        }
    }

    public function onNext($msg)
    {
        if (!$this->socket) {
            return;
        }

        $this->socket->send($this->serializer->serialize($msg));
    }

    public function onError(\Exception $exception)
    {
        if (!$this->socket) {
            return;
        }

        if ($this->closingObserver) {
            $this->closingObserver->onNext('');
        }

        $this->socket->close(1001, $exception->getMessage());
        $this->socket = null;
    }

    public function onCompleted()
    {
        if (!$this->socket) {
            return;
        }

        if ($this->closeObserver) {
            $this->closingObserver->onNext('');
        }

        $this->socket->close();
        $this->socket = null;
    }
}
