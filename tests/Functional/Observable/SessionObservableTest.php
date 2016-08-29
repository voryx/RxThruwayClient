<?php

namespace Rx\Thruway\Tests\Functional\Observable;

use Rx\Observable;
use Rx\Subject\Subject;
use Rx\Testing\MockObserver;
use Rx\Thruway\Observable\SessionObservable;
use Rx\Functional\FunctionalTestCase;
use Thruway\Message\WelcomeMessage;

class SessionObservableTest extends FunctionalTestCase
{

    /**
     * @test
     */
    function session_message_never()
    {
        $messages = Observable::never();

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return new SessionObservable($messages, new Subject());
        });

        $this->assertMessages([], $results->getMessages());
    }

    /**
     * @test
     */
    function session_messages_empty()
    {
        $messages = $this->createHotObservable([
            onNext(150, 1),
            onCompleted(235)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return new SessionObservable($messages, new Subject());
        });

        $this->assertMessages([
            onCompleted(235)
        ], $results->getMessages());
    }


    /**
     * @test
     */
    function session_messages_welcome()
    {
        $welcomeMessage = new WelcomeMessage(123, (object)[]);

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, $welcomeMessage),
            onCompleted(600)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return new SessionObservable($messages, new Subject());
        });

        $this->assertMessages([
            onNext(201, $welcomeMessage),
            onCompleted(600)
        ], $results->getMessages());
    }

//    Need a lot more message and error tests


    /**
     * @test
     */
    function session_subscriptions_none()
    {

        $messages = $this->createHotObservable([
            onNext(150, 1)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return new SessionObservable($messages, new Subject());
        });

        $this->assertMessages([], $results->getMessages());

        $this->assertSubscriptions([subscribe(200, 1000)], $messages->getSubscriptions());
    }


    /**
     * @test
     */
    function session_subscriptions_take_one()
    {

        $welcomeMessage = new WelcomeMessage(123, (object)[]);

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, $welcomeMessage),
            onCompleted(600)
        ]);

        $results = $this->scheduler->startWithCreate(function () use ($messages) {
            return (new SessionObservable($messages, new Subject()))->take(1);
        });

        $this->assertMessages([
            onNext(201, $welcomeMessage),
            onCompleted(201)
        ], $results->getMessages());

        $this->assertSubscriptions([subscribe(200, 201)], $messages->getSubscriptions());
    }


    /**
     * @test
     */
    function session_subscriptions_overlap()
    {

        $welcomeMessage = new WelcomeMessage(123, (object)[]);

        $messages = $this->createHotObservable([
            onNext(150, 1),
            onNext(201, $welcomeMessage),
            onCompleted(600)
        ]);

        $session = new SessionObservable($messages, new Subject());

        $results = new MockObserver($this->scheduler);

        $this->scheduler->scheduleAbsolute(200, function () use ($results, $session) {
            $dispose = $session->subscribe($results, $this->scheduler);
            $this->scheduler->scheduleAbsolute(300, function () use ($dispose) {
                $dispose->dispose();
            });
        });

        $results2 = new MockObserver($this->scheduler);

        $this->scheduler->scheduleAbsolute(250, function () use ($results2, $session) {
            $dispose = $session->subscribe($results2, $this->scheduler);
            $this->scheduler->scheduleAbsolute(1000, function () use ($dispose) {
                $dispose->dispose();
            });
        });

        $this->scheduler->start();

        $this->assertMessages([
            onNext(201, $welcomeMessage)

        ], $results->getMessages());

        $this->assertMessages([
            onNext(251, $welcomeMessage),
            onCompleted(600)
        ], $results2->getMessages());

        $this->assertSubscriptions([
            subscribe(200, 600)
        ], $messages->getSubscriptions());
    }

    /**
     * @test
     */
    function session_subscriptions_dispose()
    {

        $messages = $this->createHotObservable([
            onNext(150, 1)
        ]);

        $results = $this->scheduler->startWithDispose(function () use ($messages) {
            return new SessionObservable($messages, new Subject());
        }, 400);

        $this->assertMessages([], $results->getMessages());

        $this->assertSubscriptions([subscribe(200, 400)], $messages->getSubscriptions());
    }

}
