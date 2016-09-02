<?php

namespace Rx\Thruway\Subject;

use Rx\Disposable\CallbackDisposable;
use Rx\Observable;
use Rx\Observer\ScheduledObserver;
use Rx\ObserverInterface;
use Rx\Scheduler\ImmediateScheduler;
use Rx\SchedulerInterface;
use Rx\Subject\Subject;

class SessionReplaySubject extends Subject
{
    /** @var bool */
    private $hasError = false;

    /** @var SchedulerInterface */
    private $scheduler;

    private $value;

    public function __construct(Observable $close)
    {
        $this->scheduler  = new ImmediateScheduler();

        $close->subscribeCallback(function () {
            $this->value = null;
        });
    }

    public function subscribe(ObserverInterface $observer, SchedulerInterface $scheduler = null)
    {
        $this->assertNotDisposed();

        if (!$scheduler) {
            $scheduler = $this->scheduler;
        }
        $so = new ScheduledObserver($scheduler, $observer);

        $subscription = $this->createRemovableDisposable($this, $so);

        $this->observers[] = $so;

        if ($this->value){
            $so->onNext($this->value);
        }

        if ($this->hasError) {
            $so->onError($this->exception);
        } else {
            if ($this->isStopped) {
                $so->onCompleted();
            }
        }

        $so->ensureActive();

        return $subscription;
    }

    public function onNext($value)
    {
        $this->assertNotDisposed();

        if ($this->isStopped) {
            return;
        }

        $this->value = $value;

        /** @var ScheduledObserver $observer */
        foreach ($this->observers as $observer) {
            $observer->onNext($value);
            $observer->ensureActive();
        }
    }

    private function createRemovableDisposable($subject, $observer)
    {
        return new CallbackDisposable(function () use ($observer, $subject) {
            $observer->dispose();
            if (!$subject->isDisposed()) {
                array_splice($subject->observers, array_search($observer, $subject->observers), 1);
            }
        });
    }

}
