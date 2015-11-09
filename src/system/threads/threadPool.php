<?php

namespace mpcmf\system\threads;

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
     */
    public function refresh()
    {
        /**
         * @note kill thread if thread is not alive
         */
        foreach($this->pool as $thread_key => $thread) {
            if(!$thread->isAlive()) {
                $thread->kill();
                unset($this->pool[$thread_key]);
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
        if(count($this->pool) >= $this->max_threads && count($this->queue) >= $this->max_queue) {
            return false;
        }
        $this->queue[] = array(new thread($thread), $arguments);
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
}
