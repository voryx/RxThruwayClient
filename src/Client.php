<?php

namespace Rx\Thruway;

use Rx\Disposable\CompositeDisposable;
use Rx\DisposableInterface;
use Rx\Observable;
use Rx\Observer\CallbackObserver;
use Rx\Scheduler\EventLoopScheduler;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use Rx\Subject\ReplaySubject;
use Rx\Subject\Subject;
use Rx\Thruway\Observer\ChallengeObserver;
use Thruway\Common\Utils;
use Thruway\Serializer\JsonSerializer;
use Rx\Extra\Observable\FromEventEmitterObservable;
use Rx\Thruway\Observable\{
    CallObservable, TopicObservable, RegisterObservable, WebSocketObservable, WampChallengeException
};
use Thruway\Message\{
    AuthenticateMessage, ChallengeMessage, Message, HelloMessage, PublishMessage, WelcomeMessage
};

class Client
{
    private $url, $loop, $realm, $session, $options, $messages, $webSocket, $scheduler, $serializer, $disposable, $onError, $onOpen;

    public function __construct(string $url, string $realm, array $options = [], LoopInterface $loop = null)
    {
        $this->url        = $url;
        $this->realm      = $realm;
        $this->options    = $options;
        $this->loop       = $loop ?? \EventLoop\getLoop();
        $this->scheduler  = new EventLoopScheduler($this->loop);
        $this->webSocket  = (new WebSocketObservable($url, $this->loop))->retryWhen([$this, 'reconnect'])->shareReplay(1);
        $this->serializer = new JsonSerializer();
        $this->messages   = $this->messagesFromWebSocket($this->webSocket)->share();
        $this->session    = new ReplaySubject(1);
        $this->disposable = new CompositeDisposable();
        $this->onError    = new Subject();
        $this->onOpen     = new Subject();

        $this->sendHelloMessage()->concatMapTo($this->getWelcomeMessage())->subscribe($this->session);
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
            ->flatMapTo($this->webSocket)
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
        return $this->registerExtended($uri, $callback, $options, false);
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @param bool $extended
     * @return Observable
     */
    public function registerExtended(string $uri, callable $callback, array $options = [], bool $extended = true) :Observable
    {
        return $this->session
            ->flatMapTo(new RegisterObservable($uri, $callback, $this->messages, [$this, 'sendMessage'], $options, $extended))
            ->subscribeOn($this->scheduler);
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Observable
     */
    public function topic(string $uri, array $options = []) :Observable
    {
        return $this->session->flatMapTo(new TopicObservable($uri, $options, $this->messages, [$this, 'sendMessage']));
    }

    /**
     * @param string $uri
     * @param mixed | Observable $obs
     * @param array $options
     * @return DisposableInterface
     */
    public function publish(string $uri, $obs, array $options = []) : DisposableInterface
    {
        $obs = $obs instanceof Observable ? $obs : Observable::just($obs);

        $completed = new Subject();

        $sub = $this->session
            ->takeUntil($completed)
            ->flatMapTo($obs->doOnCompleted(function () use ($completed) {
                $completed->onNext(0);
            }))
            ->map(function ($value) use ($uri, $options) {
                return new PublishMessage(Utils::getUniqueId(), (object)$options, $uri, [$value]);
            })
            ->flatMap([$this, 'sendMessage'])
            ->subscribe(new CallbackObserver(), $this->scheduler);

        $this->disposable->add($sub);

        return $sub;
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
            ->subscribe(new ChallengeObserver($this->webSocket, $this->serializer), $this->scheduler);

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
    private function getWelcomeMessage()
    {
        return $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->doOnNext(function (WelcomeMessage $msg) {
                $this->onOpen->onNext($msg);
            });
    }

    /**
     * Send a HelloMessage on each new WebSocket connection
     */
    private function sendHelloMessage()
    {
        return $this->webSocket->doOnNext(function (WebSocket $ws) {
            $helloMsg = new HelloMessage($this->realm, (object)$this->options);
            $ws->send($this->serializer->serialize($helloMsg));
        });
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
                echo $this->serializer->deserialize($msg[0]), PHP_EOL;
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
