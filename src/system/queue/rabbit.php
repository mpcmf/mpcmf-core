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
     * Transaction status flag
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
            MPCMF_DEBUG && self::log()->addDebug("[{$key}] Initialize connection: " . json_encode($connectionData), [__METHOD__]);
            $this->connections[$key] = new \AMQPConnection($connectionData);
            $this->connections[$key]->connect();
        }

        return $this->connections[$key];
    }

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
        try {
            if (!$start && !$this->transactionStarted) {
                $this->getChannel()->startTransaction();
                $this->transactionStarted = true;
            }

            $result = $this->publishMessage($body, $queueName, $persistent, $queueType, $delay, $options);

            if ($start && $this->transactionStarted) {
                $this->runTasks();
            }

            return $result;
        } catch (\AMQPException $e) {
            if(strpos($e->getMessage(), 'No channel available') !== false) {
                self::log()->addWarning("Reconnecting to rabbit because of exception in publish: {$e->getMessage()}");
                $this->reconnect();
            }

            throw $e;
        }
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

        return $this->getExchange($queueName, $queueType)->publish($this->prepareBody($body), $queueName, AMQP_NOPARAM, $options);
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

        if ($contentTypeHeader === false) {
            $contentTypeHeader = $envelope->getHeader('content-type');
        }

        if ($contentTypeHeader === 'text/plain' || $contentTypeHeader === false) {
            // TODO: Remove this
            return $envelope->getBody();
        }

        $body = $envelope->getBody();
        list($contentType, $compressionType) = explode('/', $contentTypeHeader);

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

        return $this->getExchange($delayQueueName, self::EXCHANGE_TYPE_FANOUT)->publish(json_encode($body), $queueName, AMQP_NOPARAM, $options);
    }

    /**
     * @return bool
     *
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     */
    public function runTasks()
    {
        $status = false;
        if ($this->transactionStarted) {
            $status = $this->getChannel()->commitTransaction();
            $this->transactionStarted = false;
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
     *
     * @return \AMQPExchange
     * @throws \AMQPExchangeException
     * @throws \AMQPConnectionException
     * @throws \AMQPChannelException
     */
    protected function getExchange($queueName = null, $queueType = self::EXCHANGE_TYPE_DIRECT)
    {
        if(empty($queueName)) {
            $queueName = self::EXCHANGE_POINT;
        }

        /**
         * @var \AMQPExchange[] $exchanges
         */
        static $exchanges = [];

        $key = "{$this->configSection}:{$queueName}";
        if (!isset($exchanges[$key])) {
            MPCMF_DEBUG && self::log()->addDebug("[{$key}] Initialize exchange...", [__METHOD__]);
            $exchanges[$key] = new \AMQPExchange($this->getChannel());
            $exchanges[$key]->setName($this->getExchangeName($queueName, $queueType));
            $exchanges[$key]->setFlags(AMQP_DURABLE);

            $exchanges[$key]->setType($queueType);
            if ($queueType === self::EXCHANGE_TYPE_DELAYED) {
                $exchanges[$key]->setArgument('x-delayed-type', 'direct');
            }

            if($queueName !== self::EXCHANGE_POINT) {
                if (method_exists($exchanges[$key], 'declareExchange')) {
                    $exchanges[$key]->declareExchange();
                } else {
                    $exchanges[$key]->declare();
                }
            }
        }

        return $exchanges[$key];
    }

    protected function getExchangeName($queueName, $queueType = self::EXCHANGE_TYPE_DIRECT)
    {
        return APP_NAME . ".{$queueType}.{$queueName}";
    }

    /**
     * @param bool $force
     *
     * @return \AMQPChannel
     * @throws \AMQPConnectionException
     */
    protected function getChannel($force = false)
    {
        if(function_exists('posix_getpid')) {
            $pid = posix_getpid();
        } else {
            $pid = getmypid();
        }

        $key = "{$pid}:{$this->configSection}";

        if ($force || !isset($this->channels[$key])) {
            $this->channels[$key] = new \AMQPChannel($this->getConnection($force));
            $this->channels[$key]->setPrefetchCount(1);
        }

        return $this->channels[$key];
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