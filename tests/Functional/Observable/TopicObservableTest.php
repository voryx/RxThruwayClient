<?php

namespace Rx\Thruway\Tests\Functional\Observable;

use Rx\Observable;
use Rx\Subject\Subject;
use Rx\Thruway\Observable\TopicObservable;
use Rx\Thruway\Tests\Functional\FunctionalTestCase;
use Thruway\Message\EventMessage;
use Thruway\Message\SubscribedMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\WelcomeMessage;

class TopicObservableTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function topic_message_never()
    {
        $messages = Observable::never();

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) {
            $this->recordWampMessage($msg);
        });

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new TopicObservable('testing.uri', [], $messages, $webSocket);
        });

        $this->assertMessages([], $results->getMessages());

        $this->assertWampMessages([
            [200, '[32,12345,{},"testing.uri"]'], //SubscribeMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function topic_messages_empty()
    {
        $messages = $this->createHotObservable([
            onNext(150, 1),
            onCompleted(235)
        ]);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) {
            $this->recordWampMessage($msg);
        });

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new TopicObservable('testing.uri', [], $messages, $webSocket);
        });

        $this->assertMessages([
            onCompleted(235)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function topic_one_no_args()
    {
        $eventMessage      = new EventMessage(54321, null, new \stdClass());
        $subscribedMessage = new SubscribedMessage(null, 54321);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($eventMessage, $subscribedMessage) {
            if ($msg instanceof SubscribeMessage) {
                $subId = $msg->getRequestId();
                $subscribedMessage->setRequestId($subId);
            }
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $subscribedMessage),
            onNext(300, $eventMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new TopicObservable('testing.uri', [], $messages, $webSocket);
        });

        $this->assertMessages([
            onNext(300, $eventMessage),
            onCompleted(350)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function topic_one_with_args()
    {

        $eventMessage      = new EventMessage(54321, null, new \stdClass(), [123]);
        $subscribedMessage = new SubscribedMessage(null, 54321);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($eventMessage, $subscribedMessage) {
            if ($msg instanceof SubscribeMessage) {
                $subId = $msg->getRequestId();
                $subscribedMessage->setRequestId($subId);
            }
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $subscribedMessage),
            onNext(300, $eventMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new TopicObservable('testing.uri', [], $messages, $webSocket);
        });

        $this->assertMessages([
            onNext(300, $eventMessage),
            onCompleted(350)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function topic_one_with_argskw()
    {
        $eventMessage      = new EventMessage(54321, null, new \stdClass(), [123], (object)[1 => 1, 2 => 2]);
        $subscribedMessage = new SubscribedMessage(null, 54321);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($eventMessage, $subscribedMessage) {
            if ($msg instanceof SubscribeMessage) {
                $subId = $msg->getRequestId();
                $subscribedMessage->setRequestId($subId);
            }
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $subscribedMessage),
            onNext(300, $eventMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new TopicObservable('testing.uri', [], $messages, $webSocket);
        });

        $this->assertMessages([
            onNext(300, $eventMessage),
            onCompleted(350)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function topic_multiple_events()
    {
        $e1 = new EventMessage(54321, null, new \stdClass(), [123], (object)[1 => 1, 2 => 2]);
        $e2 = new EventMessage(54321, null, new \stdClass(), [123], (object)[1 => 1, 2 => 2]);
        $e3 = new EventMessage(54321, null, new \stdClass(), [123], (object)[1 => 1, 2 => 2]);
        $e4 = new EventMessage(99999, null, new \stdClass(), [123], (object)[1 => 1, 2 => 2]);
        $e5 = new EventMessage(54321, null, new \stdClass(), [123], (object)[1 => 1, 2 => 2]);

        $subscribedMessage = new SubscribedMessage(null, 54321);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($subscribedMessage) {
            if ($msg instanceof SubscribeMessage) {
                $subId = $msg->getRequestId();
                $subscribedMessage->setRequestId($subId);
            }
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $subscribedMessage),
            onNext(300, $e1),
            onNext(310, $e2),
            onNext(320, $e3),
            onNext(330, $e4),
            onNext(340, $e5),
            onCompleted(450)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new TopicObservable('testing.uri', [], $messages, $webSocket);
        });

        $this->assertMessages([
            onNext(300, $e1),
            onNext(310, $e2),
            onNext(320, $e3),
            onNext(340, $e5),
            onCompleted(450)
        ], $results->getMessages());


    }

    // @todo error tests
    // @todo dispose tests
    // @todo remote unsbuscribe tests
}
