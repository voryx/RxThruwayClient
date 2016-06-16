<?php

namespace Rx\Thruway\Observer;

use Exception;
use Rx\Disposable\CompositeDisposable;
use Rx\Observer\AbstractObserver;
use Rx\Thruway\Observable\WampInvocationException;
use Thruway\Message\Message;
use Thruway\Message\YieldMessage;
use Thruway\Message\InvocationMessage;

class YieldObserver extends AbstractObserver
{

    private $sendMessage;
    private $progress;
    private $disposable;

    public function __construct(callable $sendMessage, bool $progress = false)
    {
        $this->sendMessage = $sendMessage;
        $this->progress    = $progress;
        $this->disposable  = new CompositeDisposable();
    }

    protected function completed()
    {
        if ($this->progress) {
            // TODO: send last message
        }

        $this->disposable->dispose();
    }

    protected function next($args)
    {
        /* @var $invocationMsg InvocationMessage */
        list($value, $invocationMsg) = $args;

        $this->sendMessage(new YieldMessage($invocationMsg->getRequestId(), null, [$value]));
    }

    protected function error(Exception $error)
    {
        if ($error instanceof WampInvocationException) {
            $this->sendMessage($error->getErrorMessage());
        }
    }

    protected function sendMessage(Message $msg)
    {
        $sub = call_user_func($this->sendMessage, $msg)->subscribeCallback(
        //@todo add logger observer
        );

//        $this->disposable->add($sub);
    }
}
