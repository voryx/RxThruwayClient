<?php

namespace Rx\Thruway\Observable;

use Rx\Observable;
use Rx\ObserverInterface;
use Rx\Subject\Subject;
use Thruway\Common\Utils;
use Thruway\WampErrorException;
use Rx\Observer\CallbackObserver;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Thruway\Message\{
    InterruptMessage, Message, RegisteredMessage, RegisterMessage, UnregisteredMessage, ErrorMessage, InvocationMessage, UnregisterMessage, YieldMessage
};

final class RegisterObservable extends Observable
{
    private $uri, $options, $messages, $ws, $callback, $extended, $logSubject, $invocationErrors;

    function __construct(string $uri, callable $callback, Observable $messages, Subject $ws, array $options = [], bool $extended = false, Subject $logSubject = null)
    {
        $this->uri              = $uri;
        $this->options          = $options;
        $this->callback         = $callback;
        $this->messages         = $messages->share();
        $this->ws               = $ws;
        $this->extended         = $extended;
        $this->logSubject       = $logSubject ?: new Subject();
        $this->invocationErrors = new Subject();
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $requestId      = Utils::getUniqueId();
        $disposable     = new CompositeDisposable();
        $registerMsg    = new RegisterMessage($requestId, (object)$this->options, $this->uri);
        $registrationId = null;
        $completed      = false;

        $unregisteredMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof UnregisteredMessage && $msg->getRequestId() === $requestId;
            })
            ->take(1);

        $registeredMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof RegisteredMessage && $msg->getRequestId() === $requestId;
            })
            ->take(1)
            ->share();

        $invocationMsg = $registeredMsg->flatMap(function (RegisteredMessage $registeredMsg) use (&$registrationId) {
            $registrationId = $registeredMsg->getRegistrationId();

            return $this->messages->filter(function (Message $msg) use ($registeredMsg) {
                return $msg instanceof InvocationMessage && $msg->getRegistrationId() === $registeredMsg->getRegistrationId();
            });
        });

        //Transform WAMP error messages into an error observable
        $error = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ErrorMessage && $msg->getErrorRequestId() === $requestId;
            })
            ->flatMap(function (ErrorMessage $msg) {
                return Observable::error(new WampErrorException($msg->getErrorURI(), $msg->getArguments()));
            })
            ->takeUntil($registeredMsg)
            ->take(1);

        $unregister = function () use ($requestId, &$registrationId, &$completed) {
            if (!$registrationId || $completed) {
                return;
            }
            $unregisterMsg = new UnregisterMessage(Utils::getUniqueId(), $registrationId);
            $this->ws->onNext($unregisterMsg);
        };

        $this->ws->onNext($registerMsg);

        $registerSubscription = $registeredMsg
            ->merge($unregisteredMsg)
            ->merge($error)
            ->subscribe(new CallbackObserver(
                [$observer, 'onNext'],
                [$observer, 'onError'],
                function () use (&$completed, $observer, $unregister) {
                    $unregister();
                    $completed = true;
                    $observer->onCompleted();
                }
            ), $scheduler);

        $invocationSubscription = $invocationMsg
            ->flatMap(function (InvocationMessage $msg) {

                try {
                    if ($this->extended) {
                        $result = call_user_func_array($this->callback, [$msg->getArguments(), $msg->getArgumentsKw(), $msg->getDetails(), $msg]);
                    } else {
                        $result = call_user_func_array($this->callback, $msg->getArguments());
                    }
                } catch (\Exception $e) {
                    $this->invocationErrors->onNext(new WampInvocationException($msg));
                    return Observable::emptyObservable();
                }

                $resultObs = $result instanceof Observable ? $result : Observable::just($result);

                if (($this->options['progress'] ?? false) === false) {
                    $returnObs = $resultObs
                        ->take(1)
                        ->map(function ($value) use ($msg) {
                            return [$value, $msg, $this->options];
                        });
                } else {

                    $returnObs = $resultObs
                        ->map(function ($value) use ($msg) {
                            return [$value, $msg, $this->options];
                        })
                        ->concat(Observable::just([null, $msg, ["progress" => false]]));
                }

                $interruptMsg = $this->messages
                    ->filter(function (Message $m) use ($msg) {
                        return $m instanceof InterruptMessage && $m->getRequestId() === $msg->getRequestId();
                    })
                    ->take(1);

                return $returnObs
                    ->takeUntil($interruptMsg)
                    ->catchError(function (\Exception $ex) use ($msg) {
                        $this->invocationErrors->onNext(new WampInvocationException($msg));
                        return Observable::emptyObservable();
                    });

            })
            ->map(function ($args) {
                /* @var $invocationMsg InvocationMessage */
                list($value, $invocationMsg, $options) = $args;

                return new YieldMessage($invocationMsg->getRequestId(), $options, [$value]);
            })
            ->subscribe($this->ws, $scheduler);

        $invocationErrors = $this->invocationErrors
            ->map(function (WampInvocationException $error) {
                return $error->getErrorMessage();
            })
            ->subscribe($this->ws, $scheduler);

        $disposable->add($invocationErrors);
        $disposable->add($invocationSubscription);
        $disposable->add($registerSubscription);
        $disposable->add(new CallbackDisposable($unregister));

        return $disposable;
    }
}
