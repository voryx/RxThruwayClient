<?php

namespace Rx\Thruway\Observable;

use Rx\Observable;
use Rx\ObserverInterface;

class SingleInstanceReplay
{
    private $bufferSize;

    public function __construct(int $bufferSize)
    {
        $this->bufferSize = $bufferSize;
    }

    public function __invoke(Observable $source)
    {
        $hasObservable = false;
        $observable    = null;

        $getObservable = function () use (&$hasObservable, &$observable, $source): Observable {
            if ($hasObservable) {
                return $observable;
            }

            $observable = $source
                ->finally(function () use (&$hasObservable) {
                    $hasObservable = false;
                })
                ->shareReplay($this->bufferSize);

            $hasObservable = true;

            return $observable;
        };

        return new Observable\AnonymousObservable(function (ObserverInterface $o) use ($getObservable) {
            $obs = $getObservable();
            return $obs->subscribe($o);
        });
    }
}
