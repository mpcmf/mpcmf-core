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
    //@todo add fanout etc.

    const URL_LIST_QUEUES = 'http://%s:15672/api/queues';

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

    protected function getConnection()
    {
        $connectionData = $this->getConnectionData();
        $this->connection = new \AMQPConnection($connectionData);
        $this->connection->connect();

        return $this->connection;
    }

    public function sendToBackground($queueName, $body = null, $start = true, $persistent = true)
    {
        if (!$start && !$this->transactionStarted) {
            $this->getChannel()->startTransaction();
            $this->transactionStarted = true;
        }

        $result = $this->publishMessage($body, $queueName, $persistent);

        if ($start && $this->transactionStarted) {
            $this->runTasks();
        }

        return $result;
    }

    protected function publishMessage($body, $queueName, $persistent = false)
    {
        $key = "{$this->configSection}:{$queueName}";
        if(!isset($this->declaredQueues[$key])) {
            $this->declaredQueues[$key] = true;
            $this->getQueue($queueName);
        }

        $options = [];

        if($persistent) {
            $options['delivery_mode'] = self::MESSAGE_DELIVERY_PERSISTENT;
        }

        return $this->getExchange($queueName)->publish(json_encode($body), $queueName, AMQP_NOPARAM, $options);
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
        static $curl;
        if ($curl === null) {
            $curl = new curl();
        }

        $connectionData = $this->getConnectionData();

        try {
            $url = sprintf(self::URL_LIST_QUEUES, $connectionData['host']);
            $curl->reset();
            $curl->addOptions([
                CURLOPT_USERPWD => "{$connectionData['login']}:{$connectionData['password']}"
            ]);
            $curl->prepare($url);
            $content = $curl->execute();
            $result = json_decode($content, true);
        } catch (curlException $e) {
            MPCMF_DEBUG && self::log()->addDebug('[ERROR] Requesting rabbitmq queues list failed!', [__METHOD__]);
            MPCMF_DEBUG && self::log()->addDebug("[ERROR] ({$e->getCode()}) {$e->getMessage()}", [__METHOD__]);
            $result = [];
        }

        return $result;
    }

    /**
     * @param string|null $queueName
     * @param string $queueType
     *
     * @return \AMQPExchange
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
            $exchanges[$key]->setName(self::getExchangeName($queueName));
            $exchanges[$key]->setFlags(AMQP_DURABLE);

            $exchanges[$key]->setType($queueType);

            if($queueName !== self::EXCHANGE_POINT) {
                $exchanges[$key]->declare();
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
     */
    protected function getChannel()
    {
        if ($this->channel === null) {
            $this->channel = new \AMQPChannel($this->getConnection());
            $this->channel->setPrefetchCount(1);
        }

        return $this->channel;
    }

    public function getQueue($queueName)
    {
        if (!isset($this->queues[$queueName])) {
            MPCMF_DEBUG && self::log()->addDebug("[{$queueName}] Declaring queue: {$queueName}", [__METHOD__]);
            $this->queues[$queueName] = new \AMQPQueue($this->getChannel());
            $this->queues[$queueName]->setName($queueName);
            $this->queues[$queueName]->setFlags(AMQP_DURABLE);
            $this->queues[$queueName]->declare();
            $this->getExchange($queueName);
            $this->queues[$queueName]->bind(self::getExchangeName($queueName), $queueName);
        }

        return $this->queues[$queueName];
    }

    public function __destruct()
    {
        $this->runTasks();
        $this->getConnection()->disconnect();
    }
}