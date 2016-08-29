<?php

namespace Rx\Thruway\Observable;

use Rx\Disposable\CallbackDisposable;
use Thruway\Message\WelcomeMessage;
use Rx\Observer\CallbackObserver;
use Thruway\Message\Message;
use Rx\ObserverInterface;
use Rx\Observable;

final class SessionObservable extends Observable
{
    private $messages, $value, $count, $close;

    function __construct(Observable $messages, Observable $close)
    {
        $this->messages = $messages->share();
        $this->value    = null;
        $this->count    = 0;
        $this->close    = $close;

    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $this->close->subscribe(new CallbackObserver(
            function () {
                $this->value = null;
            }
        ), $scheduler);


        $x = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->doOnEach(new CallbackObserver(
                function ($x) {
                    $this->value = $x;
                },
                function ($e) {
                    $this->value = null;
                },
                function () {
                    $this->value = null;
                }
            ));

        if ($this->value !== null) {
            $x = $x->startWith($this->value);
        }

        $disposable = $x->subscribe($observer, $scheduler);

        $this->count++;
        return new CallbackDisposable(function () use ($disposable) {
            $this->count--;
            if ($this->count === 0) {
                $this->value = null;
            }
            $disposable->dispose();
        });
    }
}
