<?php

declare(strict_types=1);

namespace Rx\Thruway;

use Rx\DisposableInterface;
use Rx\Observable;
use Rx\ObserverInterface;

final class SingleInstanceObservable extends Observable
{
    private $hasObservable = false;
    private $source;
    private $observable;

    public function __construct(Observable $source)
    {
        $this->source = $source;
    }

    protected function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        if (!$this->hasObservable) {
            $this->hasObservable = true;

            $this->observable = $this->source
                ->finally(function () {
                    $this->hasObservable = false;
                })
                ->share();
        }

        return $this->observable->subscribe($observer);
    }
}
