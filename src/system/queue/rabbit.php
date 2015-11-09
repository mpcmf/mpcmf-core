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

    protected function getConnectionData()
    {
        static $connectionData = [];

        if (!isset($connectionData[$this->configSection])) {
            $config = $this->getPackageConfig();
            $connectionData[$this->configSection] = [
                'host' => isset($config['host']) ? $config['host'] : self::DEFAULT_HOST,
                'port' => isset($config['port']) ? $config['port'] : self::DEFAULT_PORT,
                'login' => isset($config['login']) ? $config['login'] : self::DEFAULT_LOGIN,
                'password' => isset($config['password']) ? $config['password'] : self::DEFAULT_PASSWORD,
            ];
        }

        return $connectionData[$this->configSection];
    }

    protected function getConnection()
    {
        /** @var \AMQPConnection[] $connections */
        static $connections = [];

        if(!isset($pid)) {
            if(function_exists('posix_getpid')) {
                $pid = posix_getpid();
            } else {
                $pid = getmypid();
            }
        }

        $key = "{$pid}:{$this->configSection}";
        if (!isset($connections[$key])) {
            $connectionData = $this->getConnectionData();
            MPCMF_DEBUG && self::log()->addDebug("[{$key}] Initialize connection: " . json_encode($connectionData), [__METHOD__]);
            $connections[$key] = new \AMQPConnection($connectionData);
            $connections[$key]->connect();
        }

        return $connections[$key];
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
        static $declaredQueues = [];

        $key = "{$this->configSection}:{$queueName}";
        if(!isset($declaredQueues[$key])) {
            $declaredQueues[$key] = true;
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
        if (!isset($curl)) {
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
        /**
         * @var \AMQPChannel[] $channels
         */
        static $channels = [];

        if(!isset($pid)) {
            if(function_exists('posix_getpid')) {
                $pid = posix_getpid();
            } else {
                $pid = getmypid();
            }
        }

        $key = "{$pid}:{$this->configSection}";
        if (!isset($channels[$key])) {
            MPCMF_DEBUG && self::log()->addDebug("[{$key}] Initialize channel...", [__METHOD__]);
            $channels[$key] = new \AMQPChannel($this->getConnection());
            $channels[$key]->setPrefetchCount(1);
        }

        return $channels[$key];
    }

    public function getQueue($queueName)
    {
        /** @var $queues \AMQPQueue[] */
        static $queues = [];

        if(function_exists('posix_getpid')) {
            $pid = posix_getpid();
        } else {
            $pid = getmypid();
        }

        $key = "{$pid}:{$this->configSection}:{$queueName}";
        if (!isset($queues[$key])) {
            MPCMF_DEBUG && self::log()->addDebug("[{$key}] Declaring queue: {$queueName}", [__METHOD__]);
            $queues[$key] = new \AMQPQueue($this->getChannel());
            $queues[$key]->setName($queueName);
            $queues[$key]->setFlags(AMQP_DURABLE);
            $queues[$key]->declare();
            $this->getExchange($queueName);
            $queues[$key]->bind(self::getExchangeName($queueName), $queueName);
        }

        return $queues[$key];
    }

    public function __destruct()
    {
        $this->runTasks();
        $this->getConnection()->disconnect();
    }
}