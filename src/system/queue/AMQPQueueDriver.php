<?php

namespace mpcmf\system\queue;

use mpcmf\system\helper\io\log;
use mpcmf\system\threads\gracefulStop;

class AMQPQueueDriver extends \AMQPQueue
{

    use log;

    /**
     * Seconds an idle consumer waits for a message before it checks for a stop request
     */
    const STOP_CHECK_INTERVAL = 1.0;

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

    /**
     * Consume messages, stopping gracefully on SIGTERM/SIGINT
     *
     * The callback runs in a critical section of gracefulStop: a stop signal during it is deferred, the message
     * is processed and acked, then the process exits (or, with gracefulStop::enable(), the consumer is cancelled
     * and consume() returns). An idle consumer wakes up every STOP_CHECK_INTERVAL seconds to notice a stop:
     * a blocking wait of ext-amqp is not interrupted by signals. A configured read_timeout keeps working,
     * the callback runs with it. Messages prefetched and not processed are redelivered by the broker.
     *
     * @param callable|null $callback
     * @param int|null      $flags
     * @param string|null   $consumerTag
     *
     * @throws \AMQPException
     * @noinspection PhpSignatureMismatchDuringInheritanceInspection invalid signature in phpstorm amqp stub
     */
    public function consume(?callable $callback = null, ?int $flags = null, ?string $consumerTag = null): void
    {
        try {

            if ($callback === null) {
                parent::consume($callback, $flags, $consumerTag);
            } else {
                $this->consumeUntilStop($callback, $flags, $consumerTag);
            }
        } catch (\AMQPException $e) {
            $this->handleConnectionError($e, 'consume');

            throw $e;
        }
    }

    /**
     * @param callable    $callback
     * @param int|null    $flags
     * @param string|null $consumerTag
     *
     * @throws \AMQPException
     */
    protected function consumeUntilStop(callable $callback, $flags, $consumerTag)
    {
        gracefulStop::checkpoint();
        if (gracefulStop::isRequested()) {

            return;
        }

        $connection = $this->getConnection();
        $readTimeout = (float)$connection->getReadTimeout();
        $checkTimeout = $readTimeout > 0 ? min($readTimeout, self::STOP_CHECK_INTERVAL) : self::STOP_CHECK_INTERVAL;
        $autoAck = ((int)$flags & AMQP_AUTOACK) !== 0;
        $idleSince = microtime(true);
        $stopped = false;
        // signals are dispatched explicitly while waiting: an async handler due when consume() throws the timeout
        // exception is called with the exception pending, and PHP drops such a call
        $async = pcntl_async_signals();

        $handler = function (\AMQPEnvelope $envelope, \AMQPQueue $queue) use ($callback, $connection, $readTimeout, $checkTimeout, $autoAck, $async, &$idleSince, &$stopped) {
            pcntl_async_signals($async);
            try {
                if (gracefulStop::isRequested()) {
                    // the stop came with the message: give it back, unless the broker considers it acked already
                    if (!$autoAck) {
                        $queue->nack($envelope->getDeliveryTag(), AMQP_REQUEUE);
                    }
                    $stopped = true;

                    return false;
                }
                // the socket read timeout applies to requests of the callback too
                $connection->setReadTimeout($readTimeout);
                $result = gracefulStop::critical(function () use ($callback, $envelope, $queue) {

                    return call_user_func($callback, $envelope, $queue);
                });
                $idleSince = microtime(true);
                if ($result !== false && gracefulStop::isRequested()) {
                    $stopped = true;
                    $result = false;
                }
                $connection->setReadTimeout($checkTimeout);

                return $result;
            } finally {
                pcntl_async_signals(false);
            }
        };

        $connection->setReadTimeout($checkTimeout);
        pcntl_async_signals(false);
        try {
            while (true) {
                try {
                    parent::consume($handler, $flags, $consumerTag);

                    break;
                } catch (\AMQPQueueException $e) {
                    if (stripos($e->getMessage(), 'timeout') === false) {

                        throw $e;
                    }
                }
                // idle: handle a stop signal received while waiting
                gracefulStop::checkpoint();
                if (gracefulStop::isRequested()) {
                    $stopped = true;

                    break;
                }
                $idle = microtime(true) - $idleSince;
                if ($readTimeout > 0 && $idle >= $readTimeout - 0.01) {

                    throw $e;
                }
                if ($readTimeout > 0) {
                    $connection->setReadTimeout(min($checkTimeout, $readTimeout - $idle));
                }
                // the consumer is subscribed already, only wait for messages
                $flags = (int)$flags | AMQP_JUST_CONSUME;
            }
        } finally {
            pcntl_async_signals($async);
            $connection->setReadTimeout($readTimeout);
        }

        if ($stopped) {
            // no more deliveries; prefetched messages are redelivered when the channel is closed
            $this->cancel((string)$this->getConsumerTag());
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
