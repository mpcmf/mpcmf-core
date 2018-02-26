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

    const URL_LIST_QUEUES = 'queues';
    const URL_API_BASE = 'http://%s:15672/api/';

    const MESSAGE_DELIVERY_PERSISTENT = 2;

    /**
     * Transaction status flag
     *
     * @var bool
     */
    protected $transactionStarted = false;

    private $connectionData = [];
    private $declaredQueues = [];
    /**
     * @var \AMQPQueue[]
     */
    private $queues = [];
    private $connection;
    /**
     * @var \AMQPChannel
     */
    private $channel;

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

    public function getConnection()
    {
        $connectionData = $this->getConnectionData();
        $this->connection = new \AMQPConnection($connectionData);
        $this->connection->connect();

        return $this->connection;
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
     */
    public function sendToBackground($queueName, $body = null, $start = true, $persistent = true, $queueType = self::EXCHANGE_TYPE_DIRECT, $delay = 0, $options = [])
    {
        if (!$start && !$this->transactionStarted) {
            $this->getChannel()->startTransaction();
            $this->transactionStarted = true;
        }

        $result = $this->publishMessage($body, $queueName, $persistent, $queueType, $delay, $options);

        if ($start && $this->transactionStarted) {
            $this->runTasks();
        }

        return $result;
    }

    protected function publishMessage($body, $queueName, $persistent = false, $queueType = self::EXCHANGE_TYPE_DIRECT, $delay = 0, $options = [])
    {
        if ($queueType === self::EXCHANGE_TYPE_DEAD_LETTER) {
            return $this->deadLetterPublish($body, $queueName, $persistent, $delay);
        }

        $key = "{$this->configSection}:{$queueName}";
        if(!isset($this->declaredQueues[$key])) {
            $this->declaredQueues[$key] = true;
            $this->getQueue($queueName, $queueType);
        }
        
        if($persistent) {
            $options['delivery_mode'] = self::MESSAGE_DELIVERY_PERSISTENT;
        }

        if ($queueType === self::EXCHANGE_TYPE_DELAYED) {
            $options['x-delay'] = $delay;
        }

        return $this->getExchange($queueName, $queueType)->publish(json_encode($body), $queueName, AMQP_NOPARAM, $options);
    }

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
     * @return \AMQPChannel
     * @throws \AMQPConnectionException
     */
    protected function getChannel()
    {
        if ($this->channel === null) {
            $this->channel = new \AMQPChannel($this->getConnection());
            $this->channel->setPrefetchCount(1);
        }

        return $this->channel;
    }

    public function getQueue($queueName, $queueType = self::EXCHANGE_TYPE_DIRECT, $arguments = [])
    {
        if (!isset($this->queues[$queueName])) {
            MPCMF_DEBUG && self::log()->addDebug("[{$queueName}] Declaring queue: {$queueName}", [__METHOD__]);
            $this->queues[$queueName] = new \AMQPQueue($this->getChannel());
            $this->queues[$queueName]->setName($queueName);
            $this->queues[$queueName]->setFlags(AMQP_DURABLE);
            if (count($arguments) > 0) {
                $this->queues[$queueName]->setArguments($arguments);
            }

            if (method_exists($this->queues[$queueName], 'declareQueue')) {
                $this->queues[$queueName]->declareQueue();
            } else {
                $this->queues[$queueName]->declare();
            }
            $this->getExchange($queueName, $queueType);
            $this->queues[$queueName]->bind($this->getExchangeName($queueName, $queueType), $queueName);
        }

        return $this->queues[$queueName];
    }

    public function __destruct()
    {
        $this->runTasks();
        $this->getConnection()->disconnect();
    }
}