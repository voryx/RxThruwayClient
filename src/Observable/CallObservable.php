<?php

namespace Rx\Thruway\Observable;

use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Rx\Disposable\EmptyDisposable;
use Rx\DisposableInterface;
use Rx\Scheduler;
use Rx\SchedulerInterface;
use Thruway\WampErrorException;
use Rx\ObserverInterface;
use Thruway\Common\Utils;
use Rx\Subject\Subject;
use Rx\Observable;
use Thruway\Message\{
    CancelMessage, Message, CallMessage, ResultMessage, ErrorMessage
};

final class CallObservable extends Observable
{
    private $uri, $args, $argskw, $options, $messages, $webSocket, $timeout, $completed, $scheduler;

    public function __construct(
        string $uri,
        Observable $messages,
        Subject $webSocket,
        array $args = null,
        array $argskw = null,
        array $options = null,
        int $timeout = 300000,
        SchedulerInterface $scheduler = null
    )
    {
        $this->uri       = $uri;
        $this->args      = $args;
        $this->argskw    = $argskw;
        $this->options   = (object)$options;
        $this->messages  = $messages;
        $this->webSocket = $webSocket;
        $this->timeout   = $timeout;
        $this->completed = false;
        $this->scheduler = $scheduler ?: Scheduler::getDefault();
    }

    public function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        $requestId = Utils::getUniqueId();
        $callMsg   = new CallMessage($requestId, $this->options, $this->uri, $this->args, $this->argskw);

        $msg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ResultMessage && $msg->getRequestId() === $requestId;
            })
            ->flatMap(function (ResultMessage $msg) {

                static $i = -1;
                $i++;

                $details = $msg->getDetails();
                if ($i === 0 && (bool)($details->progress ?? false) === false) {
                    $details = $msg->getDetails();

                    $details->progress = true;

                    return Observable::fromArray([
                        new ResultMessage($msg->getRequestId(), $details, $msg->getArguments(), $msg->getArgumentsKw()),
                        new ResultMessage($msg->getRequestId(), (object)['progress' => false])
                    ], $this->scheduler);
                }
                return Observable::of($msg);
            });

        //Take until we get a result without progress
        $resultMsg = $msg
            ->takeWhile(function (ResultMessage $msg) {
                $details = $msg->getDetails();
                return (bool)($details->progress ?? false);
            })
            ->finally(function () {
                $this->completed = true;
            })
            ->share();

        $error = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ErrorMessage && $msg->getErrorRequestId() === $requestId;
            })
            ->flatMap(function (ErrorMessage $msg) {
                return Observable::error(new WampErrorException($msg->getErrorURI() . ':' . $this->uri, $msg->getArguments()), $this->scheduler);
            })
            ->takeUntil($resultMsg)
            ->take(1);

        try {
            $this->webSocket->onNext($callMsg);
        } catch (\Exception $e) {
            $observer->onError($e);
            return new EmptyDisposable();
        }

        $result = $error
            ->merge($resultMsg)
            ->map(function (ResultMessage $msg) {
                $details = $msg->getDetails();
                unset($details->progress);
                return new ResultMessage($msg->getRequestId(), $details, $msg->getArguments(), $msg->getArgumentsKw());
            });

        return new CompositeDisposable([
            new CallbackDisposable(function () use ($requestId) {
                if (!$this->completed) {
                    $cancelMsg = new CancelMessage($requestId, (object)[]);
                    $this->webSocket->onNext($cancelMsg);
                }
            }),

            $result->subscribe($observer)
        ]);
    }
}
