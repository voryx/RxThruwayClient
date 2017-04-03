<?php

namespace Rx\Thruway;

use Rx\Disposable\CompositeDisposable;
use Rx\DisposableInterface;
use Rx\Websocket\MessageSubject;
use Thruway\Common\Utils;
use Rx\Subject\Subject;
use Rx\Observable;
use Rx\Thruway\Observable\{
    CallObservable, TopicObservable, RegisterObservable, WampChallengeException
};
use Thruway\Message\{
    AuthenticateMessage, ChallengeMessage, Message, HelloMessage, PublishMessage, WelcomeMessage
};
use Thruway\Serializer\JsonSerializer;

final class Client
{
    private $session, $messages, $sendMessage, $disposable, $challengeCallback;

    private $currentRetryCount = 0;

    public function __construct(string $url, string $realm, array $options = [], Subject $webSocket = null, Observable $messages = null, Observable $session = null)
    {
        $this->disposable = new CompositeDisposable();
        $serializer       = new JsonSerializer();

        $this->challengeCallback = function () {
        };

        $ws = (new \Rx\Websocket\Client($url, false, ['wamp.2.json']))
            ->retryWhen([$this, '_retry'])
            ->repeatWhen([$this, '_repeat'])
            ->compose(function ($source) {
                return new SingleInstanceObservable($source);
            });

        $this->sendMessage = $webSocket ?: new Subject();

        $this->messages = $ws
            ->do(function (MessageSubject $ms) use ($realm, $serializer) {
                $this->currentRetryCount = 0;

                $options['roles'] = $this->roles();
                $ms->onNext($serializer->serialize(new HelloMessage($realm, (object)$options)));
            })
            ->switch()
            ->map([$serializer, 'deserialize'])
            ->share();

        $challengeMsg = $this->messages
            ->filter(function (Message $msg) {
                return $msg instanceof ChallengeMessage;
            })
            ->flatMapLatest(function (ChallengeMessage $msg) {
                $challengeResult = null;
                try {
                    $challengeResult = call_user_func($this->challengeCallback, Observable::of([$msg->getAuthMethod(), $msg->getDetails()]));
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
                    return Observable::of($ex->getErrorMessage());
                }
                return Observable::error($ex);
            })
            ->do([$this->sendMessage, 'onNext']);

        $this->session = $this->messages
            ->merge($challengeMsg)
            ->filter(function (Message $msg) {
                return $msg instanceof WelcomeMessage;
            })
            ->shareReplay(1);

        $s1 = $this->sendMessage
            ->map([$serializer, 'serialize'])
            ->lift(function () use ($ws) {
                return new WithLatestFromOperator([$ws, $this->session]);
            })
            ->subscribe(function ($args) {
                /** @var MessageSubject $ms */
                /** @var Message $msg */
                list($msg, $ms) = $args;
                $ms->send($msg);
            });

        $this->disposable->add($s1);
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
            ->mapTo(new CallObservable($uri, $this->messages, $this->sendMessage, $args, $argskw, $options))
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
        $callObs   = new CallObservable($uri, $this->messages, $this->sendMessage, $args, $argskw, $options);

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
            ->mapTo(new RegisterObservable($uri, $callback, $this->messages, $this->sendMessage, $options, $extended))
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
            ->mapTo(new TopicObservable($uri, $options, $this->messages, $this->sendMessage))
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
        $obs = $obs instanceof Observable ? $obs : Observable::of($obs);

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
            ->subscribe([$this->sendMessage, 'onNext']);
    }

    public function onChallenge(callable $challengeCallback)
    {
        $this->challengeCallback = $challengeCallback;
    }

    public function close()
    {
        $this->disposable->dispose();
    }

    public function _retry(Observable $errors)
    {
        $maxRetryDelay     = 150000;
        $initialRetryDelay = 1500;
        $retryDelayGrowth  = 1.5;
        $maxRetries        = 150;

        return $errors
            ->flatMap(function (\Exception $ex) use ($maxRetryDelay, $retryDelayGrowth, $initialRetryDelay) {
                $delay   = min($maxRetryDelay, pow($retryDelayGrowth, ++$this->currentRetryCount) + $initialRetryDelay);
                $seconds = number_format((float)$delay / 1000, 3, '.', '');
                echo 'Error: ', $ex->getMessage(), PHP_EOL, "Reconnecting in ${seconds} seconds...", PHP_EOL, PHP_EOL;
                return Observable::timer((int)$delay);
            })
            ->take($maxRetries);
    }

    public function _repeat(Observable $attempts)
    {
        $maxRetryDelay     = 150000;
        $initialRetryDelay = 1500;
        $retryDelayGrowth  = 1.5;
        $maxRetries        = 150;

        return $attempts
            ->flatMap(function () use ($maxRetryDelay, $retryDelayGrowth, $initialRetryDelay) {
                $delay   = min($maxRetryDelay, pow($retryDelayGrowth, ++$this->currentRetryCount) + $initialRetryDelay);
                $seconds = number_format((float)$delay / 1000, 3, '.', '');
                echo 'Websocket Closed', PHP_EOL, "Reconnecting in ${seconds} seconds...", PHP_EOL, PHP_EOL;
                return Observable::timer((int)$delay);
            })
            ->take($maxRetries);
    }

    private function roles()
    {
        return [
            'caller'     => [
                'features' => [
                    'caller_identification'    => true,
                    'progressive_call_results' => true
                ]
            ],
            'callee'     => [
                'features' => [
                    'call_canceling'             => true,
                    'caller_identification'      => true,
                    'pattern_based_registration' => true,
                    'shared_registration'        => true,
                    'progressive_call_results'   => true,
                    'registration_revocation'    => true
                ]
            ],
            'publisher'  => [
                'features' => [
                    'publisher_identification'      => true,
                    'subscriber_blackwhite_listing' => true,
                    'publisher_exclusion'           => true
                ]
            ],
            'subscriber' => [
                'features' => [
                    'publisher_identification'   => true,
                    'pattern_based_subscription' => true,
                    'subscription_revocation'    => true
                ]
            ]
        ];
    }
}
