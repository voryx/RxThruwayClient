<?php

namespace Rx\Thruway\Tests\Functional\Observable;

use Rx\Observable;
use Rx\Thruway\Observable\RegisterObservable;
use Rx\Thruway\Tests\Functional\FunctionalTestCase;
use Thruway\Message\ErrorMessage;
use Thruway\Message\InvocationMessage;
use Thruway\Message\Message;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\WampErrorException;

class RegisterObservableTest extends FunctionalTestCase
{

    public function callable($first = 0, $second = 0)
    {
        return $first + $second;
    }

    public function callableObs($first = 0, $second = 0)
    {
        return Observable::just($first + $second);
    }

    public function callableManyObs($first = 0, $second = 0)
    {
        return Observable::fromArray([$first, $second]);
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

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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

        //Sent Message
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'], //RegisterMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_no_invocation_no_complete()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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

        //Sent Message
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'], //RegisterMessage
            [1000, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_one_invocation_no_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[70,12345,{},[0]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_reconnect()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, new WelcomeMessage(12345, new \stdClass())),
            onNext(270, $registeredMsg),
            onNext(280, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [281, '[70,12345,{},[0]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_many_invocations_no_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[70,12345,{},[0]]'], //YieldMessage
            [271, '[70,12345,{},[0]]'], //YieldMessage
            [281, '[70,12345,{},[0]]'], //YieldMessage
            [291, '[70,12345,{},[0]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_one_invocation_with_one_arg()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1]);

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[70,12345,{},[1]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_one_invocation_with_two_arg()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1, 2]);

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[70,12345,{},[3]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_one_invocation_with_two_arg_obs()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1, 2]);

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
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
            return new RegisterObservable('testing.uri', [$this, 'callableObs'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[70,12345,{},[3]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    function register_with_invocation_error()
    {
        $error         = new \Exception("testing");
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1, 2]);

        $sendMessage = function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
            return Observable::emptyObservable();
        };

        $callable = function () use ($error) {
            throw $error;
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage, $callable) {
            return new RegisterObservable('testing.uri', $callable, $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [260, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }
    
    /**
     * @test
     */
    function register_with_registration_error()
    {
        $errorMsg = new ErrorMessage(null, 54321, new \stdClass(), "registration.error.uri");

        $sendMessage = function (Message $msg) use ($errorMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $errorMsg->setErrorRequestId($requestId);
            }

            $this->recordWampMessage($msg);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $errorMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $sendMessage);
        });

        $this->assertMessages([
            onError(251, new WampErrorException('registration.error.uri'))
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
        ], $this->getWampMessages());
    }
}
