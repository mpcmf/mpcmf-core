<?php

namespace mpcmf\system\queue;

use mpcmf\system\helper\io\log;

class AMQPQueueDriver extends \AMQPQueue
{

    use log;

    protected $isFailedConnection = false;
    protected $failedConectionCounter = 0;

    public function get($flags = AMQP_NOPARAM)
    {
        try {
            return parent::get($flags);
        } catch (\AMQPException $e) {
            if (strpos($e->getMessage(), 'No channel available') !== false) {
                $this->failedConectionCounter++;
                self::log()->addWarning("queue connection error [{$this->failedConectionCounter}]: {$e->getMessage()}");
                $this->isFailedConnection = true;
            }
            if($this->failedConectionCounter > 3) {
                exit;
            }

            throw $e;
        }
    }

    public function isFailedConnection()
    {

        return $this->isFailedConnection;
    }
}