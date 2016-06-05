<?php

namespace Rx\Thruway;

use Exception;
use Rx\ObserverInterface;

class LoggerObserver implements ObserverInterface
{

    private $prefix;

    /**
     * LoggerObserver constructor.
     * @param $prefix
     */
    public function __construct($prefix)
    {
        $this->prefix = $prefix;
    }
    
    public function onCompleted()
    {
        echo $this->prefix.': Completed', PHP_EOL;
    }

    public function onError(Exception $error)
    {
        echo $this->prefix.': Error: ', json_encode($error), PHP_EOL;
    }

    public function onNext($value)
    {
        echo $this->prefix.': Next: ', json_encode($value), PHP_EOL;
    }

}