<?php

namespace Rx\Thruway\Tests\Functional\Observable;

use Rx\Observable;
use Thruway\Message\CallMessage;
use Rx\Functional\FunctionalTestCase;
use Rx\Thruway\Observable\CallObservable;
use Thruway\Message\ErrorMessage;
use Thruway\Message\ResultMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\WampErrorException;

class CallObservableTest extends FunctionalTestCase
{

    public function sendMessage(CallMessage $msg)
    {
        $this->assertEquals($msg->getUri(), 'testing.uri');
        return Observable::emptyObservable();
    }

    /**
     * @test
     */
    function call_message_never()
    {
        $messages = Observable::never();

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return new CallObservable('testing.uri', $messages, [$this, 'sendMessage']);
        });

        $this->assertMessages([], $results->getMessages());
    }

    /**
     * @test
     */
    function call_messages_empty()
    {
        $messages = $this->createHotObservable([
            onNext(150, 1),
            onCompleted(235)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return new CallObservable('testing.uri', $messages, [$this, 'sendMessage']);
        });

        $this->assertMessages([
            onCompleted(235)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_no_args()
    {

        $resultMessage = new ResultMessage(null, new \stdClass());

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, [[], new \stdClass(), new \stdClass()]),
            onCompleted(250)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_with_args()
    {

        $args = ["testing"];

        $resultMessage = new ResultMessage(null, new \stdClass(), $args);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, [$args, new \stdClass(), new \stdClass()]),
            onCompleted(250)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_with_argskw()
    {
        $args   = ["testing"];
        $argskw = (object)["foo" => "bar"];

        $resultMessage = new ResultMessage(null, new \stdClass(), $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, [$args, $argskw, new \stdClass()]),
            onCompleted(250)
        ], $results->getMessages());

    }

    /**
     * @test
     */
    function call_one_with_details()
    {

        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, [$args, $argskw, $details]),
            onCompleted(250)
        ], $results->getMessages());

    }

    /**
     * @test
     */
    function call_one_reconnect()
    {

        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onNext(260, new WelcomeMessage(12345, new \stdClass())),
            onNext(270, $resultMessage),
            onCompleted(350)

        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(250, [$args, $argskw, $details]),
            onCompleted(250)
        ], $results->getMessages());

    }

    /**
     * @test
     */
    function call_one_throw_error_before()
    {

        $error   = new \Exception("testing");
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onError(210, $error),
            onNext(220, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onError(210, $error)
        ], $results->getMessages());
    }


    /**
     * @test
     */
    function call_one_throw_error_after_welcome()
    {

        $error   = new \Exception("testing");
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onError(220, $error),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onError(220, $error)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_throw_error_after_result()
    {

        $error   = new \Exception("testing");
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(230, $resultMessage),
            onError(240, $error),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onNext(230, [$args, $argskw, $details]),
            onCompleted(230)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_throw_sendMessage()
    {
        $error = new \Exception("testing");

        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($error) {
            throw $error;
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onError(200, $error)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_error_message()
    {

        $errorMessage = new ErrorMessage(12345, null, new \stdClass(), 'some.server.error');

        $sendMessage = function (CallMessage $msg) use ($errorMessage) {
            $requestId = $msg->getRequestId();
            $errorMessage->setErrorRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $errorMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        });

        $this->assertMessages([
            onError(251, new WampErrorException('some.server.error'))
        ], $results->getMessages());
    }

    /**
     * @test
     */
    function call_one_dispose_before()
    {
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithDispose(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        }, 220);

        $this->assertMessages([], $results->getMessages());

    }

    /**
     * @test
     */
    function call_one_dispose_after()
    {
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithDispose(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage);
        }, 260);

        $this->assertMessages([
            onNext(250, [$args, $argskw, $details]),
            onCompleted(250)
        ], $results->getMessages());
    }


    /**
     * @test
     */
    function call_one_timeout()
    {

        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $sendMessage = function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            return Observable::emptyObservable();
        };

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $sendMessage) {
            return new CallObservable('testing.uri', $messages, $sendMessage, null, null, null, 10);
        });

        $this->assertMessages([
            onError(210, new \Exception())
        ], $results->getMessages());

    }
}
