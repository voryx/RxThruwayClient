<?php

namespace Rx\Thruway\Observable;

use Thruway\Message\ChallengeMessage;
use Thruway\Message\ErrorMessage;
use Thruway\WampErrorException;

class WampChallengeException extends WampErrorException
{
    private $challengeMessage;

    public function __construct(ChallengeMessage $msg, $errorUri = 'thruway.error.challenge_exception', $arguments = null, $argumentsKw = null, $details = null)
    {
        $this->challengeMessage = $msg;

        parent::__construct($errorUri, $arguments, $argumentsKw, $details);
    }

    /**
     * @return mixed
     */
    public function getErrorMessage()
    {
        //@todo This should be an abort message not an error
        return ErrorMessage::createErrorMessageFromMessage($this->challengeMessage, $this->getErrorUri());
    }
}
