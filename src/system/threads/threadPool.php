<?php

namespace mpcmf\system\threads;

use mpcmf\system\helper\service\signalHandler;

/**
 * Thread Pool class
 *
 * Organize thread pool with queue
 */
class threadPool
{
    /**
     * Max treads in pool
     *
     * @var int
     */
    private $max_threads = 1;

    /**
     * Max queue length
     *
     * @var int
     */
    private $max_queue = 0;

    /**
     * Pool queue array
     *
     * @var array[]
     */
    private $queue = array();

    /**
     * Pool array
     *
     * @var thread[]
     */
    private $pool = array();

    /**
     * Graceful stop mode, see enableGracefulStop()
     *
     * @var bool
     */
    private $gracefulStop = false;

    /**
     * Seconds between SIGTERM and SIGKILL when stopping threads
     *
     * @var int
     */
    private $stopGrace = 10;

    /**
     * @var bool
     */
    private $stopRequested = false;

    /**
     * Max thread lifetime in seconds, 0 - unlimited
     *
     * @var int
     */
    private $threadTimeout = 0;

    /**
     * Seconds between SIGTERM and SIGKILL for threads over the timeout
     *
     * @var int
     */
    private $threadTimeoutGrace = 10;

    /**
     * When SIGTERM was sent to a thread, by pool key
     *
     * @var float[]
     */
    private $terminatedAt = array();

    /**
     * Check if has alive threads
     *
     * @return bool
     */
    public function hasAlive()
    {
        $this->refresh();
        return (count($this->pool) > 0 || count($this->queue) > 0);
    }

    /**
     * Wait for alive threads
     *
     * @return bool
     */
    public function waitAll()
    {
        $this->refresh();
        while($this->hasAlive()) {
            usleep(100000);
            $this->refresh();
        }

        return true;
    }

    /**
     * Refresh threads in pool
     *
     * @return bool
     * @throws \Exception
     */
    public function refresh()
    {
        if($this->gracefulStop) {
            pcntl_signal_dispatch();
        }

        /**
         * @note release finished threads: the child is already reaped, there is nothing to kill
         */
        foreach($this->pool as $thread_key => $thread) {
            if(!$thread->isAlive()) {
                $thread->stop();
                unset($this->pool[$thread_key], $this->terminatedAt[$thread_key]);
            }
        }

        if($this->stopRequested) {
            $this->queue = array();
            foreach($this->pool as $thread_key => $thread) {
                $this->terminate($thread_key, $thread, $this->stopGrace);
            }

            return true;
        }

        if($this->threadTimeout > 0) {
            $now = time();
            foreach($this->pool as $thread_key => $thread) {
                if(isset($this->terminatedAt[$thread_key]) || $now - $thread->getStartedAt() >= $this->threadTimeout) {
                    $this->terminate($thread_key, $thread, $this->threadTimeoutGrace);
                }
            }
        }
        /**
         * @note add threads in pool from queue
         */
        while(count($this->queue) > 0 && count($this->pool) < $this->max_threads) {
            $threadParams = array_shift($this->queue);
            /** @var thread $thread */
            $thread = $threadParams[0];
            $params = $threadParams[1];
            $this->pool[] = $thread->start($params);
        }

        return true;
    }

    /**
     * Get pool count
     *
     * @return int
     */
    public function getPoolCount()
    {
        return is_array($this->pool) ? count($this->pool) : 0;
    }

    /**
     * Add thread with arguments in pool
     *
     * @param callable $thread
     * @param array|null $arguments
     * @return bool
     */
    public function add($thread, $arguments = null)
    {
        if($this->stopRequested) {
            return false;
        }
        if(count($this->pool) >= $this->max_threads && count($this->queue) >= $this->max_queue) {
            return false;
        }
        $threadInstance = new thread($thread);
        $threadInstance->setGracefulStop($this->gracefulStop);
        $this->queue[] = array($threadInstance, $arguments);
        $this->refresh();

        return true;
    }

    /**
     * Set max queue length
     *
     * @param $max_queue
     * @return mixed
     */
    public function setMaxQueue($max_queue)
    {
        return $this->max_queue = $max_queue;
    }

    /**
     * Get max queue length
     *
     * @return int
     */
    public function getMaxQueue()
    {
        return $this->max_queue;
    }

    /**
     * Set max threads length
     *
     * @param int $max_threads
     * @return mixed
     */
    public function setMaxThreads($max_threads)
    {
        return $this->max_threads = $max_threads;
    }

    /**
     * Get max threads value
     *
     * @return int max threads
     */
    public function getMaxThreads()
    {
        return $this->max_threads;
    }

    /**
     * Get thread pool
     *
     * @return array pool
     */
    public function getPool()
    {
        return $this->pool;
    }

    /**
     * Enable graceful stop
     *
     * SIGTERM/SIGINT to the master only request a stop (see isStopRequested()): add() refuses new threads,
     * queued threads are dropped, running threads get SIGTERM and SIGKILL after $grace seconds.
     * Threads get SIGTERM/SIGINT as a stop request too (see thread::isStopRequested()).
     * The master process is not terminated, the application decides when to exit.
     *
     * @param int $grace Seconds between SIGTERM and SIGKILL
     */
    public function enableGracefulStop($grace = 10)
    {
        $this->gracefulStop = true;
        $this->stopGrace = (int)$grace;

        $signalHandler = signalHandler::getInstance();
        $signalHandler->addHandler(SIGTERM, [$this, 'requestStop']);
        $signalHandler->addHandler(SIGINT, [$this, 'requestStop']);
    }

    /**
     * Request a stop of the pool, also called on SIGTERM/SIGINT in graceful stop mode
     */
    public function requestStop()
    {
        $this->stopRequested = true;
    }

    /**
     * Whether a stop was requested
     *
     * @return bool
     */
    public function isStopRequested()
    {
        if($this->gracefulStop) {
            pcntl_signal_dispatch();
        }

        return $this->stopRequested;
    }

    /**
     * Limit thread lifetime: a thread running longer gets SIGTERM, then SIGKILL after $grace seconds
     *
     * @param int $seconds 0 - unlimited
     * @param int $grace
     */
    public function setThreadTimeout($seconds, $grace = 10)
    {
        $this->threadTimeout = (int)$seconds;
        $this->threadTimeoutGrace = (int)$grace;
    }

    /**
     * SIGTERM once, SIGKILL when the grace period is over
     *
     * @param int|string $thread_key
     * @param thread     $thread
     * @param int        $grace
     */
    private function terminate($thread_key, thread $thread, $grace)
    {
        if(!isset($this->terminatedAt[$thread_key])) {
            $thread->signal(SIGTERM);
            $this->terminatedAt[$thread_key] = microtime(true);
        } elseif(microtime(true) - $this->terminatedAt[$thread_key] >= $grace) {
            $thread->signal(SIGKILL);
        }
    }
}
