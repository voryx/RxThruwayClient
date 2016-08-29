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
    private $messages, $value, $count;

    function __construct(Observable $messages)
    {
        $this->messages = $messages;
        $this->value    = null;
        $this->count    = 0;
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
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
