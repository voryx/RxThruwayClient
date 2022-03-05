<?php

namespace Rx\Thruway;

use React\Socket\ConnectorInterface;
use Rx\Exception\Exception;
use Rx\Disposable\CompositeDisposable;
use Rx\DisposableInterface;
use Rx\Thruway\Subject\WebSocketSubject;
use Thruway\Common\Utils;
use Rx\Subject\Subject;
use Rx\Observable;
use Rx\Thruway\Observable\{
    CallObservable, SingleInstanceReplay, TopicObservable, RegisterObservable, WampChallengeException
};
use Thruway\Message\{
    AbortMessage, AuthenticateMessage, ChallengeMessage, GoodbyeMessage, Message, HelloMessage, PublishMessage, WelcomeMessage
};

final class Client
{
    /* @var Observable */
    private $session;

    private $disposable, $challengeCallback, $onClose;

    private $currentRetryCount = 0;

    public function __construct(string $url, string $realm, array $options = [], Subject $webSocket = null, Observable $messages = null, Observable $session = null, ConnectorInterface $connector = null, array $headers = [])
    {
        $this->disposable = new CompositeDisposable();
        $this->onClose    = new Subject();

        $this->challengeCallback = function () {
        };

        $open  = new Subject();
        $close = new Subject();

        $webSocket = $webSocket ?: new WebSocketSubject($url, ['wamp.2.json'], $open, $close, $connector, $headers);
        $messages  = $messages ?: $webSocket->retryWhen([$this, '_reconnect'])->singleInstance();

        //When the connection opens, send a HelloMessage
        $open
            ->do(function () {
                $this->currentRetryCount = 0;
            })
            ->map(function () use ($realm, $options) {
                $options['roles'] = $this->roles();
                return new HelloMessage($realm, (object)$options);
            })
            ->subscribe(
                [$webSocket, 'onNext'],
                function (\Throwable $e) {
                    echo 'Error while opening websocket connection: ' . $e->getMessage() . PHP_EOL;
                }
            );

        [$challengeMsg, $remainingMsgs] = $messages->partition(function (Message $msg) {
            return $msg instanceof ChallengeMessage;
        });

        [$goodByeMsg, $remainingMsgs] = $remainingMsgs->partition(function ($msg) {
            return $msg instanceof AbortMessage || $msg instanceof GoodbyeMessage;
        });

        [$abortMsg, $remainingMsgs] = $remainingMsgs->partition(function (Message $msg) {
            return $msg instanceof AbortMessage;
        });

        $goodByeMsg = $goodByeMsg->do([$this->onClose, 'onNext']);

        $remainingMsgs = $remainingMsgs->merge($goodByeMsg);

        $abortMsg = $abortMsg->do([$this->onClose, 'onNext']);

        $challenge = $this->challenge($challengeMsg)->do([$webSocket, 'onNext']);

        $abortError = $abortMsg->map(function (AbortMessage $msg) {
            throw new Exception($msg->getDetails()->message . ' ' . $msg->getResponseURI());
        });

        [$welcomeMsg, $remainingMsgs] = $remainingMsgs
            ->merge($challenge)
            ->merge($abortError)
            ->partition(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            });

        $this->session = $session ?: $welcomeMsg
            ->map(function (WelcomeMessage $welcomeMsg) use ($remainingMsgs, $webSocket) {
                return [$remainingMsgs, $webSocket, $welcomeMsg];
            })
            ->compose(new SingleInstanceReplay(1));

        $this->disposable->add($webSocket);
    }

    public function call(string $uri, array $args = [], array $argskw = [], array $options = null): Observable
    {
        return $this->session
            ->flatMapLatest(function ($res) use ($uri, $args, $argskw, $options) {
                [$messages, $webSocket] = $res;
                return new CallObservable($uri, $messages, $webSocket, $args, $argskw, $options);
            })
            ->take(1);
    }

    public function register(string $uri, callable $callback, array $options = []): Observable
    {
        return $this->registerExtended($uri, $callback, $options, false);
    }

    /**
     * This is a variant of call, that expects the far end to emit more than one result.  It will also repeat the call,
     * if the websocket connection resets and the observable has not completed or errored.
     *
     */
    public function progressiveCall(string $uri, array $args = [], array $argskw = [], array $options = null): Observable
    {
        $options['receive_progress'] = true;

        return $this->session
            ->flatMapLatest(function ($res) use ($uri, $args, $argskw, $options) {
                [$messages, $webSocket] = $res;
                return new CallObservable($uri, $messages, $webSocket, $args, $argskw, $options);
            })
            ->retryWhen([$this, '_reconnect']);
    }

    public function progressiveRegister(string $uri, callable $callback, array $options = []): Observable
    {
        $options['progress']                 = true;
        $options['replace_orphaned_session'] = 'yes';
        $options['force_reregister']         = true;

        return $this->registerExtended($uri, $callback, $options);
    }

    public function registerExtended(string $uri, callable $callback, array $options = [], bool $extended = true): Observable
    {
        return $this->session->flatMapLatest(function ($res) use ($uri, $callback, $options, $extended) {
            [$messages, $webSocket] = $res;
            return new RegisterObservable($uri, $callback, $messages, $webSocket, $options, $extended);
        });
    }

    public function topic(string $uri, array $options = []): Observable
    {
        return $this->session->flatMapLatest(function ($res) use ($uri, $options) {
            [$messages, $webSocket] = $res;
            return new TopicObservable($uri, $options, $messages, $webSocket);
        });
    }

    public function publish(string $uri, $obs, array $options = []): DisposableInterface
    {
        $obs = $obs instanceof Observable ? $obs : Observable::of($obs);

        $completed = new Subject();

        return $this->session
            ->takeUntil($completed->delay(1))
            ->pluck(1)
            ->map(function ($webSocket) use ($obs, $completed, $uri, $options) {
                return $obs
                    ->finally(function () use ($completed) {
                        $completed->onNext(0);
                    })
                    ->map(function ($value) use ($uri, $options) {
                        return new PublishMessage(Utils::getUniqueId(), (object)$options, $uri, [$value]);
                    })
                    ->do([$webSocket, 'onNext']);
            })
            ->switchFirst()
            ->subscribe();
    }

    public function onChallenge(callable $challengeCallback)
    {
        $this->challengeCallback = $challengeCallback;
    }

    public function close()
    {
        $this->disposable->dispose();
    }

    public function onOpen(): Observable
    {
        return $this->session;
    }

    public function onClose(): Observable
    {
        return $this->onClose;
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
                $seconds = number_format((float)$delay / 1000, 3, '.', '');
                echo 'Error: ', $ex->getMessage(), PHP_EOL, "Reconnecting in ${seconds} seconds...", PHP_EOL;
                return Observable::timer((int)$delay);
            })
            ->take($maxRetries);
    }

    private function challenge(Observable $challengeMsg): Observable
    {
        return $challengeMsg
            ->flatMapLatest(function (ChallengeMessage $msg) {
                $challengeResult = null;
                try {
                    $challengeResult = \call_user_func($this->challengeCallback, Observable::of($msg));
                } catch (\Throwable $e) {
                    throw new WampChallengeException($msg);
                }
                return $challengeResult->take(1);
            })
            ->map(function ($signature) {
                return new AuthenticateMessage($signature);
            })
            ->catch(function (\Throwable $ex) {
                if ($ex instanceof WampChallengeException) {
                    return Observable::of($ex->getErrorMessage());
                }
                return Observable::error($ex);
            });
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
}
