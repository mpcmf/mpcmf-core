<?php

namespace mpcmf\system\queue;

use mpcmf\system\helper\io\log;

class AMQPQueueDriver extends \AMQPQueue
{

    use log;

    protected $isFailedConnection = false;
    protected $failedConectionCounter = 0;

    public function get(?int $flags = AMQP_NOPARAM): ?\AMQPEnvelope
    {
        try {

            return parent::get($flags);
        } catch (\AMQPException $e) {
            $this->handleConnectionError($e, 'get');

            throw $e;
        }
    }

    public function ack(int $deliveryTag, ?int $flags = null): void
    {
        try {

            parent::ack($deliveryTag, $flags);
        } catch (\AMQPException $e) {
            $this->handleConnectionError($e, 'ack');

            throw $e;
        }
    }

    public function nack(int $deliveryTag, ?int $flags = null): void
    {
        try {

            parent::nack($deliveryTag, $flags);
        } catch (\AMQPException $e) {
            $this->handleConnectionError($e, 'nack');

            throw $e;
        }
    }

    //@NOTE: invalid signature in phpstorm amqp stub
    /** @noinspection PhpSignatureMismatchDuringInheritanceInspection */
    public function consume(?callable $callback = null, ?int $flags = null, ?string $consumerTag = null): void
    {
        try {

            parent::consume($callback, $flags, $consumerTag);
        } catch (\AMQPException $e) {
            $this->handleConnectionError($e, 'consume');

            throw $e;
        }
    }

    public function isFailedConnection()
    {

        return $this->isFailedConnection;
    }

    /**
     * Mark the queue for reconnect when the exception means a lost channel or connection
     *
     * @param \AMQPException $e
     * @param string         $operation
     */
    protected function handleConnectionError(\AMQPException $e, $operation)
    {
        if (!$this->isConnectionLost($e)) {

            return;
        }

        $m = $e->getMessage();
        if ($this->failedConectionCounter++ >= 3) {
            self::log()->addError("non-recoverable {$operation} queue connection error, exiting [{$this->failedConectionCounter}]: {$m}");

            exit(1);
        }
        self::log()->addWarning("{$operation} queue connection error [{$this->failedConectionCounter}]: {$m}");
        $this->isFailedConnection = true;
    }

    /**
     * The first error after a drop has no fixed text (CONNECTION_FORCED, Channel mismatch, empty on heartbeat timeout),
     * so the channel and connection state is checked, not only the message
     *
     * @param \AMQPException $e
     *
     * @return bool
     */
    protected function isConnectionLost(\AMQPException $e)
    {
        if (strpos($e->getMessage(), 'No channel available.') !== false) {

            return true;
        }

        try {

            return !$this->getChannel()->isConnected() || !$this->getConnection()->isConnected();
        } catch (\Throwable $stateException) {

            return true;
        }
    }
}
