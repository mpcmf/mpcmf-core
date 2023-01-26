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
            $m = $e->getMessage();
            if ($m === 'Could not get messages from queue. No channel available.') {
                if ($this->failedConectionCounter++ >= 3) {
                    self::log()->addError("non-recoverable queue connection error, exiting [{$this->failedConectionCounter}]: {$m}");

                    exit;
                }
                self::log()->addWarning("queue connection error [{$this->failedConectionCounter}]: {$m}");
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