<?php

namespace Rx\Thruway\Subject;

use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Rx\React\RejectedPromiseException;
use Thruway\Message\AbortMessage;
use Thruway\Serializer\JsonSerializer;
use React\EventLoop\LoopInterface;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Rx\ObserverInterface;
use Rx\Subject\Subject;

final class WebSocketSubject extends Subject
{
    private $url, $protocols, $socket, $openObserver, $closeObserver, $output, $loop, $serializer;

    public function __construct(string $url, array $protocols = [], Subject $openObserver = null, Subject $closeObserver = null, WebSocket $socket = null, LoopInterface $loop = null)
    {
        $this->url           = $url;
        $this->protocols     = $protocols;
        $this->socket        = $socket;
        $this->openObserver  = $openObserver;
        $this->closeObserver = $closeObserver;
        $this->loop          = $loop ?: \EventLoop\getLoop();
        $this->serializer    = new JsonSerializer();
        $this->output        = new Subject();
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $this->output = new Subject();

        if (!$this->socket) {
            $this->connectSocket();
        }

        $disposable = new CompositeDisposable();

        $disposable->add($this->output->subscribe($observer, $scheduler));

        $disposable->add(new CallbackDisposable(function () {
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

                        $msg = $this->serializer->deserialize($message);

                        if ($msg instanceof AbortMessage) {
                            $this->output->onError(new \Exception($msg->getResponseURI()));
                            return;
                        }

                        $this->output->onNext($msg);
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

    public function dispose()
    {
        parent::dispose();

        if ($this->socket) {
            $this->socket->close();
            $this->socket = null;
        }
    }
}
