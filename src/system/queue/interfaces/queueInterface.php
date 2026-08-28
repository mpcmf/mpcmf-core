<?php

namespace mpcmf\system\queue\interfaces;

interface queueInterface
{

    public function get($flags = 0);

    public function ack($delivery_tag, $flags = null);

    public function nack($delivery_tag, $flags = null);

    public function consume($callback, $flags = null, $consumerTag = null);
}