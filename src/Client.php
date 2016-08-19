<?php

namespace Rx\Thruway;

use Rx\Disposable\CompositeDisposable;
use Rx\Observable;
use Rx\Scheduler\EventLoopScheduler;
use Rx\Subject\ReplaySubject;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use Rx\Subject\Subject;
use Rx\Thruway\Observer\ChallengeObserver;
use Thruway\Serializer\JsonSerializer;
use Rx\Extra\Observable\FromEventEmitterObservable;
use Rx\Thruway\Observable\{
    CallObservable, TopicObservable, RegisterObservable, WebSocketObservable, WampChallengeException
};
use Thruway\Message\{
    AuthenticateMessage, ChallengeMessage, Message, HelloMessage, WelcomeMessage
};

class Client
{
    private $url;
    private $loop;
    private $realm;
    private $session;
    private $options;
    private $messages;
    private $webSocket;
    private $scheduler;
    private $serializer;
    private $disposable;
    private $onError;
    private $onOpen;

    public function __construct(string $url, string $realm, array $options = [], LoopInterface $loop = null)
    {

        $this->url        = $url;
        $this->loop       = $loop ?? \EventLoop\getLoop();
        $this->scheduler  = new EventLoopScheduler($this->loop);
        $this->realm      = $realm;
        $this->webSocket  = (new WebSocketObservable($url, $this->loop))->repeat()->retryWhen([$this, 'reconnect'])->shareReplay(1);
        $this->serializer = new JsonSerializer();
        $this->messages   = $this->messagesFromWebSocket($this->webSocket)->share();
        $this->session    = new ReplaySubject(1);
        $this->options    = $options;
        $this->disposable = new CompositeDisposable();
        $this->onError    = new Subject();
        $this->onOpen     = new Subject();

        $this->sendHelloMessage();
        $this->setUpSession();
    }

    /**
     * Send message after the session is setup
     *
     * @param Message $msg
     * @return Observable
     */
    public function sendMessage(Message $msg) :Observable
    {
        return $this->session
            ->flatMap(function () {
                return $this->webSocket;
            })
            ->take(1)
            ->doOnNext(function (WebSocket $webSocket) use ($msg) {
                $webSocket->send($this->serializer->serialize($msg));
            })
            ->flatMap(function () {
                return Observable::emptyObservable();
            });
    }

    /**
     * @param string $uri
     * @param array $args
     * @param array $argskw
     * @param array $options
     * @return CallObservable
     */
    public function call(string $uri, array $args = [], array $argskw = [], array $options = null) :CallObservable
    {
        return new CallObservable($uri, $this->messages, [$this, 'sendMessage'], $args, $argskw, $options);
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return Observable
     */
    public function register(string $uri, callable $callback, array $options = []) :Observable
    {
        return $this->session->flatMap(function () use ($uri, $callback, $options) {
            return new RegisterObservable($uri, $callback, $this->messages, [$this, 'sendMessage'], $options);
        });
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return RegisterObservable
     */
    public function registerExtended(string $uri, callable $callback, array $options = []) :RegisterObservable
    {
        return $this->session->flatMap(function () use ($uri, $callback, $options) {
            return new RegisterObservable($uri, $callback, $this->messages, [$this, 'sendMessage'], $options, true);
        });
    }

    /**
     * @param string $uri
     * @param array $options
     * @return TopicObservable
     */
    public function topic(string $uri, array $options = []) :Observable
    {
        return $this->session->flatMap(function () use ($uri, $options) {
            return new TopicObservable($uri, $options, $this->messages, [$this, 'sendMessage']);
        });
    }

    public function onChallenge(callable $challengeCallback)
    {
        $sub = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof ChallengeMessage;
            })
            ->flatMap(function (ChallengeMessage $msg) use ($challengeCallback) {
                $challengeResult = null;
                try {
                    $challengeResult = call_user_func($challengeCallback, Observable::just([$msg->getAuthMethod(), $msg->getDetails()]));
                } catch (\Exception $e) {
                    throw new WampChallengeException($msg);
                }
                return $challengeResult->take(1);
            })
            ->map(function ($signature) {
                return new AuthenticateMessage($signature);
            })
            ->subscribe(new ChallengeObserver($this->webSocket, $this->serializer));

        $this->disposable->add($sub);
    }

    public function onError()
    {
        return $this->onError;
    }

    public function onOpen()
    {
        return $this->onOpen;
    }

    public function close()
    {
        //@todo do other close stuff.  should probably emit on a normal closing subject
        $this->disposable->dispose();
    }

    /**
     * Emits new sessions onto a session subject
     */
    private function setUpSession()
    {
        $sub = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->doOnNext(function (WelcomeMessage $msg) {
                $this->onOpen->onNext($msg);
            })
            ->subscribe($this->session);

        $this->disposable->add($sub);
    }

    /**
     * Send a HelloMessage on each new WebSocket connection
     */
    private function sendHelloMessage()
    {
        $helloMsg = new HelloMessage($this->realm, (object)$this->options);

        $sub = $this->webSocket
            ->map(function (WebSocket $ws) use ($helloMsg) {
                return $ws->send($this->serializer->serialize($helloMsg));
            })
            ->subscribeCallback(
            //@todo log stuff
            );

        $this->disposable->add($sub);
    }

    /**
     * @param Observable $webSocket
     * @return Observable
     */
    private function messagesFromWebSocket(Observable $webSocket) :Observable
    {
        return $webSocket
            ->flatMap(function (WebSocket $webSocket) {
                return (new FromEventEmitterObservable($webSocket, "message", "error", "close"));
            })
            ->map(function ($msg) {
                return $this->serializer->deserialize($msg[0]);
            });
    }

    public function reconnect(Observable $attempts)
    {
        $maxRetryDelay     = 300000;
        $initialRetryDelay = 1500;
        $retryDelayGrowth  = 1.5;
        $maxRetries        = 150;
        $exponent          = 0;

        return $attempts
            ->flatMap(function ($ex) use ($maxRetryDelay, $retryDelayGrowth, &$exponent, $initialRetryDelay) {
                $this->onError->onNext($ex);
                $delay = min($maxRetryDelay, pow($retryDelayGrowth, ++$exponent) + $initialRetryDelay);
                return Observable::timer((int)$delay, $this->scheduler);
            })
            ->take($maxRetries);
    }
}
