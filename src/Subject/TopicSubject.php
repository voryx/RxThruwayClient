<?php

namespace Rx\Thruway\Subject;

use Rx\Observable;
use Rx\ObserverInterface;
use Rx\Subject\Subject;

class TopicSubject extends Subject
{
    private $observer, $observable;

    public function __construct(ObserverInterface $observer, Observable $observable)
    {
        $this->observer   = $observer;
        $this->observable = $observable;
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        return call_user_func_array([$this->observable, 'subscribe'], [$observer, $scheduler]);
    }

    public function onNext($value)
    {
        return call_user_func([$this->observer, 'onNext'], $value);
    }

    public function onError(\Exception $exception)
    {
        return call_user_func([$this->observer, 'onError'], $exception);
    }

    public function onCompleted()
    {
        return call_user_func([$this->observer, 'onCompleted']);
    }
}