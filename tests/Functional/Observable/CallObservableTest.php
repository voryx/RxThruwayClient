<?php

namespace Rx\Thruway\Tests\Functional\Observable;

use Rx\Observable;
use Rx\Subject\Subject;
use Thruway\Message\CallMessage;
use Rx\Thruway\Tests\Functional\FunctionalTestCase;
use Rx\Thruway\Observable\CallObservable;
use Thruway\Message\ErrorMessage;
use Thruway\Message\ResultMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\WampErrorException;

class CallObservableTest extends FunctionalTestCase
{

    /**
     * @test
     */
    public function call_message_never()
    {
        $messages = Observable::never();

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) {
            $this->recordWampMessage($msg);
        });

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([], $results->getMessages());

        $this->assertWampMessages([
            [200, '[48,12345,{},"testing.uri"]'], //CallMessage
            [1000, '[49,12345,{}]'] //CancelMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function call_messages_empty()
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
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onCompleted(235)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function call_one_no_args()
    {
        $resultMessage = new ResultMessage(null, new \stdClass());

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onNext(251, $resultMessage),
            onCompleted(252)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function call_one_with_args()
    {

        $args = ["testing"];

        $resultMessage = new ResultMessage(null, new \stdClass(), $args);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onNext(251, $resultMessage),
            onCompleted(252)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function call_one_with_argskw()
    {
        $args   = ["testing"];
        $argskw = (object)["foo" => "bar"];

        $resultMessage = new ResultMessage(null, new \stdClass(), $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onNext(251, $resultMessage),
            onCompleted(252)
        ], $results->getMessages());

    }

    /**
     * @test
     */
    public function call_one_with_details()
    {
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onNext(251, $resultMessage),
            onCompleted(252)
        ], $results->getMessages());

    }

    /**
     * @test
     */
    public function call_one_reconnect()
    {
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onNext(260, new WelcomeMessage(12345, new \stdClass())),
            onNext(270, $resultMessage),
            onCompleted(350)

        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onNext(251, $resultMessage),
            onCompleted(252)
        ], $results->getMessages());

    }

    /**
     * @test
     */
    public function call_one_throw_error_before()
    {
        $error   = new \Exception("testing");
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($resultMessage) {
            $this->recordWampMessage($msg);
            if ($msg instanceof CallMessage) {
                $requestId = $msg->getRequestId();
                $resultMessage->setRequestId($requestId);
            }
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onError(210, $error),
            onNext(220, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onError(210, $error)
        ], $results->getMessages());

        $this->assertWampMessages([
            [200, '[48,12345,{},"testing.uri"]'] //CallMessage
        ], $this->getWampMessages());
    }


    /**
     * @test
     */
    public function call_one_throw_error_after_welcome()
    {
        $error   = new \Exception("testing");
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($resultMessage) {
            $this->recordWampMessage($msg);
            if ($msg instanceof CallMessage) {
                $requestId = $msg->getRequestId();
                $resultMessage->setRequestId($requestId);
            }
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onError(220, $error),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onError(220, $error)
        ], $results->getMessages());

        $this->assertWampMessages([
            [200, '[48,12345,{},"testing.uri"]'] //CallMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function call_one_throw_error_after_result()
    {
        $error   = new \Exception("testing");
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(230, $resultMessage),
            onError(240, $error),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onNext(231, $resultMessage),
            onCompleted(232)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function call_one_throw_sendMessage()
    {
        $error = new \Exception("testing");

        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($error) {
            throw $error;
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onError(200, $error)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function call_one_error_message()
    {
        $errorMessage = new ErrorMessage(12345, null, new \stdClass(), 'some.server.error');

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($errorMessage) {
            if ($msg instanceof CallMessage) {
                $requestId = $msg->getRequestId();
                $errorMessage->setErrorRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $errorMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        });

        $this->assertMessages([
            onError(251, new WampErrorException('some.server.error'))
        ], $results->getMessages());

        $this->assertWampMessages([
            [200, '[48,12345,{},"testing.uri"]'] //CallMessage
        ], $this->getWampMessages());
    }

    /**
     * @test
     */
    public function call_one_dispose_before()
    {
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function ($msg) use ($resultMessage) {
            if ($msg instanceof CallMessage) {
                $requestId = $msg->getRequestId();
                $resultMessage->setRequestId($requestId);
            }
            $this->recordWampMessage($msg);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithDispose(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        }, 220);

        $this->assertMessages([], $results->getMessages());

        $this->assertWampMessages([
            [200, '[48,12345,{},"testing.uri"]'], //CallMessage
            [220, '[49,12345,{}]'] //CancelMessage
        ], $this->getWampMessages());

    }

    /**
     * @test
     */
    public function call_one_dispose_after()
    {
        $args    = ["testing"];
        $argskw  = (object)["foo" => "bar"];
        $details = (object)["one" => "two"];

        $resultMessage = new ResultMessage(null, $details, $args, $argskw);

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(210, new WelcomeMessage(12345, new \stdClass())),
            onNext(250, $resultMessage),
            onCompleted(350)
        ]);

        $results = $this->scheduler->startWithDispose(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, null, 3000, $this->scheduler);
        }, 260);

        $this->assertMessages([
            onNext(251, $resultMessage),
            onCompleted(252)
        ], $results->getMessages());
    }

    /**
     * @test
     */
    public function progressive_error_after_result() {
        $args    = ["testing"];

        $resultMessage = new ResultMessage(null, (object)['progress' => true], $args, null);
        $errorMessage = new ErrorMessage(CallMessage::MSG_CALL, 123, (object)[], 'error.something');

        $webSocket = new Subject();
        $webSocket->subscribe(function (CallMessage $msg) use ($resultMessage, $errorMessage) {
            $requestId = $msg->getRequestId();
            $resultMessage->setRequestId($requestId);
            $errorMessage->setRequestId($requestId);
        });

        $messages = $this->createHotObservable([
                                                   onNext(150, 1),
                                                   onNext(210, new WelcomeMessage(12345, new \stdClass())),
                                                   onNext(250, $resultMessage),
                                                   onNext(300, $errorMessage),
                                                   onCompleted(350)
                                               ]);

        $results = $this->scheduler->startWithDispose(function () use ($messages, $webSocket) {
            return new CallObservable('testing.uri', $messages, $webSocket, null, null, ['receive_progress' => true], 3000, $this->scheduler);
        }, 355);

        $exception = new WampErrorException($errorMessage->getErrorURI() . ':testing.uri', $errorMessage->getArguments());

        $this->assertMessages([
                                  onNext(250, $resultMessage),
                                  onError(301, $exception)
                              ], $results->getMessages());
    }
}
