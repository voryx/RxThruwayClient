<?php

namespace Rx\Thruway\Observable;

use Rx\Disposable\EmptyDisposable;
use Rx\Observable;
use Rx\React\Promise;
use Rx\ObserverInterface;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Rx\Observer\CallbackObserver;
use React\EventLoop\LoopInterface;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;

class WebSocketObservable extends Observable
{

    private $url;
    private $loop;

    function __construct(string $url = "ws://127.0.0.1:9090/", LoopInterface $loop)
    {
        $this->url  = $url;
        $this->loop = $loop;
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        try {

            $connector  = new Connector($this->loop);
            $disposable = new CompositeDisposable();

            $onNext = function (WebSocket $ws) use ($observer, $disposable) {
                $ws->on("error", [$observer, "onError"]);
                $ws->on("close", [$observer, "onCompleted"]);
                $observer->onNext($ws);

                $disposable->add(new CallbackDisposable(function () use ($ws) {
                    $ws->close();
                }));
            };

            $callbackObserver = new CallbackObserver($onNext, [$observer, 'onError']);

            $subscription = Promise::toObservable($connector($this->url, ['wamp.2.json']))
                ->subscribe($callbackObserver, $scheduler);

            $disposable->add($subscription);

            return $disposable;

        } catch (\Exception $e) {
            $observer->onError($e);
            return new EmptyDisposable();
        }
    }
}
