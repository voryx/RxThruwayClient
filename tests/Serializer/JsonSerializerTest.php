<?php

namespace Rx\Thruway\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Rx\Thruway\Message\RegistrationRevokedMessage;
use Rx\Thruway\Serializer\JsonSerializer;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\UnregisteredMessage;
use Thruway\Serializer\DeserializationException;

class JsonSerializerTest extends TestCase
{
    /** @var JsonSerializer */
    private $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonSerializer();
    }

    /**
     * @test
     */
    public function deserializes_registration_revocation()
    {
        $msg = $this->serializer->deserialize('[67,0,{"registration":1293722,"reason":"moving endpoint to other callee"}]');

        $this->assertInstanceOf(RegistrationRevokedMessage::class, $msg);
        $this->assertSame(0, $msg->getRequestId());
        $this->assertSame(1293722, $msg->getRegistrationId());
        $this->assertSame('moving endpoint to other callee', $msg->getReason());
        $this->assertEquals((object)['registration' => 1293722, 'reason' => 'moving endpoint to other callee'], $msg->getDetails());
    }

    /**
     * @test
     */
    public function deserializes_registration_revocation_without_reason()
    {
        $msg = $this->serializer->deserialize('[67,0,{"registration":1293722}]');

        $this->assertInstanceOf(RegistrationRevokedMessage::class, $msg);
        $this->assertSame(1293722, $msg->getRegistrationId());
        $this->assertNull($msg->getReason());
    }

    /**
     * @test
     */
    public function deserializes_plain_unregistered_ack_as_thruway_message()
    {
        $msg = $this->serializer->deserialize('[67,12345]');

        $this->assertInstanceOf(UnregisteredMessage::class, $msg);
        $this->assertNotInstanceOf(RegistrationRevokedMessage::class, $msg);
        $this->assertSame(12345, $msg->getRequestId());
    }

    /**
     * @test
     */
    public function delegates_other_messages_to_thruway_common()
    {
        $msg = $this->serializer->deserialize('[65,12345,54321]');

        $this->assertInstanceOf(RegisteredMessage::class, $msg);
        $this->assertSame(12345, $msg->getRequestId());
        $this->assertSame(54321, $msg->getRegistrationId());
    }

    /**
     * @test
     */
    public function serializes_registration_revocation_round_trip()
    {
        $msg = new RegistrationRevokedMessage(0, ['registration' => 1293722, 'reason' => 'moving endpoint to other callee']);

        $json = $this->serializer->serialize($msg);

        $this->assertSame('[67,0,{"registration":1293722,"reason":"moving endpoint to other callee"}]', $json);
        $this->assertEquals($msg, $this->serializer->deserialize($json));
    }

    /**
     * @test
     */
    public function serializes_thruway_messages()
    {
        $this->assertSame('[65,12345,54321]', $this->serializer->serialize(new RegisteredMessage(12345, 54321)));
    }

    /**
     * @test
     */
    public function throws_on_invalid_json()
    {
        $this->expectException(DeserializationException::class);

        $this->serializer->deserialize('[67,0,');
    }
}
