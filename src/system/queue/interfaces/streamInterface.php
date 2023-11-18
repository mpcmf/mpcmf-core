<?php

namespace mpcmf\system\queue\interfaces;

use mpcmf\system\queue\rabbit;

interface streamInterface
{

    public function setCompressionType($compressionType);

    public function setCompressionLevel($compressionLevel);

    public function setContentType($contentType);

    public function getConnection($force = false, $init = true);

    public function reconnect();

    public function sendToBackground($queueName, $body = null, $start = true, $persistent = true, $queueType = rabbit::EXCHANGE_TYPE_DIRECT, $delay = 0, $options = []);

    public function getQueue($queueName, $queueType = rabbit::EXCHANGE_TYPE_DIRECT, $arguments = []);
}