<?php


namespace Rx\Thruway\Tests\Functional;


use Thruway\Message\Message;

class FunctionalTestCase extends \Rx\Functional\FunctionalTestCase
{

    private $wampMessages = [];

    public function recordWampMessage(Message $msg)
    {
        if (method_exists($msg, 'setRequestId')) {
            $msg->setRequestId(12345);
        }
        $this->wampMessages[] = [$this->scheduler->getClock(), json_encode($msg)];
    }

    public function assertWampMessages(array $expected, array $recorded)
    {
        if (count($expected) !== count($recorded)) {
            $this->fail(sprintf('Expected message count %d does not match actual count %d.', count($expected), count($recorded)));
        }

        for ($i = 0, $count = count($expected); $i < $count; $i++) {
            if (array_diff($expected[$i], $recorded[$i])) {
                $this->fail(json_encode($recorded[$i]) . ' does not equal expected ' . json_encode($expected[$i]));
            }
        }

        $this->assertTrue(true); // success
    }

    /**
     * @return array
     */
    public function getWampMessages()
    {
        return $this->wampMessages;
    }


}