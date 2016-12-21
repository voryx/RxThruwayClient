<?php

namespace Rx\Thruway;

use Rx\Thruway\Subject\SessionReplaySubject;
use Rx\Thruway\Subject\WebSocketSubject;
use Rx\Disposable\CompositeDisposable;
use Rx\Scheduler\EventLoopScheduler;
use React\EventLoop\LoopInterface;
use Rx\DisposableInterface;
use Thruway\Common\Utils;
use Rx\Subject\Subject;
use Rx\Observable;
use Rx\Thruway\Observable\{
    CallObservable, TopicObservable, RegisterObservable, WampChallengeException
};
use Thruway\Message\{
    AuthenticateMessage, ChallengeMessage, Message, HelloMessage, PublishMessage, WelcomeMessage
};

final class Client
{

    private $url, $loop, $realm, $session, $options, $messages, $webSocket, $scheduler, $disposable, $challengeCallback;

    private $currentRetryCount = 0;

    public function __construct(string $url, string $realm, array $options = [], LoopInterface $loop = null, Subject $webSocket = null, Observable $messages = null, Observable $session = null)
    {
        $this->url               = $url;
        $this->realm             = $realm;
        $this->options           = $options;
        $this->loop              = $loop ?? \EventLoop\getLoop();
        $this->scheduler         = new EventLoopScheduler($this->loop);
        $this->disposable        = new CompositeDisposable();
        $this->challengeCallback = function () {

        };

        $open  = new Subject();
        $close = new Subject();

        $this->webSocket = $webSocket ?: new WebSocketSubject($url, ['wamp.2.json'], $open, $close);

        $this->messages = $messages ?: $this->webSocket->retryWhen([$this, '_reconnect'])->shareReplay(0);

        $open
            ->doOnNext(function(){
                $this->currentRetryCount = 0;
            })
            ->map(function () {
            $this->options['roles'] = $this->roles();
            return new HelloMessage($this->realm, (object)$this->options);
        })->subscribe($this->webSocket, $this->scheduler);

        $challengeMsg = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof ChallengeMessage;
            })
            ->flatMapLatest(function (ChallengeMessage $msg) {
                $challengeResult = null;
                try {
                    $challengeResult = call_user_func($this->challengeCallback, Observable::just([$msg->getAuthMethod(), $msg->getDetails()]));
                } catch (\Exception $e) {
                    throw new WampChallengeException($msg);
                }
                return $challengeResult->take(1);
            })
            ->map(function ($signature) {
                return new AuthenticateMessage($signature);
            })
            ->catchError(function (\Exception $ex) {
                if ($ex instanceof WampChallengeException) {
                    return Observable::just($ex->getErrorMessage());
                }
                return Observable::error($ex);
            })
            ->doOnEach($this->webSocket);

        $this->session = $session ?: $this->messages
            ->merge($challengeMsg)
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->multicast(new SessionReplaySubject($close))->refCount();

        $this->disposable->add($this->webSocket);
    }

    /**
     * @param string $uri
     * @param array $args
     * @param array $argskw
     * @param array $options
     * @return Observable
     */
    public function call(string $uri, array $args = [], array $argskw = [], array $options = null) :Observable
    {
        return $this->session
            ->take(1)
            ->mapTo(new CallObservable($uri, $this->messages, $this->webSocket, $args, $argskw, $options))
            ->switchLatest();
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
     * This is a variant of call, that expects the far end to emit more than one result.  It will also repeat the call,
     * if the websocket connection resets and the observable has not completed or errored.
     *
     * @param string $uri
     * @param array $args
     * @param array $argskw
     * @param array $options
     * @return Observable
     */
    public function progressiveCall(string $uri, array $args = [], array $argskw = [], array $options = null) :Observable
    {
        $options['receive_progress'] = true;

        $completed = new Subject();
        $callObs   = new CallObservable($uri, $this->messages, $this->webSocket, $args, $argskw, $options);

        return $this->session
            ->takeUntil($completed)
            ->mapTo($callObs->doOnCompleted(function () use ($completed) {
                $completed->onNext(0);
            }))
            ->switchLatest();
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return Observable
     */
    public function progressiveRegister(string $uri, callable $callback, array $options = []) :Observable
    {
        $options['progress'] = true;

        $options['replace_orphaned_session'] = 'yes';

        return $this->registerExtended($uri, $callback, $options);
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
            ->mapTo(new RegisterObservable($uri, $callback, $this->messages, $this->webSocket, $options, $extended))
            ->switchLatest();
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Observable
     */
    public function topic(string $uri, array $options = []) :Observable
    {
        return $this->session
            ->mapTo(new TopicObservable($uri, $options, $this->messages, $this->webSocket))
            ->switchLatest()
            ->subscribeOn($this->scheduler);
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

        return $this->session
            ->takeUntil($completed)
            ->mapTo($obs->doOnCompleted(function () use ($completed) {
                $completed->onNext(0);
            }))
            ->lift(function () {
                return new SwitchFirstOperator();
            })
            ->map(function ($value) use ($uri, $options) {
                return new PublishMessage(Utils::getUniqueId(), (object)$options, $uri, [$value]);
            })
            ->subscribe($this->webSocket, $this->scheduler);
    }

    public function onChallenge(callable $challengeCallback)
    {
        $this->challengeCallback = $challengeCallback;
    }

    public function close()
    {
        $this->disposable->dispose();
    }

    public function _reconnect(Observable $attempts)
    {
        $maxRetryDelay     = 150000;
        $initialRetryDelay = 1500;
        $retryDelayGrowth  = 1.5;
        $maxRetries        = 150;

        return $attempts
            ->flatMap(function (\Exception $ex) use ($maxRetryDelay, $retryDelayGrowth, $initialRetryDelay) {
                $delay   = min($maxRetryDelay, pow($retryDelayGrowth, ++$this->currentRetryCount) + $initialRetryDelay);
                $seconds = number_format((float)$delay / 1000, 3, '.', '');;
                echo "Error: ", $ex->getMessage(), PHP_EOL, "Reconnecting in ${seconds} seconds...", PHP_EOL;
                return Observable::timer((int)$delay, $this->scheduler);
            })
            ->take($maxRetries);
    }

    private function roles()
    {
        return [
            "caller"     => [
                "features" => [
                    "caller_identification"    => true,
                    "progressive_call_results" => true
                ]
            ],
            "callee"     => [
                "features" => [
                    "call_canceling"             => true,
                    "caller_identification"      => true,
                    "pattern_based_registration" => true,
                    "shared_registration"        => true,
                    "progressive_call_results"   => true,
                    "registration_revocation"    => true
                ]
            ],
            "publisher"  => [
                "features" => [
                    "publisher_identification"      => true,
                    "subscriber_blackwhite_listing" => true,
                    "publisher_exclusion"           => true
                ]
            ],
            "subscriber" => [
                "features" => [
                    "publisher_identification"   => true,
                    "pattern_based_subscription" => true,
                    "subscription_revocation"    => true
                ]
            ]
        ];
    }
}
