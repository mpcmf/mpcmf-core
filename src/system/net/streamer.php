<?php

namespace mpcmf\system\net;

use mpcmf\system\helper\io\log;
use mpcmf\system\queue\rabbit;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Gearman worker class
 */
class streamer
{
    use log;

    private $config;
    private $pause;
    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var socketStreamer
     */
    protected $server;
    protected static $queue = [];
    protected static $shutDownData = [];

    private $nextPauseCheckTime = 0;
    private $queueSize = 0;

    public function __construct($config)
    {
        $onShutdown = function() {
            self::onShutdown();
        };
        register_shutdown_function($onShutdown);
        pcntl_signal(SIGTERM, $onShutdown);

        $this->config = $config;
        self::$shutDownData['config'] = $this->config;
    }

    public function run()
    {
        declare(ticks=500);
        register_tick_function([$this->socketStreamer(), 'mainIteration']);

        rabbit::factory($this->config['queue']['section'])
            ->getQueue($this->config['queue']['queueName'])
            ->consume(function($input) {
                $this->consume($input);
            }, AMQP_AUTOACK);
    }

    /**
     * @return socketStreamer
     */
    protected function socketStreamer()
    {
        if(!isset($this->server)) {
            $this->server = new socketStreamer();
            $this->server->setOutput($this->output);
            $this->server->setBindHost($this->config['server']['host']);
            $this->server->setBindPort($this->config['server']['port']);
            $this->server->setGzip($this->config['server']['gzip']);
            $this->server->setBase64($this->config['server']['base64']);
            $this->server->setDelimiter($this->config['server']['delimiter']);
            $this->server->setAuthHash($this->config['server']['auth']);
            $this->server->setQueueLimit($this->config['server']['max_queue']);
            $this->server->setGetDataArrayFunction(function() {
                return $this->loadDataCallback();
            });
            $this->server->setOnEveryCycle(null);
            $this->server->start(false);
        }

        return $this->server;
    }

    protected function updatePause()
    {
        static $pauseMin = 0, $pauseMax = 50000, $x = 100, $noClientsPause = 200000;

        $now = time();
        if($this->nextPauseCheckTime < $now) {
            if($this->server->getClientsCount() == 0) {
                $this->pause = $noClientsPause;
            } elseif($this->pause === $noClientsPause) {
                $this->pause = 500;
            } else {
                $this->queueSize = $this->server->getMaxQueueSize();
                $this->nextPauseCheckTime = $now + 1;
                if ($this->pause < $this->queueSize) {
                    $this->pause += $x;
                } elseif ($this->pause > $this->queueSize) {
                    $this->pause -= $x;
                }
                if ($this->pause < $pauseMin) {
                    $this->pause = $pauseMin;
                } elseif ($this->pause > $pauseMax) {
                    $this->pause = $pauseMax;
                }
            }
            $string = "<error>PAUSE: {$this->pause} microseconds</error>";
            if(isset($this->output)) {
                $this->output->writeln($string);
            } else {
                echo strip_tags($string);
            }
        }
    }

    /**
     * @param \AMQPEnvelope $input
     */
    protected function consume($input)
    {
        pcntl_signal_dispatch();
        static $doublesCheck = true, $doubles = [], $cleanerCount = 0, $cleanerLimit = 10000;
        if(!isset($doublesCheck)) {
            $doublesCheck = $this->config['doubles_check']['enabled'];
            $cleanerLimit = $this->config['doubles_check']['limit'];
        }
        static $hasOutputQueue;
        if($hasOutputQueue === null) {
            $hasOutputQueue = isset($this->config['output-queue'], $this->config['output-queue']['enabled']) && $this->config['output-queue']['enabled'];
        }

        $post = json_decode($input->getBody(), true);

        if($hasOutputQueue) {
            static $outputQueue, $queue;
            if ($outputQueue === null || $queue === null) {
                $outputQueue = $this->config['output-queue'];
                $queue = rabbit::factory($outputQueue['section']);
            }

            $queue->sendToBackground($outputQueue['queueName'], $post);
        }

        $this->pause && usleep($this->pause);

        if($doublesCheck) {
            if(isset($post['_hash']) && !isset($doubles[$post['_hash']])) {
                self::$queue[] = $post;
                $this->addStatsItem('processed');
                $doubles[$post['_hash']] = true;
                if(++$cleanerCount > $cleanerLimit) {
                    $doubles = array_slice($doubles, (int)($cleanerLimit / 4));
                    $cleanerCount = count($doubles);
                }
            }
        } else {
            self::$queue[] = $post;
            $this->addStatsItem('processed');
        }


        $time = time();

        static $nextPauseUpdate = 0;
        if($nextPauseUpdate < $time) {
            $this->updatePause();
            $nextPauseUpdate = $time + 1;
        }
    }

    protected function loadDataCallback()
    {
        $queue = array_splice(self::$queue, 0);
        $this->addStatsItem('sent', count($queue));
        return $queue;
    }

    protected function addStatsItem($type, $inputCount = 1)
    {
        static $data = [], $avgPeriodSeconds = 60, $nextDraw = 0;

        if(!isset($data[$type])) {
            $data[$type] = [
                'msgs' => [],
                'nextStat' => 0,
                'speed' => 0
            ];
        }

        $now = time();

        if(!isset($data[$type]['msgs'][$now])) {
            $data[$type]['msgs'][$now] = $inputCount;
            $count = count($data[$type]['msgs']);
            if($count > $avgPeriodSeconds) {
                foreach($data[$type]['msgs'] as $key => $value) {
                    unset($data[$type]['msgs'][$key]);
                    break;
                }
                $count = $avgPeriodSeconds;
            }
            $sum = array_sum($data[$type]['msgs']);
            $data[$type]['speed'] = round($sum / $count, 2);
            if($nextDraw < $now) {
                $nextDraw = $now;
                $string = "<info>=== ===\nStatistics:</info>\n  <comment>Local queue: " . count(self::$queue) . "</comment>\n";
                foreach($data as $typeName => $typeData) {
                    $string .= "  <comment>Stream [{$typeName}]: {$typeData['speed']} mps</comment>\n";
                }
                $string .= "  <info>AvgData: {$count}/{$avgPeriodSeconds}</info>\n";
                if(isset($this->output)) {
                    $this->output->writeln($string);
                } else {
                    echo strip_tags($string);
                }
            }
        } else {
            $data[$type]['msgs'][$now] += $inputCount;
        }
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput($output)
    {
        $this->output = $output;
    }

    protected function everyCycleCallback()
    {

    }

    protected static function onShutdown()
    {
        MPCMF_DEBUG && self::log()->addDebug('Destructing and returning queue to queueServer...');
        if(!count(self::$queue)) {
            MPCMF_DEBUG && self::log()->addDebug('Nothing to return, exiting...');
            return;
        }
        while(null !== ($item = array_pop(self::$queue))) {
            rabbit::factory(self::$shutDownData['config']['queue']['section'])
                       ->sendToBackground(self::$shutDownData['config']['queue']['queueName'], $item, false);
        }
        MPCMF_DEBUG && self::log()->addDebug('Commiting return transaction...');
        rabbit::factory(self::$shutDownData['config']['queue']['section'])->runTasks();
        MPCMF_DEBUG && self::log()->addDebug('Exiting...');
    }

    public function __destruct()
    {
        self::onShutdown();
    }

    /**
     * @param mixed $pause
     */
    public function setPause($pause)
    {
        $this->pause = $pause;
    }
}
