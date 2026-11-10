<?php

namespace mpcmf\system\queue;

use mpcmf\system\exceptions\curlException;
use mpcmf\system\helper\io\log;
use mpcmf\system\net\curl;
use mpcmf\system\pattern\factory;

/**
 * Class rabbitMQ
 *
 * @package rabbitMq\lib
 * @author Borovikov Maxim <maxim.mahi@gmail.com>
 * @author Ostrovsky Gregory <greevex@gmail.com>
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class rabbit
{
    use factory, log;

    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_PORT = 5672;
    const DEFAULT_LOGIN = 'guest';
    const DEFAULT_PASSWORD = 'guest';

    const OPTIONAL_CONNECTION_PARAMS = [
        'vhost',
        'heartbeat',
        'connect_timeout',
        'read_timeout',
        'write_timeout',
        'rpc_timeout',
    ];

    const EXCHANGE_POINT = 'amq.direct';

    const EXCHANGE_TYPE_DIRECT = 'direct';
    const EXCHANGE_TYPE_FANOUT = 'fanout';
    const EXCHANGE_TYPE_DELAYED = 'x-delayed-message';
    const EXCHANGE_TYPE_DEAD_LETTER = 'dead-letter';

    const COMPRESSION_TYPE__GZIP = 'gzip';
    const COMPRESSION_TYPE__RAW = 'raw';

    const CONTENT_TYPE__JSON = 'json';
    const CONTENT_TYPE__PLAIN = 'text';

    const HEADER_CONTENT_TYPE = 'content_type';

    const URL_LIST_QUEUES = 'queues';
    const URL_API_BASE = 'http://%s:15672/api/';

    const MESSAGE_DELIVERY_PERSISTENT = 2;

    /**
     * Transaction status flag: the transactional channel has publishes not committed yet
     *
     * @var bool
     */
    protected $transactionStarted = false;

    protected $compressionType = self::COMPRESSION_TYPE__RAW;
    protected $compressionLevel = -1;

    protected $contentType = self::CONTENT_TYPE__JSON;

    private $connectionData = [];
    private $declaredQueues = [];

    /**
     * Process the per-process state (declared queues, transaction) belongs to
     *
     * @var int|null
     */
    private $statePid;
    /**
     * @var \AMQPQueue[]
     */
    private $queues = [];

    /**
     * @var \AMQPConnection[]
     */
    private $connections;
    /**
     * @var \AMQPChannel[]
     */
    private $channels;
    /**
     * Channels in transactional mode (tx.select), one per process and section, created on the first transactional publish:
     * tx.select cannot be undone on a channel, so plain publishes never go through it
     *
     * @var \AMQPChannel[]
     */
    private $transactionChannels = [];
    /**
     * Exchanges by process, section, queue, type and channel kind; an exchange object is bound to the channel it was created on
     *
     * @var \AMQPExchange[]
     */
    private $exchanges = [];
    /**
     * The publish in progress goes to the transactional channel
     *
     * @var bool
     */
    private $publishToTransaction = false;
    /**
     * Publishes of the open transaction on the current transactional channel
     *
     * @var int
     */
    private $transactionPublishes = 0;
    /**
     * Publishes of the open transaction rolled back by the broker with a lost transactional channel
     *
     * @var int
     */
    private $transactionLost = 0;

    public function setCompressionType($compressionType)
    {
        $this->compressionType = $compressionType;
    }

    public function setCompressionLevel($compressionLevel)
    {
        $this->compressionLevel = $compressionLevel;
    }

    public function setContentType($contentType)
    {
        $this->contentType = $contentType;
    }

    protected function getConnectionData()
    {
        if (!isset($this->connectionData[$this->configSection])) {
            $config = $this->getPackageConfig();
            $this->connectionData[$this->configSection] = [
                'host' => isset($config['host']) ? $config['host'] : self::DEFAULT_HOST,
                'port' => isset($config['port']) ? $config['port'] : self::DEFAULT_PORT,
                'login' => isset($config['login']) ? $config['login'] : self::DEFAULT_LOGIN,
                'password' => isset($config['password']) ? $config['password'] : self::DEFAULT_PASSWORD,
            ];
            // passed to AMQPConnection only when configured, ext-amqp defaults apply otherwise
            foreach (self::OPTIONAL_CONNECTION_PARAMS as $param) {
                if (isset($config[$param])) {
                    $this->connectionData[$this->configSection][$param] = $config[$param];
                }
            }
        }

        return $this->connectionData[$this->configSection];
    }

    public function getConnection($force = false, $init = true)
    {
        if(function_exists('posix_getpid')) {
            $pid = posix_getpid();
        } else {
            $pid = getmypid();
        }

        $key = "{$pid}:{$this->configSection}";
        if ($init === false) {
            if (!isset($this->connections[$key])) {

                return null;
            }
            return $this->connections[$key];
        }


        if ($force || !isset($this->connections[$key])) {
            $connectionData = $this->getConnectionData();
            MPCMF_DEBUG && self::log()->addDebug("[{$key}] Initialize connection: " . json_encode(array_merge($connectionData, ['password' => '***'])), [__METHOD__]);
            $this->connections[$key] = new \AMQPConnection($connectionData);
            $this->connections[$key]->connect();
        }

        return $this->connections[$key];
    }

    /**
     * Reconnect the connection of the current process; both channels (plain and transactional) are reopened on next use.
     * Publishes of an open transaction are rolled back by the broker: runTasks() throws, see there
     */
    public function reconnect()
    {
        $this->getConnection()->disconnect();
        $this->getConnection()->connect();
    }

    /**
     * @param string $queueName
     * @param mixed $body
     * @param bool $start
     * @param bool $persistent
     * @param string $queueType
     * @param int $delay In seconds
     * @param array $options
     *
     * @return bool
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     */
    public function sendToBackground($queueName, $body = null, $start = true, $persistent = true, $queueType = self::EXCHANGE_TYPE_DIRECT, $delay = 0, $options = [])
    {
        $this->checkProcessState();
        // $start=false adds the message to the transaction, $start=true with an open transaction adds it and commits;
        // a transaction lives on its own channel, plain publishes go through the non-transactional one
        $transactional = !$start || $this->transactionStarted;
        if ($transactional) {
            $this->getTransactionChannel();
            $this->transactionStarted = true;
        }

        $this->publishToTransaction = $transactional;
        try {
            $result = $this->publishMessage($body, $queueName, $persistent, $queueType, $delay, $options);
        } finally {
            $this->publishToTransaction = false;
        }
        if ($transactional) {
            $this->transactionPublishes++;
        }

        if ($start && $this->transactionStarted) {
            $this->runTasks();
        }

        return $result;
    }

    /**
     * @param $body
     * @param $queueName
     * @param bool $persistent
     * @param string $queueType
     * @param int $delay
     * @param array $queueOptions
     *
     * @return bool
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     */
    protected function publishMessage($body, $queueName, $persistent = false, $queueType = self::EXCHANGE_TYPE_DIRECT, $delay = 0, $queueOptions = [])
    {
        if ($queueType === self::EXCHANGE_TYPE_DEAD_LETTER) {
            return $this->deadLetterPublish($body, $queueName, $persistent, $delay);
        }

        $key = "{$this->configSection}:{$queueName}";
        if(!isset($this->declaredQueues[$key])) {
            $this->declaredQueues[$key] = true;
            $this->getQueue($queueName, $queueType, $queueOptions);
        }

        $options = [
            'headers' => [
                'content-type' => "{$this->contentType}/{$this->compressionType}", // Todo: remove this, after implement right header in all subsystems
                self::HEADER_CONTENT_TYPE => "{$this->contentType}/{$this->compressionType}"
            ],
        ];
        if($persistent) {
            $options['delivery_mode'] = self::MESSAGE_DELIVERY_PERSISTENT;
        }

        if ($queueType === self::EXCHANGE_TYPE_DELAYED) {
            $options['x-delay'] = $delay;
        }

        try {

            // ext-amqp 2.x: publish() returns void and throws on failure, 1.x returned true
            $this->getExchange($queueName, $queueType)->publish($this->prepareBody($body), $queueName, AMQP_NOPARAM, $options);

            return true;
        } catch (\AMQPException $e) {
            $m = $e->getMessage();
            if($m === 'Could not publish to exchange. No channel available.') {
                self::log()->addWarning("Reconnecting to rabbit because of exception in publish: {$e->getMessage()}");

                $this->getExchange($queueName, $queueType, true)->publish($this->prepareBody($body), $queueName, AMQP_NOPARAM, $options);

                return true;
            }

            throw $e;
        }
    }

    protected function prepareBody($body)
    {
        switch ($this->contentType) {
            case self::CONTENT_TYPE__JSON:
                $body = json_encode($body);
                break;
            case self::CONTENT_TYPE__PLAIN:
            default:
                break;
        }

        switch ($this->compressionType) {
            case self::COMPRESSION_TYPE__GZIP:
                $body = gzcompress($body, $this->compressionLevel);
                break;
            case self::COMPRESSION_TYPE__RAW:
            default:
                break;
        }

        return $body;
    }

    public static function getBody(\AMQPEnvelope $envelope)
    {
        $contentTypeHeader = $envelope->getHeader(self::HEADER_CONTENT_TYPE);

        // ext-amqp 2.x returns null for a missing header, 1.x returned false
        if ($contentTypeHeader === false || $contentTypeHeader === null) {
            $contentTypeHeader = $envelope->getHeader('content-type');
        }

        if ($contentTypeHeader === 'text/plain' || $contentTypeHeader === false || $contentTypeHeader === null) {
            // TODO: Remove this
            return $envelope->getBody();
        }

        $body = $envelope->getBody();
        [$contentType, $compressionType] = explode('/', $contentTypeHeader);

        switch ($compressionType) {
            case self::COMPRESSION_TYPE__GZIP:
                $body = gzuncompress($body);
                break;
            case self::COMPRESSION_TYPE__RAW:
            default:
                break;
        }

        switch ($contentType) {
            case self::CONTENT_TYPE__JSON:
                $body = json_decode($body, true);
                break;
            case self::CONTENT_TYPE__PLAIN:
            default:
                break;
        }

        return $body;
    }

    /**
     * @param $body
     * @param $queueName
     * @param bool $persistent
     * @param int $delay
     *
     * @return bool
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     */
    protected function deadLetterPublish($body, $queueName, $persistent = false, $delay = 0)
    {
        $delayQueueName = "{$queueName}_{$delay}";

        $key = "{$this->configSection}:{$delayQueueName}";
        if(!isset($this->declaredQueues[$key])) {
            $this->declaredQueues[$key] = true;
            $this->getQueue($delayQueueName, self::EXCHANGE_TYPE_FANOUT, ['x-dead-letter-exchange' => $this->getExchangeName($queueName)]);
        }

        $key = "{$this->configSection}:{$queueName}";
        if(!isset($this->declaredQueues[$key])) {
            $this->declaredQueues[$key] = true;
            $this->getQueue($queueName);
        }

        $options = ['expiration' => $delay * 1000];
        if($persistent) {
            $options['delivery_mode'] = self::MESSAGE_DELIVERY_PERSISTENT;
        }

        $this->getExchange($delayQueueName, self::EXCHANGE_TYPE_FANOUT)->publish(json_encode($body), $queueName, AMQP_NOPARAM, $options);

        return true;
    }

    /**
     * Commit the transaction
     *
     * If the transactional channel was lost after the transaction got publishes (connection or channel error, reconnect()),
     * the broker rolled them back: the rest of the transaction is rolled back too and an exception is thrown,
     * so the caller can repeat the whole batch. A failed commit ends the transaction as well (its exception is thrown,
     * the outcome is unknown): the next publish starts a new one.
     *
     * @return bool true after commit, false if there is no transaction
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     */
    public function runTasks()
    {
        $this->checkProcessState();
        $status = false;
        if ($this->transactionStarted) {
            $channel = $this->getTransactionChannel();
            if ($this->transactionLost > 0) {
                $lost = $this->transactionLost;
                $total = $lost + $this->transactionPublishes;
                $this->transactionStarted = false;
                $this->transactionPublishes = 0;
                $this->transactionLost = 0;
                $channel->rollbackTransaction();

                throw new \AMQPChannelException("Transaction lost: the transactional channel was closed, {$lost} of {$total} messages were rolled back by the broker, the rest is rolled back");
            }
            // the transaction ends with the commit attempt: a failed commit is not retried, the exception goes to the caller
            $this->transactionStarted = false;
            $this->transactionPublishes = 0;
            // ext-amqp 2.x: commitTransaction() returns void and throws on failure, 1.x returned true
            $channel->commitTransaction();
            $status = true;
        }

        return $status;
    }

    public function getQueuesList()
    {
        return $this->callApi(self::URL_LIST_QUEUES);
    }

    public function callApi($path, $params = [], $method = 'GET')
    {
        static $curl;
        if ($curl === null) {
            $curl = new curl();
        }

        $connectionData = $this->getConnectionData();

        try {
            $url = sprintf(self::URL_API_BASE, $connectionData['host']) . $path;
            $curl->reset();
            $curl->addOptions([
                CURLOPT_USERPWD => "{$connectionData['login']}:{$connectionData['password']}"
            ]);
            $curl->prepare($url, $params, $method);
            $content = $curl->execute();
            $result = json_decode($content, true);
        } catch (curlException $e) {
            MPCMF_DEBUG && self::log()->addDebug('[ERROR] Requesting rabbitmq API failed!', [__METHOD__]);
            MPCMF_DEBUG && self::log()->addDebug("[ERROR] ({$e->getCode()}) {$e->getMessage()}", [__METHOD__]);
            $result = [];
        }

        return $result;

    }

    /**
     * @param string $queueName
     * @param string $queueType
     * @param bool   $force
     *
     * @return \AMQPExchange
     * @throws \AMQPExchangeException
     * @throws \AMQPConnectionException
     * @throws \AMQPChannelException
     */
    protected function getExchange($queueName = null, $queueType = self::EXCHANGE_TYPE_DIRECT, $force = false)
    {
        if(empty($queueName)) {
            $queueName = self::EXCHANGE_POINT;
        }

        // per process: an exchange object holds the channel of the process that created it;
        // per instance and channel kind: the exchange publishes through the channel it was created on
        $transactional = $this->publishToTransaction;
        $key = getmypid() . ":{$this->configSection}:{$queueName}:{$queueType}:" . ($transactional ? 'tx' : 'plain');
        if (!$force && isset($this->exchanges[$key])) {
            // the channel is recreated after a connection or channel loss: the exchange follows it
            $channel = $transactional ? $this->getTransactionChannel() : $this->getChannel();
            if ($this->exchanges[$key]->getChannel() === $channel) {

                return $this->exchanges[$key];
            }
        }

        MPCMF_DEBUG && self::log()->addDebug("[{$key}] Initialize exchange...", [__METHOD__]);
        try {
            $exchange = new \AMQPExchange($transactional ? $this->getTransactionChannel($force) : $this->getChannel($force));
        } catch (\AMQPException $e) {
            $m = $e->getMessage();
            if($m !== 'Could not create exchange. No channel available.') {
                throw $e;
            }
            self::log()->addWarning("Reconnecting to rabbit because of exception in exchange: {$e->getMessage()}");
            $exchange = new \AMQPExchange($transactional ? $this->getTransactionChannel(true) : $this->getChannel(true));
        }
        $this->exchanges[$key] = $exchange;
        $exchange->setName($this->getExchangeName($queueName, $queueType));
        $exchange->setFlags(AMQP_DURABLE);

        $exchange->setType($queueType);
        if ($queueType === self::EXCHANGE_TYPE_DELAYED) {
            $exchange->setArgument('x-delayed-type', 'direct');
        }

        if($queueName !== self::EXCHANGE_POINT) {
            if (method_exists($exchange, 'declareExchange')) {
                $exchange->declareExchange();
            } else {
                $exchange->declare();
            }
        }

        return $exchange;
    }

    /**
     * Drop state inherited from the parent process after fork: its queues were declared and its transaction
     * was started on the parent connection
     */
    protected function checkProcessState()
    {
        $pid = getmypid();
        if ($this->statePid === $pid) {

            return;
        }

        if ($this->statePid !== null) {
            $this->declaredQueues = [];
            $this->transactionStarted = false;
            $this->transactionPublishes = 0;
            $this->transactionLost = 0;
        }
        $this->statePid = $pid;
    }

    protected function getExchangeName($queueName, $queueType = self::EXCHANGE_TYPE_DIRECT)
    {
        return APP_NAME . ".{$queueType}.{$queueName}";
    }

    /**
     * Non-transactional channel: consumers, declarations and plain publishes
     *
     * @param bool $force
     *
     * @return \AMQPChannel
     * @throws \AMQPConnectionException
     */
    protected function getChannel($force = false)
    {
        return $this->openChannel($this->channels, $force, false);
    }

    /**
     * Transactional channel (tx.select is sent on creation) of the current process: publishes with $start=false and the commit
     *
     * @param bool $force
     *
     * @return \AMQPChannel
     * @throws \AMQPConnectionException
     * @throws \AMQPChannelException
     */
    protected function getTransactionChannel($force = false)
    {
        return $this->openChannel($this->transactionChannels, $force, true);
    }

    /**
     * @param \AMQPChannel[]|null $channels
     * @param bool               $force
     * @param bool               $transactional
     *
     * @return \AMQPChannel
     * @throws \AMQPConnectionException
     * @throws \AMQPChannelException
     */
    protected function openChannel(&$channels, $force, $transactional)
    {
        if(function_exists('posix_getpid')) {
            $pid = posix_getpid();
        } else {
            $pid = getmypid();
        }

        $key = "{$pid}:{$this->configSection}";

        $current = isset($channels[$key]) ? $channels[$key] : null;
        if (!$force && $current !== null) {
            if (!$current->isConnected()) {
                // closed by a channel or connection error, or by reconnect()
                MPCMF_DEBUG && self::log()->addDebug("[{$key}] Channel is closed, opening a new one", [__METHOD__]);
            } elseif ($current->getConnection() !== $this->getConnection(false, false) && !($transactional && $this->transactionPublishes > 0)) {
                // the connection was replaced by a reconnect of another channel: move to it (an open transaction stays on its channel)
                MPCMF_DEBUG && self::log()->addDebug("[{$key}] Connection replaced, opening a new channel on it", [__METHOD__]);
            } else {

                return $current;
            }
        }

        if ($transactional && $current !== null && $this->transactionPublishes > 0) {
            // the broker rolls back the transaction of a closed channel: runTasks() reports it
            $this->transactionLost += $this->transactionPublishes;
            $this->transactionPublishes = 0;
            self::log()->addWarning("[{$key}] Transactional channel replaced, {$this->transactionLost} uncommitted messages are lost");
        }

        try {
            $channel = new \AMQPChannel($this->getConnection($force));
        } catch (\AMQPException $e) {
            $m = $e->getMessage();
            if($m !== 'Could not create channel. No connection available.') {
                throw $e;
            }
            self::log()->addWarning("Reconnecting to rabbit because of exception in channel: {$e->getMessage()}");
            $channel = new \AMQPChannel($this->getConnection(true));
        }
        $channels[$key] = $channel;
        if ($transactional) {
            $channel->startTransaction();
        } else {
            $channel->setPrefetchCount(1);
        }

        return $channel;
    }

    /**
     * @param $queueName
     * @param string $queueType
     * @param array $arguments
     *
     * @return \AMQPQueue
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     */
    public function getQueue($queueName, $queueType = self::EXCHANGE_TYPE_DIRECT, $arguments = [])
    {
        if(function_exists('posix_getpid')) {
            $pid = posix_getpid();
        } else {
            $pid = getmypid();
        }

        $queueKey = "{$pid}:{$this->configSection}:{$queueName}:{$queueType}";

        $isFailedConnection = false;
        if(isset($this->queues[$queueKey])) {
            $isFailedConnection = $this->queues[$queueKey]->isFailedConnection();
            if(!$isFailedConnection) {

                return $this->queues[$queueKey];
            }
        }

        MPCMF_DEBUG && self::log()->addDebug("[{$queueName}] Declaring queue: {$queueName}", [__METHOD__]);
        $this->queues[$queueKey] = new AMQPQueueDriver($this->getChannel($isFailedConnection));
        $this->queues[$queueKey]->setName($queueName);
        $this->queues[$queueKey]->setFlags(AMQP_DURABLE);
        if (count($arguments) > 0) {
            $this->queues[$queueKey]->setArguments($arguments);
        }

        if (method_exists($this->queues[$queueKey], 'declareQueue')) {
            $this->queues[$queueKey]->declareQueue();
        } else {
            $this->queues[$queueKey]->declare();
        }
        $this->getExchange($queueName, $queueType);
        $this->queues[$queueKey]->bind($this->getExchangeName($queueName, $queueType), $queueName);

        return $this->queues[$queueKey];
    }

    public function __destruct()
    {
        $this->runTasks();
        $connection  = $this->getConnection(false, false);
        if ($connection !== null) {
            $connection->disconnect();
        }
    }
}