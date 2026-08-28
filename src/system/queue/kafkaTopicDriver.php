<?php

namespace mpcmf\system\queue;

use mpcmf\system\queue\exceptions\kafkaException;
use mpcmf\system\queue\interfaces\queueInterface;

class kafkaTopicDriver implements queueInterface
{

    /** @var kafka */
    protected $kafka;
    /** @var string */
    protected $topicName;

    public function __construct(kafka $kafka, string $topicName)
    {
        $this->kafka = $kafka;
        $this->topicName = $topicName;
    }

    public function get($flags = 0)
    {
        throw new kafkaException(__METHOD__ . ' not implemented');
    }

    public function ack($delivery_tag, $flags = null)
    {
        $this->kafka->getConnection()['consumer']->commit([$delivery_tag]);
    }

    public function nack($delivery_tag, $flags = null)
    {
        throw new kafkaException(__METHOD__ . ' not implemented');
    }

    public function consume($callback, $flags = null, $consumerTag = null)
    {
        $this->kafka->consume([$this->topicName], $consumerTag, $callback);
    }

    public function isFailedConnection()
    {
    }
}