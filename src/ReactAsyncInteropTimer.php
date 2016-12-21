<?php

namespace Rx\Thruway;

use Interop\Async\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;

class ReactAsyncInteropTimer extends Timer
{
    private $timerKey;

    public function __construct($timerKey, $interval, callable $callback, LoopInterface $loop, $isPeriodic = false, $data = null)
    {
        $this->timerKey = $timerKey;

        parent::__construct($loop, $interval, $callback, $isPeriodic, $data);
    }

    public function cancel()
    {
        Loop::get()->cancel($this->timerKey);
    }
}
