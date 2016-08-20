<?php

namespace Rx\Thruway\Observable;

use Rx\Observable;
use Rx\ObserverInterface;
use Thruway\Common\Utils;
use Thruway\WampErrorException;
use Rx\Observer\CallbackObserver;
use Rx\Disposable\CallbackDisposable;
use Rx\Thruway\Observer\YieldObserver;
use Rx\Disposable\CompositeDisposable;
use Thruway\Message\{
    Message, RegisteredMessage, RegisterMessage, UnregisteredMessage, ErrorMessage, InvocationMessage, UnregisterMessage
};

/**
 * Class RegisterObservable
 * @package Thruway\Rx
 */
class RegisterObservable extends Observable
{

    private $uri;
    private $options;
    private $messages;
    private $sendMessage;
    private $callback;
    private $extended;

    function __construct(string $uri, callable $callback, Observable $messages, callable $sendMessage, array $options = [], bool $extended = false)
    {
        $this->uri         = $uri;
        $this->options     = $options;
        $this->callback    = $callback;
        $this->messages    = $messages->share();
        $this->sendMessage = $sendMessage;
        $this->extended    = $extended;
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
            call_user_func($this->sendMessage, $unregisterMsg)->subscribeCallback();
        };

        $registerSubscription = call_user_func($this->sendMessage, $registerMsg)
            ->merge($registeredMsg)
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
                    throw new WampInvocationException($msg);
                }

                $resultObs = $result instanceof Observable ? $result : Observable::just($result);
                return $resultObs->map(function ($value) use ($msg) {
                    return [$value, $msg];
                });
            })
            ->takeUntil($unregisteredMsg)
            ->subscribe(new YieldObserver($this->sendMessage), $scheduler);

        $disposable->add($invocationSubscription);
        $disposable->add($registerSubscription);
        $disposable->add(new CallbackDisposable($unregister));

        return $disposable;
    }
}
