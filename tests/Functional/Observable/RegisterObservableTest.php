<?php

namespace Rx\React\Tests\Functional\Observable;

use Rx\Observable;
use Rx\Thruway\Observable\RegisterObservable;
use Rx\Functional\FunctionalTestCase;
use Thruway\Message\InvocationMessage;
use Thruway\Message\Message;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\UnregisteredMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\Message\YieldMessage;

class RegisterObservableTest extends FunctionalTestCase
{

    public function callable($value = null)
    {
        return 'test';
    }

    public function callableObs($params)
    {
        return Observable::interval(100)->take(5);
    }

    /**
     * @test
     */
    function register_message_never()
    {
        $messages    = Observable::never();
        $sendMessage = function (RegisterMessage $msg) {
            $this->assertEquals($msg->getUri(), 'testing.uri');
            return Observable::emptyObservable();
        };

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([], $results->getMessages());
    }

    /**
     * @test
     */
    function register_messages_empty()
    {
        $messages = $this->createHotObservable([
            onNext(150, 1),
            onCompleted(235)
        ]);

        $sendMessage = function (RegisterMessage $msg) {
            $this->assertEquals($msg->getUri(), 'testing.uri');
            return Observable::emptyObservable();
        };

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onCompleted(235)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function register_with_no_invocation()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $messagesSent  = 0;

        $sendMessage = function (Message $msg) use ($registeredMsg, &$messagesSent) {
            $messagesSent++;
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            if ($msg instanceof UnregisteredMessage) {
                //unregister when completed
                $this->assertEquals($this->scheduler->getClock(), 350);
            }

            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        $this->assertEquals($messagesSent, 2);
    }

    /**
     * @test
     */
    function register_with_no_invocation_no_complete()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $messagesSent  = 0;

        $sendMessage = function (Message $msg) use ($registeredMsg, &$messagesSent) {
            $messagesSent++;
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            if ($msg instanceof UnregisteredMessage) {
                //unregister when disposed
                $this->assertEquals($this->scheduler->getClock(), 1000);
            }

            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg)
        ], $results->getMessages());

        $this->assertEquals($messagesSent, 2);
    }

    /**
     * @test
     */
    function register_with_one_invocation_no_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());
        $messagesSent  = 0;

        $sendMessage = function (Message $msg) use ($registeredMsg, &$messagesSent) {
            $messagesSent++;
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            if ($msg instanceof UnregisteredMessage) {
                //unregister when completed
                $this->assertEquals($this->scheduler->getClock(), 350);
            }

            if ($msg instanceof YieldMessage) {
                $this->assertEquals($msg->getArguments()[0], "test");
            }

            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        $this->assertEquals($messagesSent, 3);
    }

    /**
     * @test
     */
    function register_with_many_invocations_no_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());
        $messagesSent  = 0;

        $sendMessage = function (Message $msg) use ($registeredMsg, &$messagesSent) {
            $messagesSent++;
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            if ($msg instanceof UnregisteredMessage) {
                //unregister when completed
                $this->assertEquals($this->scheduler->getClock(), 350);
            }

            if ($msg instanceof YieldMessage) {
                $this->assertEquals($msg->getArguments()[0], "test");
            }

            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onNext(270, $invocationMsg),
            onNext(280, $invocationMsg),
            onNext(290, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        $this->assertEquals($messagesSent, 6);
    }

    /**
     * @test
     */
    function register_with_one_invocation_with_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());
        $messagesSent  = 0;

        $sendMessage = function (Message $msg) use ($registeredMsg, &$messagesSent) {
            $messagesSent++;
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            if ($msg instanceof UnregisteredMessage) {
                //unregister when completed
                $this->assertEquals($this->scheduler->getClock(), 350);
            }

            if ($msg instanceof YieldMessage) {
                $this->assertEquals($msg->getArguments()[0], "test");
            }

            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        $this->assertEquals($messagesSent, 3);
    }
}
