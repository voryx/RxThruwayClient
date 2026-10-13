<?php declare(strict_types=1);

namespace Rx\Thruway\Serializer;

use Rx\Thruway\Message\RegistrationRevokedMessage;
use Thruway\Message\Message;
use Thruway\Serializer\DeserializationException;
use Thruway\Serializer\SerializerInterface;

/**
 * JSON serializer that understands advanced profile message forms the thruway-common
 * parser drops. Currently that is the router-initiated UNREGISTERED message used for
 * registration revocation, which carries a details dict that Message::createMessageFromArray
 * discards. Everything else is delegated to thruway-common.
 */
final class JsonSerializer implements SerializerInterface
{
    public function serialize(Message $msg)
    {
        return json_encode($msg);
    }

    public function deserialize($serializedData)
    {
        if (null === ($data = @json_decode($serializedData))) {
            throw new DeserializationException('Error decoding json "' . $serializedData . '"');
        }

        // [UNREGISTERED, 0, Details|dict] - the Dealer revoked one of our registrations
        if (is_array($data) && ($data[0] ?? null) === Message::MSG_UNREGISTERED && isset($data[2])) {
            return new RegistrationRevokedMessage($data[1], $data[2]);
        }

        return Message::createMessageFromArray($data);
    }
}
