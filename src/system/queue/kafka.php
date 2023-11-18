<?php

namespace mpcmf\system\queue;

use mpcmf\system\helper\io\log;
use mpcmf\system\pattern\factory;
use mpcmf\system\queue\exceptions\kafkaException;
use mpcmf\system\queue\interfaces\streamInterface;
use RdKafka\Conf;
use RdKafka\Producer;

class kafka implements streamInterface
{
    use factory, log;

    const DEFAULT_BROKER = 'localhost:9092';

    /** @var array<string, array{consumer:\RdKafka\KafkaConsumer, producer:Producer}> $connections */
    protected $connections = [];

    protected $connectionData = [];

    public function setCompressionType($compressionType)
    {
        // TODO: Implement setCompressionType() method.
    }

    public function setCompressionLevel($compressionLevel)
    {
        // TODO: Implement setCompressionLevel() method.
    }

    public function setContentType($contentType)
    {
        // TODO: Implement setContentType() method.
    }

    protected function getConnectionData()
    {
        if (!isset($this->connectionData[$this->configSection])) {
            $config = $this->getPackageConfig();
            $this->connectionData[$this->configSection] = [
                'conf' => $config['conf'] ?? ['metadata.broker.list' => self::DEFAULT_BROKER],
                'flush_time_out_ms' => $config['flush_time_out_ms'] ?? 10000, //10 sec
                'flush_retries' => $config['flush_retries'] ?? 10,
                'topic_produce_partition' => $config['topic_produce_partition'] ?? RD_KAFKA_PARTITION_UA, //auto
                'block_produce_on_full' => $config['block_produce_on_full'] ?? RD_KAFKA_MSG_F_BLOCK, //use 0 for non block,
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

            $conf = new Conf();
            foreach ($connectionData['conf'] as $configKey => $value) {
                $conf->set($configKey, $value);
            }

            $this->connections[$key] = [
                //'consumer' => new \RdKafka\KafkaConsumer($conf),
                'consumer' => null,
                'producer' => new Producer($conf),
            ];
        }

        return $this->connections[$key];
    }

    public function reconnect()
    {
        // TODO: Implement reconnect() method.
    }

    public function sendToBackground($queueName, $body = null, $start = true, $persistent = true, $queueType = rabbit::EXCHANGE_TYPE_DIRECT, $delay = 0, $options = [])
    {
        //@TODO: how to convert headers?
        $this->produce($queueName, [$body], []);
    }

    public function getQueue($queueName, $queueType = rabbit::EXCHANGE_TYPE_DIRECT, $arguments = [])
    {
        return new kafkaTopicDriver($this, $queueName);
    }

    public function produce(string $topicName, array $payloads, array $headers = null)
    {
        $producer = $this->getConnection()['producer'];
        /** @var \RdKafka\ProducerTopic $topic */
        $topic = $producer->newTopic($topicName);
        foreach ($payloads as $payload) {
            $payload = is_array($payload) ? json_encode($payload) : (string)$payload;
            $topic->producev($this->connectionData[$this->configSection]['topic_produce_partition'], $this->connectionData[$this->configSection]['block_produce_on_full'], $payload, null, $headers);
            $producer->poll(0);
        }

        $flushRetries = $this->connectionData[$this->configSection]['flush_retries'];
        do {
            $flushResultCode = $producer->flush($this->connectionData[$this->configSection]['flush_time_out_ms']);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $flushResultCode) {
                return true;
            }
        } while (--$flushRetries);

        throw new kafkaException('Error on flushing', $flushResultCode);
    }

    public function consume(array $topicsNames, $groupId, callable $callback, $timeout = 5000, $autoOffsetReset = 'smallest')
    {
        $conf = new Conf();
        foreach ($this->getConnectionData()['conf'] as $key => $value) {
            $conf->set($key, $value);
        }
        $conf->set('group.id', $groupId);
        $conf->set('auto.offset.reset', $autoOffsetReset);
        $consumer = new \RdKafka\KafkaConsumer($conf);
        $consumer->subscribe($topicsNames);

        while (true) {
            $message = $consumer->consume($timeout);
            $callback($message);
        }
    }

}