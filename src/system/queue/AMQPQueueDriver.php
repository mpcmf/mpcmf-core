<?php

namespace mpcmf\system\queue;

use mpcmf\system\helper\io\log;

class AMQPQueueDriver extends \AMQPQueue
{

    use log;

    protected $isFailedConnection = false;

    public function get($flags = AMQP_NOPARAM)
    {
        try {
            return parent::get($flags);
        } catch (\AMQPException $e) {
            if (strpos($e->getMessage(), 'No channel available') !== false) {
                self::log()->addWarning("queue connection error: {$e->getMessage()}");
                $this->isFailedConnection = true;
            }

            throw $e;
        }
    }

    public function isFailedConnection()
    {

        return $this->isFailedConnection;
    }
}