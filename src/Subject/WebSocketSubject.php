<?php

namespace Rx\Thruway\Subject;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Rx\ObserverInterface;
use Rx\React\RejectedPromiseException;
use Rx\Subject\Subject;
use Thruway\Serializer\JsonSerializer;

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

            $this->wsDisposable = $this->connectSocket($scheduler);
        }

        $disposable = new CompositeDisposable();

        $disposable->add($this->output->subscribe($observer, $scheduler));

        $disposable->add(new CallbackDisposable(function () use (&$wsDisposable) {
            if (!$this->output->hasObservers() && $this->socket) {
                echo "dispose observer", PHP_EOL;
                $this->socket->close();
                $this->socket = null;
            }
        }));

        return $disposable;
    }

    private function connectSocket()
    {
        echo "connecting", PHP_EOL;
        
        try {
            $connector = new Connector($this->loop);

            $connector($this->url, $this->protocols)->then(
                function (WebSocket $ws) {

                    echo "connected", PHP_EOL;

                    $this->socket = $ws;

                    $ws->on("error", [$this->output, "onError"]);
                    $ws->on("close", function ($reason) {

                        echo "closing", PHP_EOL;
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
                        echo "raw message: ", $message, PHP_EOL;
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

        echo "sending raw message: ", $this->serializer->serialize($msg), PHP_EOL;
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

        echo "got completed", PHP_EOL;
        if ($this->closeObserver) {
            $this->closingObserver->onNext('');
        }

        $this->socket->close();
        $this->socket = null;
    }
}
