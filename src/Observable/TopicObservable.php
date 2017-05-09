<?php

namespace Rx\Thruway\Observable;

use Rx\DisposableInterface;
use Rx\Observable;
use Rx\Subject\Subject;
use Thruway\Common\Utils;
use Rx\ObserverInterface;
use Thruway\WampErrorException;
use Rx\Disposable\CallbackDisposable;
use Rx\Disposable\CompositeDisposable;
use Thruway\Message\{
    Message, EventMessage, SubscribedMessage, ErrorMessage, SubscribeMessage, UnsubscribeMessage
};

final class TopicObservable extends Observable
{
    private $uri, $options, $messages, $webSocket;

    public function __construct(string $uri, array $options, Observable $messages, Subject $webSocket)
    {
        $this->uri       = $uri;
        $this->options   = (object)$options;
        $this->messages  = $messages;
        $this->webSocket = $webSocket;
    }

    public function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        $requestId      = Utils::getUniqueId();
        $subscriptionId = null;
        $subscribeMsg   = new SubscribeMessage($requestId, $this->options, $this->uri);

        $subscribedMsg = $this->messages->filter(function (Message $msg) use ($requestId) {
            return $msg instanceof SubscribedMessage && $msg->getRequestId() === $requestId;
        })->take(1);

        $errorMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ErrorMessage && $msg->getErrorRequestId() === $requestId;
            })
            ->flatMap(function (ErrorMessage $msg) {
                return Observable::error(new WampErrorException($msg->getErrorURI(), $msg->getArguments()));
            })
            ->take(1);

        $this->webSocket->onNext($subscribeMsg);

        $sub = $subscribedMsg
            ->flatMap(function (SubscribedMessage $subscribedMsg) use (&$subscriptionId) {

                $subscriptionId = $subscribedMsg->getSubscriptionId();

                return $this->messages->filter(function (Message $msg) use ($subscriptionId) {
                    return $msg instanceof EventMessage && $msg->getSubscriptionId() === $subscriptionId;
                });
            })
            ->merge($errorMsg)
            ->subscribe($observer);

        $disposable = new CompositeDisposable();

        $disposable->add($sub);

        $disposable->add(new CallbackDisposable(function () use (&$subscriptionId) {
            if (!$subscriptionId) {
                return;
            }
            $unsubscribeMsg = new UnsubscribeMessage(Utils::getUniqueId(), $subscriptionId);
            $this->webSocket->onNext($unsubscribeMsg);
        }));

        return $disposable;
    }
}
