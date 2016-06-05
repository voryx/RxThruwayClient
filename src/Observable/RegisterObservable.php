<?php

namespace Rx\Thruway\Observable;

use Rx\Observable;
use Rx\ObserverInterface;
use Thruway\Common\Utils;
use Thruway\WampErrorException;
use Rx\Observer\CallbackObserver;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Thruway\Message\{
    Message, RegisteredMessage, RegisterMessage, YieldMessage, ErrorMessage, InvocationMessage, UnregisterMessage
};


/**
 * Class RegisterObservable
 * @package Thruway\Rx
 */
class RegisterObservable extends Observable
{

    private $uri;
    private $options;
    private $callback;
    private $messages;
    private $sendMessage;

    function __construct(string $uri, callable $callback, Observable $messages, callable $sendMessage, array $options = [])
    {
        $this->uri         = $uri;
        $this->options     = $options;
        $this->callback    = $callback;
        $this->messages    = $messages;
        $this->sendMessage = $sendMessage;
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $requestId      = Utils::getUniqueId();
        $disposable     = new CompositeDisposable();
        $registerMsg    = new RegisterMessage($requestId, (object)$this->options, $this->uri);
        $registrationId = null;

        $registeredMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof RegisteredMessage && $msg->getRequestId() === $requestId;
            })
            ->take(1);

        $errorMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ErrorMessage && $msg->getErrorRequestId() === $requestId;
            })
            ->flatMap(function (ErrorMessage $msg) {
                return Observable::error(new WampErrorException($msg->getErrorURI(), $msg->getArguments()));
            })
            ->take(1);

        $callbackObserver = new CallbackObserver(null, [$observer, 'onError'], [$observer, 'onCompleted']);

        $sub =  call_user_func($this->sendMessage, $registerMsg)
            ->merge($registeredMsg)
            ->flatMap(function (RegisteredMessage $registeredMsg) use ($observer, &$registrationId) {
                $registrationId = $registeredMsg->getRegistrationId();
                $observer->onNext($registeredMsg);

                $invocationMsg = $this->messages->filter(function (Message $msg) use ($registeredMsg) {
                    return $msg instanceof InvocationMessage && $msg->getRegistrationId() === $registeredMsg->getRegistrationId();
                });

                return $invocationMsg->flatMap(function (InvocationMessage $msg) {
                    $yieldMsg = new YieldMessage($msg->getRequestId(), null, call_user_func_array($this->callback, [$msg->getArguments(), $msg->getArgumentsKw()]));

                    return call_user_func($this->sendMessage, $yieldMsg);
                });
            })
            ->merge($errorMsg)
            ->subscribe($callbackObserver, $scheduler);

        $disposable->add($sub);

        $disposable->add(new CallbackDisposable(function () use ($requestId, &$registrationId) {
            echo "registration disposed", PHP_EOL;
            if (!$registrationId) {
                return;
            }
            $unregisterMsg = new UnregisterMessage(Utils::getUniqueId(), $registrationId);
            call_user_func($this->sendMessage, $unregisterMsg)->subscribeCallback();

        }));

        return $disposable;
    }
}
