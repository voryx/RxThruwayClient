<?php

namespace Rx\Thruway\Observable;

use Thruway\Message\ErrorMessage;
use Thruway\Message\InvocationMessage;
use Thruway\WampErrorException;

final class WampInvocationException extends WampErrorException
{
    private $invocationMessage;

    public function __construct(InvocationMessage $msg, $errorUri = 'thruway.error.invocation_exception', $arguments = null, $argumentsKw = null, $details = null)
    {
        $this->invocationMessage = $msg;

        parent::__construct($errorUri, $arguments, $argumentsKw, $details);
    }

    /**
     * @return mixed
     */
    public function getErrorMessage()
    {
        return ErrorMessage::createErrorMessageFromMessage($this->invocationMessage, $this->getErrorUri());
    }
}
