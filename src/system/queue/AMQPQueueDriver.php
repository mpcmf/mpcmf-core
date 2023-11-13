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

    public function ack($delivery_tag, $flags = null)
    {
        try {

            return parent::ack($delivery_tag, $flags);
        } catch (\AMQPException $e) {
            $m = $e->getMessage();
            if ($m === 'Could not ack message. No channel available.') {
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

    public function nack($delivery_tag, $flags = null)
    {
        try {

            return parent::nack($delivery_tag, $flags);
        } catch (\AMQPException $e) {
            $m = $e->getMessage();
            if ($m === 'Could not nack message. No channel available.') {
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

    //@NOTE: invalid signature in phpstorm amqp stub
    /** @noinspection PhpSignatureMismatchDuringInheritanceInspection */
    public function consume($callback, $flags = null, $consumerTag = null)
    {
        try {

            parent::consume($callback, $flags, $consumerTag);
        } catch (\AMQPException $e) {
            $m = $e->getMessage();
            if ($m === 'Could not get channel. No channel available.') {
                if ($this->failedConectionCounter++ >= 3) {
                    self::log()->addError("non-recoverable consume queue connection error, exiting [{$this->failedConectionCounter}]: {$m}");

                    exit;
                }
                self::log()->addWarning("consume queue connection error [{$this->failedConectionCounter}]: {$m}");
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