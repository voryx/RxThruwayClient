<?php

namespace Rx\Thruway;

use Rx\ObserverInterface;
use Rx\Disposable\CompositeDisposable;
use Rx\DisposableInterface;
use Rx\Thruway\Subject\WebSocketSubject;
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
    private $session, $messages, $webSocket, $disposable, $challengeCallback;

    private $currentRetryCount = 0;

    public function __construct(string $url, string $realm, array $options = [], Subject $webSocket = null, Observable $messages = null, Observable $session = null)
    {
        $this->disposable = new CompositeDisposable();

        $this->challengeCallback = function () {
        };

        $open = new Subject();
        $close = new Subject();

        $this->webSocket = $webSocket ?: new WebSocketSubject($url, ['wamp.2.json'], $open, $close);
        $this->messages = $messages ?: $this->webSocket->retryWhen([$this, '_reconnect'])->singleInstance();

        $open
            ->do(function () {
                $this->currentRetryCount = 0;
            })
            ->map(function () use ($realm, $options) {
                $options['roles'] = $this->roles();
                return new HelloMessage($realm, (object)$options);
            })
            ->subscribe($this->webSocket);

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
            ->catch(function (\Exception $ex) {
                if ($ex instanceof WampChallengeException) {
                    return Observable::just($ex->getErrorMessage());
                }
                return Observable::error($ex);
            })
            ->do($this->webSocket);

        $this->session = $session ?: $this->messages
            ->merge($challengeMsg)
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->compose(function ($observable) {
                return $this->singleInstanceReplay($observable);
            });

        $this->disposable->add($this->webSocket);
    }

    /**
     * @param string $uri
     * @param array $args
     * @param array $argskw
     * @param array $options
     * @return Observable
     */
    public function call(string $uri, array $args = [], array $argskw = [], array $options = null): Observable
    {
        return $this->session
            ->take(1)
            ->mapTo(new CallObservable($uri, $this->messages, $this->webSocket, $args, $argskw, $options))
            ->switch();
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return Observable
     */
    public function register(string $uri, callable $callback, array $options = []): Observable
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
    public function progressiveCall(string $uri, array $args = [], array $argskw = [], array $options = null): Observable
    {
        $options['receive_progress'] = true;

        $completed = new Subject();
        $callObs = new CallObservable($uri, $this->messages, $this->webSocket, $args, $argskw, $options);

        return $this->session
            ->takeUntil($completed)
            ->mapTo($callObs->doOnCompleted(function () use ($completed) {
                $completed->onNext(0);
            }))
            ->switch();
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @return Observable
     */
    public function progressiveRegister(string $uri, callable $callback, array $options = []): Observable
    {
        $options['progress'] = true;
        $options['replace_orphaned_session'] = 'yes';
        $options['force_reregister'] = true;

        return $this->registerExtended($uri, $callback, $options);
    }

    /**
     * @param string $uri
     * @param callable $callback
     * @param array $options
     * @param bool $extended
     * @return Observable
     */
    public function registerExtended(string $uri, callable $callback, array $options = [], bool $extended = true): Observable
    {
        return $this->session
            ->mapTo(new RegisterObservable($uri, $callback, $this->messages, $this->webSocket, $options, $extended))
            ->switch();
    }

    /**
     * @param string $uri
     * @param array $options
     * @return Observable
     */
    public function topic(string $uri, array $options = []): Observable
    {
        return $this->session
            ->mapTo(new TopicObservable($uri, $options, $this->messages, $this->webSocket))
            ->switch();
    }

    /**
     * @param string $uri
     * @param mixed | Observable $obs
     * @param array $options
     * @return DisposableInterface
     * @throws \InvalidArgumentException
     */
    public function publish(string $uri, $obs, array $options = []): DisposableInterface
    {
        $obs = $obs instanceof Observable ? $obs : Observable::just($obs);

        $completed = new Subject();

        return $this->session
            ->takeUntil($completed)
            ->mapTo($obs->doOnCompleted(function () use ($completed) {
                $completed->onNext(0);
            }))
            ->switchFirst()
            ->map(function ($value) use ($uri, $options) {
                return new PublishMessage(Utils::getUniqueId(), (object)$options, $uri, [$value]);
            })
            ->subscribe($this->webSocket);
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
        $maxRetryDelay = 150000;
        $initialRetryDelay = 1500;
        $retryDelayGrowth = 1.5;
        $maxRetries = 150;

        return $attempts
            ->flatMap(function (\Exception $ex) use ($maxRetryDelay, $retryDelayGrowth, $initialRetryDelay) {
                $delay = min($maxRetryDelay, pow($retryDelayGrowth, ++$this->currentRetryCount) + $initialRetryDelay);
                $seconds = number_format((float)$delay / 1000, 3, '.', '');;
                echo 'Error: ', $ex->getMessage(), PHP_EOL, "Reconnecting in ${seconds} seconds...", PHP_EOL;
                return Observable::timer((int)$delay);
            })
            ->take($maxRetries);
    }

    private function roles(): array
    {
        return [
            'caller' => [
                'features' => [
                    'caller_identification' => true,
                    'progressive_call_results' => true
                ]
            ],
            'callee' => [
                'features' => [
                    'call_canceling' => true,
                    'caller_identification' => true,
                    'pattern_based_registration' => true,
                    'shared_registration' => true,
                    'progressive_call_results' => true,
                    'registration_revocation' => true
                ]
            ],
            'publisher' => [
                'features' => [
                    'publisher_identification' => true,
                    'subscriber_blackwhite_listing' => true,
                    'publisher_exclusion' => true
                ]
            ],
            'subscriber' => [
                'features' => [
                    'publisher_identification' => true,
                    'pattern_based_subscription' => true,
                    'subscription_revocation' => true
                ]
            ]
        ];
    }

    /**
     * RxPHP's shareReplay() does not reconnect after the subscribers go from 1 to 0.
     * This Observable provides the RxJS5 shareReplay() functionality, where it can go from 1 to 0 to 1
     * without killing the observable stream.
     *
     * @param Observable $source
     * @return Observable
     * @throws \InvalidArgumentException
     */
    private function singleInstanceReplay(Observable $source): Observable
    {
        $hasObservable = false;
        $observable = null;

        $getObservable = function () use (&$hasObservable, &$observable, $source): Observable {
            if (!$hasObservable) {
                $hasObservable = true;
                $observable = $source
                    ->finally(function () use (&$hasObservable) {
                        $hasObservable = false;
                    })
                    ->shareReplay(1);
            }
            return $observable;
        };

        return new Observable\AnonymousObservable(function (ObserverInterface $o) use ($getObservable) {
            return $getObservable()->subscribe($o);
        });
    }
}
