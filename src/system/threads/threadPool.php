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
     * Start methods, see setStartMethod()
     */
    const START_FORK = 'fork';
    const START_FORKSERVER = 'forkserver';
    const START_SPAWN = 'spawn';

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
     * Signals reset to SIG_DFL in children
     *
     * @var int[]
     */
    private $childDefaultSignals = array();

    /**
     * When SIGTERM was sent to a thread, by pool key
     *
     * @var float[]
     */
    private $terminatedAt = array();

    /**
     * @var string
     */
    private $startMethod = self::START_FORK;

    /**
     * Whether add() was called, the start method cannot be changed after that
     *
     * @var bool
     */
    private $used = false;

    /**
     * Bootstrap file for spawn/forkserver children
     *
     * @var string|null
     */
    private $bootstrap;

    /**
     * @var forkServer|null
     */
    private $forkServer;

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
        $stopRequested = $this->isStopRequested();

        /**
         * @note release finished threads: the child is already reaped, there is nothing to kill
         */
        foreach($this->pool as $thread_key => $thread) {
            if(!$thread->isAlive()) {
                $thread->stop();
                unset($this->pool[$thread_key], $this->terminatedAt[$thread_key]);
            }
        }

        if($stopRequested) {
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
     * @param callable|string|array $thread With forkserver/spawn start methods an addressable task, see setStartMethod()
     * @param array|null $arguments
     * @return bool
     * @throws \InvalidArgumentException Task or arguments cannot be passed to a forkserver/spawn child
     */
    public function add($thread, $arguments = null)
    {
        if($this->isStopRequested()) {
            return false;
        }
        if(count($this->pool) >= $this->max_threads && count($this->queue) >= $this->max_queue) {
            return false;
        }
        $this->used = true;
        $threadInstance = $this->createThread($thread, $arguments);
        $threadInstance->setGracefulStop($this->gracefulStop);
        $threadInstance->setDefaultSignals($this->childDefaultSignals);
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
     * The master process is not terminated, the application decides when to exit: signals are handled
     * by gracefulStop, which is enabled for the process, a repeated signal does not exit.
     *
     * @param int $grace Seconds between SIGTERM and SIGKILL
     */
    public function enableGracefulStop($grace = 10)
    {
        $this->gracefulStop = true;
        $this->stopGrace = (int)$grace;

        gracefulStop::enable();
        // the pool owns the shutdown: it stops threads and kills them after the grace period
        gracefulStop::setForceOnRepeat(false);
    }

    /**
     * Request a stop of the pool, SIGTERM/SIGINT in graceful stop mode request it too (see gracefulStop)
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
        if($this->gracefulStop && !$this->stopRequested && gracefulStop::isRequested()) {
            $this->stopRequested = true;
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
     * Reset signals to SIG_DFL in children, so signals handled by the master are not swallowed there
     *
     * In graceful stop mode SIGTERM/SIGINT are handled by the thread anyway.
     *
     * @param int[] $signals
     */
    public function setChildDefaultSignals(array $signals)
    {
        $this->childDefaultSignals = $signals;
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

    /**
     * Set how children are started, must be called before the first add()
     *
     * START_FORK (default): pcntl_fork() of the master, the task is any callable, the child inherits everything.
     * START_FORKSERVER: a fork of the fork server, which is a fork of the master made by startForkServer().
     * START_SPAWN: a new php process per thread, loads the application bootstrap itself.
     * With forkserver/spawn the task must be addressable: 'class::method', [class::class, 'method']
     * (public static method) or an invokable class name, and its arguments must be serializable.
     *
     * @param string $method
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function setStartMethod($method)
    {
        if($this->used) {
            throw new \LogicException('Start method must be set before the first add() and startForkServer()');
        }
        if(!in_array($method, array(self::START_FORK, self::START_FORKSERVER, self::START_SPAWN), true)) {
            throw new \InvalidArgumentException("Unknown thread start method: {$method}");
        }
        $this->startMethod = $method;
    }

    /**
     * @return string
     */
    public function getStartMethod()
    {
        return $this->startMethod;
    }

    /**
     * Bootstrap file loaded by spawn children, default APP_ROOT/loader.php
     * (or vendor/autoload.php and environment.php, as bin/mpcmf does)
     *
     * @param string $file
     */
    public function setBootstrap($file)
    {
        $this->bootstrap = $file;
    }

    /**
     * Fork the fork server from the current process, required before add() with the forkserver start method
     *
     * Threads inherit the state of this process at the moment of the call (loaded classes, config, constants),
     * so call it after the application initialization and before opening connections: connections the
     * framework knows to be open are reported to the log as a warning. A dead server is replaced.
     *
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function startForkServer()
    {
        if($this->startMethod !== self::START_FORKSERVER) {
            throw new \LogicException('Fork server is used only with the forkserver start method');
        }
        $this->used = true;
        if($this->forkServer !== null && $this->forkServer->isAlive()) {
            return;
        }
        $this->forkServer = new forkServer($this->getChildTitle());
        $this->forkServer->start();
    }

    /**
     * Whether the fork server is running
     *
     * @return bool
     */
    public function isForkServerAlive()
    {
        return $this->forkServer !== null && $this->forkServer->isAlive();
    }

    /**
     * Fork server pid, null if it is not started
     *
     * @return int|null
     */
    public function getForkServerPid()
    {
        return $this->forkServer !== null ? $this->forkServer->getPid() : null;
    }

    /**
     * Stop the fork server when its running threads have finished
     */
    public function stopForkServer()
    {
        if($this->forkServer !== null) {
            $this->forkServer->stop();
            $this->forkServer = null;
        }
    }

    /**
     * @param mixed $task
     * @param mixed $arguments
     *
     * @return thread
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function createThread($task, $arguments)
    {
        if($this->startMethod === self::START_FORK) {
            return new thread($task);
        }
        $payload = threadTask::encode($task, $arguments);
        if($this->startMethod === self::START_SPAWN) {
            return new spawnThread($payload, $this->getChildEnv());
        }
        if($this->forkServer === null) {
            throw new \LogicException('Fork server is not started, call startForkServer() before add()');
        }
        if(!$this->forkServer->isAlive()) {
            throw new \RuntimeException('Fork server is dead, no new threads can be started');
        }

        return new forkServerThread($this->forkServer, $payload, array(
            'title' => $this->getChildTitle(),
            'grace' => $this->stopGrace,
        ));
    }

    /**
     * Process title base of children: the master title or command line
     *
     * @return string
     */
    private function getChildTitle()
    {
        $title = (string)cli_get_process_title();
        if($title === '' && is_readable('/proc/self/cmdline')) {
            $title = trim(str_replace("\0", ' ', file_get_contents('/proc/self/cmdline')));
        }

        return $title;
    }

    /**
     * Environment of spawn children: the master environment and how to load the application
     *
     * @return array
     */
    private function getChildEnv()
    {
        $env = getenv();
        $env[spawnThread::ENV_TITLE] = $this->getChildTitle();
        if(defined('APP_ROOT')) {
            $env[spawnThread::ENV_APP_ROOT] = APP_ROOT;
        }
        if(defined('APP_NAME')) {
            $env[spawnThread::ENV_APP_NAME] = APP_NAME;
        }
        if($this->bootstrap !== null) {
            $env[spawnThread::ENV_BOOTSTRAP] = $this->bootstrap;
        }

        return $env;
    }
}
