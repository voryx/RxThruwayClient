<?php

namespace Rx\Thruway\Subject;

use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Timer\Timer;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Rx\DisposableInterface;
use Thruway\Message\AbortMessage;
use Thruway\Serializer\JsonSerializer;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Rx\ObserverInterface;
use Rx\Subject\Subject;
use WyriHaximus\React\AsyncInteropLoop\AsyncInteropLoop;

final class WebSocketSubject extends Subject
{
    private $url, $protocols, $socket, $openObserver, $closeObserver, $output, $loop, $serializer;

    public function __construct(string $url, array $protocols = [], Subject $openObserver = null, Subject $closeObserver = null, WebSocket $socket = null)
    {
        $this->url           = $url;
        $this->protocols     = $protocols;
        $this->socket        = $socket;
        $this->openObserver  = $openObserver;
        $this->closeObserver = $closeObserver;
        $this->serializer    = new JsonSerializer();
        $this->loop          = new AsyncInteropLoop();
        $this->output        = new Subject();
    }

    public function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        $this->output = new Subject();

        if (!$this->socket) {
            $this->connectSocket();
        }

        $disposable = new CompositeDisposable();

        $disposable->add($this->output->subscribe($observer));

        $disposable->add(new CallbackDisposable(function () {
            if ($this->socket && !$this->output->hasObservers()) {
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

                    $lastReceivedPong = 0;
                    $pingTimer        = $this->loop->addPeriodicTimer(30, function (Timer $timer) use ($ws, &$lastReceivedPong) {
                        static $sequence = 0;

                        if ($lastReceivedPong !== $sequence) {
                            $timer->cancel();
                            $ws->close();
                        }

                        $sequence++;
                        $frame = new Frame($sequence, true, Frame::OP_PING);
                        $ws->send($frame);

                    });

                    $ws->on('pong', function (Frame $frame) use (&$lastReceivedPong) {
                        $lastReceivedPong = $frame->getPayload();
                    });

                    $ws->on('error', function (\Exception $ex) use ($pingTimer) {
                        $pingTimer->cancel();
                        $this->output->onError($ex);
                    });
                    $ws->on('close', function ($reason) use ($pingTimer) {
                        $pingTimer->cancel();
                        if ($this->closeObserver) {
                            $this->closeObserver->onNext($reason);
                        }
//                        Until we can figure out a better way, handle all closes as errors
//                        if ($reason === 1000) {
//                            $this->output->onCompleted();
//                            return;
//                        }
                        $this->output->onError(new \Exception($reason));
                    });

                    $ws->on('message', function ($message) {

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
                    $error = $error instanceof \Exception ? $error : new \Exception($error);
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
