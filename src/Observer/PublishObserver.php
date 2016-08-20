<?php

namespace Rx\Thruway\Observer;

use Exception;
use Rx\Thruway\Client;
use Thruway\Common\Utils;
use Thruway\Message\Message;
use Rx\Observer\AbstractObserver;
use Thruway\Message\PublishMessage;
use Rx\Disposable\CompositeDisposable;

class PublishObserver extends AbstractObserver
{
    private $client, $topic, $options, $disposable;

    public function __construct(string $topic, array $options, Client $client)
    {
        $this->client     = $client;
        $this->topic      = $topic;
        $this->options    = $options;
        $this->disposable = new CompositeDisposable();
    }

    protected function completed()
    {
        $this->disposable->dispose();
    }

    protected function next($value)
    {
        $this->sendMessage(new PublishMessage(Utils::getUniqueId(), (object)$this->options, $this->topic, [$value]));
    }

    protected function error(Exception $error)
    {
        $this->disposable->dispose();
    }

    protected function sendMessage(Message $msg)
    {
        $sub = call_user_func([$this->client, 'sendMessage'], $msg)->subscribeCallback();

//        $this->disposable->add($sub);
    }
}
