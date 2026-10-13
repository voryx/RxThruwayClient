<?php

namespace Rx\Thruway\Tests\Functional\Observable;

use Rx\Observable;
use Rx\Subject\Subject;
use Rx\Thruway\Message\RegistrationRevokedMessage;
use Rx\Thruway\Observable\RegisterObservable;
use Rx\Thruway\Observable\RegistrationRevokedException;
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
        return Observable::just($first + $second, $this->scheduler);
    }

    public function callableManyObs($first = 0, $second = 0)
    {
        return Observable::fromArray([$first, $second], $this->scheduler);
    }

    public function callableEmpty($first = 0, $second = 0)
    {
        return Observable::empty($this->scheduler);
    }

    /**
     * @test
     */
    public function register_message_never()
    {
        $messages = Observable::never();

        $webSocket = new Subject();
        $webSocket->subscribe(function (RegisterMessage $msg) {
            $this->assertEquals($msg->getUri(), 'testing.uri');
        });

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket);
        });

        $this->assertMessages([], $results->getMessages());
    }

    /**
     * @test
     */
    public function register_messages_empty()
    {
        $messages = $this->createHotObservable([
            onNext(150, 1),
            onCompleted(235)
        ]);

        $webSocket = new Subject();
        $webSocket->subscribe(function (RegisterMessage $msg) {
            $this->assertEquals($msg->getUri(), 'testing.uri');
        });

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket);
        });

        $this->assertMessages([
            onCompleted(235)
        ], $results->getMessages());

        $this->assertSubscriptions([
            subscribe(200, 235)
        ], $messages->getSubscriptions());
    }

    /**
     * @test
     */
    public function register_with_no_invocation()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }

            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket);
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
    public function register_with_no_invocation_no_complete()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket);
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
    public function register_with_one_invocation_no_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
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
    public function register_with_one_invocation_empty_result()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callableEmpty'], $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [262, '[70,12345,{},[null]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_reconnect()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, new WelcomeMessage(12345, new \stdClass())),
            onNext(270, $registeredMsg),
            onNext(280, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
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
    public function register_with_many_invocations_no_args()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

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

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
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
    public function register_with_one_invocation_with_one_arg()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1]);

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
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
    public function register_with_one_invocation_with_two_arg()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1, 2]);

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
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
    public function register_with_one_invocation_with_two_arg_obs()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass(), [1, 2]);

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callableObs'], $messages, $webSocket, [], false, null, $this->scheduler);
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

        $this->assertSubscriptions([
            subscribe(200, 350)
        ], $messages->getSubscriptions());
    }

    /**
     * @test
     */
    public function register_callback_throws()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', function () {
                throw new \Exception('error');
            }, $messages, $webSocket, [], false, null, $this->scheduler);
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
    public function register_callback_throws_multiple()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

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

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', function () {
                throw new \Exception('error');
            }, $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [260, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [270, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [280, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [290, '[8,68,12345,{},"thruway.error.invocation_exception"]'],  //ErrorMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_callback_errors()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', function () {
                return Observable::error(new \Exception('error'), $this->scheduler);
            }, $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_callback_errors_multiple()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

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

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', function () {
                return Observable::error(new \Exception('error'), $this->scheduler);
            }, $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [271, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [281, '[8,68,12345,{},"thruway.error.invocation_exception"]'], //ErrorMessage
            [291, '[8,68,12345,{},"thruway.error.invocation_exception"]'],  //ErrorMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_with_registration_error()
    {
        $errorMsg = new ErrorMessage(null, 54321, new \stdClass(), "registration.error.uri");

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($errorMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $errorMsg->setErrorRequestId($requestId);
            }

            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $errorMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null,
                $this->scheduler);
        });

        $this->assertMessages([
            onError(251, new WampErrorException('registration.error.uri'))
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
        ], $this->getWampMessages());

        $this->assertSubscriptions([
            subscribe(200, 251)
        ], $messages->getSubscriptions());
    }

    /**
     * @test
     */
    public function register_callback_errors_with_wampErrorException_using_observable()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', function () {
                return Observable::error(new WampErrorException('my.custom.error', [1], ['someKw' => 'someArg']), $this->scheduler);
            }, $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [261, '[8,68,12345,{},"my.custom.error",[1],{"someKw":"someArg"}]'], //ErrorMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_callback_errors_with_wampErrorException_using_throw()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $requestId = $msg->getRequestId();
                $registeredMsg->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(260, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', function () {
                throw new WampErrorException('my.custom.error', [1], ['someKw' => 'someArg']);
            }, $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        //Sent Wamp Messages
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'],//RegisterMessage
            [260, '[8,68,12345,{},"my.custom.error",[1],{"someKw":"someArg"}]'], //ErrorMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_revoked_by_router()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $revokedMsg    = new RegistrationRevokedMessage(0, ['registration' => 54321, 'reason' => 'moving endpoint to other callee']);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $registeredMsg->setRequestId($msg->getRequestId());
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(300, $revokedMsg),
            onNext(310, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onError(301, new RegistrationRevokedException(54321, 'moving endpoint to other callee'))
        ], $results->getMessages());

        $results->getMessages()[1]->getValue()->accept(null, function (RegistrationRevokedException $e) {
            $this->assertSame(54321, $e->getRegistrationId());
            $this->assertSame('moving endpoint to other callee', $e->getReason());
            $this->assertSame('moving endpoint to other callee', $e->getMessage());
            $this->assertSame(RegistrationRevokedException::ERROR_URI, $e->getErrorUri());
            $this->assertEquals((object)['registration' => 54321, 'reason' => 'moving endpoint to other callee'], $e->getDetails());
        });

        //The registration was already removed by the router, so no UnregisterMessage is sent and the later invocation is ignored
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'] //RegisterMessage
        ], $this->getWampMessages());

        $this->assertSubscriptions([
            subscribe(200, 301)
        ], $messages->getSubscriptions());
    }

    /**
     * @test
     */
    public function register_ignores_revocation_of_other_registration()
    {
        $registeredMsg = new RegisteredMessage(null, 54321);
        $revokedMsg    = new RegistrationRevokedMessage(0, ['registration' => 99999]);
        $invocationMsg = new InvocationMessage(44444, 54321, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsg) {
            if ($msg instanceof RegisterMessage) {
                $registeredMsg->setRequestId($msg->getRequestId());
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsg),
            onNext(300, $revokedMsg),
            onNext(310, $invocationMsg),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler);
        });

        $this->assertMessages([
            onNext(250, $registeredMsg),
            onCompleted(350)
        ], $results->getMessages());

        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'], //RegisterMessage
            [311, '[70,12345,{},[0]]'], //YieldMessage
            [350, '[66,12345,54321]'] //UnregisterMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function register_revoked_then_retry_reregisters()
    {
        $registeredMsgs = [new RegisteredMessage(null, 54321), new RegisteredMessage(null, 54322)];
        $revokedMsg     = new RegistrationRevokedMessage(0, ['registration' => 54321, 'reason' => 'moving endpoint to other callee']);

        $registerCount = 0;
        $webSocket     = new Subject();
        $webSocket->subscribe(function (Message $msg) use ($registeredMsgs, &$registerCount) {
            if ($msg instanceof RegisterMessage) {
                $registeredMsgs[$registerCount++]->setRequestId($msg->getRequestId());
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $registeredMsgs[0]),
            onNext(300, $revokedMsg),
            onNext(320, $registeredMsgs[1]),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return (new RegisterObservable('testing.uri', [$this, 'callable'], $messages, $webSocket, [], false, null, $this->scheduler))
                ->retryWhen(function (Observable $errors) {
                    return $errors->filter(function (\Throwable $e) {
                        return $e instanceof RegistrationRevokedException;
                    });
                });
        });

        $this->assertMessages([
            onNext(250, $registeredMsgs[0]),
            onNext(320, $registeredMsgs[1]),
            onCompleted(350)
        ], $results->getMessages());

        //Re-registering sends a fresh RegisterMessage, and only the live registration is unregistered at the end
        $this->assertWampMessages([
            [200, '[64,12345,{},"testing.uri"]'], //RegisterMessage
            [301, '[64,12345,{},"testing.uri"]'], //RegisterMessage (retry)
            [350, '[66,12345,54322]'] //UnregisterMessage
        ], $this->getWampMessages());

        $this->assertSubscriptions([
            subscribe(200, 301),
            subscribe(301, 350)
        ], $messages->getSubscriptions());
    }
}
