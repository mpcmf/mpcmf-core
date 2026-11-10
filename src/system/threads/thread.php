<?php

declare(ticks=10000);

namespace mpcmf\system\threads;

use mpcmf\system\helper\service\signalHandler;
use mpcmf\system\helper\io\log;

/**
 * Implements threading in PHP
 *
 */
class thread
{
    use log;

    const CHILD_POSTFIX = '#child_thread';

    /**
     * Status code
     * Function is not callable
     */
    const FUNCTION_NOT_CALLABLE = 10;

    /**
     * Status code
     * Couldn't fork
     */
    const COULD_NOT_FORK = 15;

    /**
     * Status code
     * Fork ready
     */
    const FORK_READY = -50;

    /**
     * Possible errors
     *
     * @var array
     */
    private $errors = [
        self::FUNCTION_NOT_CALLABLE => 'You must specify a valid function name that can be called from the current scope.',
        self::COULD_NOT_FORK => 'pcntl_fork() returned a status of -1. No new process was created',
    ];

    /**
     * callback for the function that should
     * run as a separate thread
     *
     * @var callable
     */
    protected $runnable;

    /**
     * holds the current process id
     *
     * @var integer
     */
    protected $pid;

    /**
     * Child has been reaped, its pid may belong to another process now
     *
     * @var bool
     */
    protected $exited = false;

    /**
     * @var int|null
     */
    protected $exitCode;

    /**
     * masterSignalHandler is registered, kept across restarts of the same object
     *
     * @var bool
     */
    protected $masterHandlerAdded = false;

    /**
     * Graceful stop mode: SIGTERM/SIGINT only request a stop in the child
     *
     * @var bool
     */
    protected $gracefulStop = false;

    /**
     * @var int|null
     */
    protected $startedAt;

    /**
     * Signals reset to SIG_DFL in the child before the callback runs
     *
     * @var int[]
     */
    protected $defaultSignals = [];

    /**
     * Get thread data file path
     *
     * @return string
     */
    public function getPath()
    {
        $pid = $this->getPid();
        if (empty($pid)) {
            $pid = getmypid();
        }
        return sys_get_temp_dir() . "/phpthread.{$pid}.data";
    }

    /**
     * checks if threading is supported by the current
     * PHP configuration
     *
     * @return boolean
     */
    public static function available()
    {
        $required_functions = [
            'pcntl_fork',
        ];

        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    /**
     * class constructor - you can pass
     * the callback function as an argument
     *
     * @param callable $callable
     */
    public function __construct($callable = null)
    {
        $this->runnable = $callable;
    }

    /**
     * Set result for this thread
     *
     * @param $data
     */
    public function setResult($data)
    {
        file_put_contents($this->getPath(), serialize($data));
    }

    /**
     * Get result of this thread
     *
     * @return mixed|null
     */
    public function getResult()
    {
        if (!file_exists($this->getPath())) {
            return null;
        }
        $data = unserialize(file_get_contents($this->getPath()));
        unlink($this->getPath());

        return $data;
    }

    /**
     * Set callback
     *
     * @param callable $runnable
     */
    public function setRunnable($runnable)
    {
        $this->runnable = $runnable;
    }

    /**
     * Get callback
     *
     * @return callable
     */
    public function getRunnable()
    {
        return $this->runnable;
    }

    /**
     * returns the process id (pid) of the simulated thread
     *
     * @return int pid
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * checks if the child thread is alive
     *
     * @return boolean
     */
    public function isAlive()
    {
        if ($this->pid === null || $this->exited) {

            return false;
        }
        $pid = pcntl_waitpid($this->pid, $status, WNOHANG);
        if ($pid === 0) {

            return true;
        }

        // reaped now, or -1 (ECHILD): reaped elsewhere
        $this->exited = true;
        if ($pid === $this->pid) {
            $this->exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
        }

        return false;
    }

    /**
     * Exit code of the finished thread, 128 + signal if killed, null if unknown
     *
     * @return int|null
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }

    /**
     * Enable graceful stop mode, must be called before start()
     *
     * @param bool $enabled
     */
    public function setGracefulStop($enabled = true)
    {
        $this->gracefulStop = (bool)$enabled;
    }

    /**
     * Reset signals to SIG_DFL in the child, must be called before start()
     *
     * @param int[] $signals
     */
    public function setDefaultSignals(array $signals)
    {
        $this->defaultSignals = $signals;
    }

    /**
     * Inside a graceful child: whether SIGTERM/SIGINT was received, see gracefulStop::isRequested()
     *
     * @return bool
     */
    public static function isStopRequested()
    {
        return gracefulStop::isRequested();
    }

    /**
     * Request a stop of the current process, see gracefulStop::request()
     *
     * @param int $_signal
     */
    public static function requestStop($_signal = SIGTERM)
    {
        gracefulStop::request();
    }

    /**
     * Unix time the thread was started at
     *
     * @return int|null
     */
    public function getStartedAt()
    {
        return $this->startedAt;
    }

    /**
     * Send a signal to the child if it is still alive (and so still owns its pid)
     *
     * @param int $signal
     *
     * @return bool
     */
    public function signal($signal)
    {
        if (!$this->isAlive()) {

            return false;
        }

        return posix_kill($this->pid, $signal);
    }

    /**
     * starts the thread, all the parameters are
     * passed to the callback function
     *
     * @return self
     * @throws \Exception
     */
    public function start()
    {
        $pid = @ pcntl_fork();

        if ($pid == -1) {
            throw new \Exception($this->getError(self::COULD_NOT_FORK), self::COULD_NOT_FORK);
        }

        if ($pid) {
            // master process
            $this->onStarted($pid);
        } else {
            // child process
            cli_set_process_title(cli_get_process_title() . self::CHILD_POSTFIX);
            $this->runInChild(func_get_args());
        }

        return $this;
    }

    /**
     * Master side: remember the started child
     *
     * @param int $pid
     */
    protected function onStarted($pid)
    {
        $this->pid = $pid;
        $this->startedAt = time();
        // the same object may be started again
        $this->exited = false;
        $this->exitCode = null;

        if (!$this->gracefulStop && !$this->masterHandlerAdded) {
            signalHandler::getInstance()->addHandler(SIGTERM, [$this, 'masterSignalHandler']);
            $this->masterHandlerAdded = true;
        }
    }

    /**
     * Child side: set up signals and run the callback, never returns
     *
     * @internal used by thread start methods
     *
     * @param array $arguments
     * @param bool  $forked the child is a fork of the master
     */
    public function runInChild(array $arguments, $forked = true)
    {
        $signalHandler = signalHandler::getInstance();

        foreach ($this->defaultSignals as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        if ($this->gracefulStop) {
            // a repeated SIGTERM is ignored
            gracefulStop::enable();
            register_shutdown_function([__CLASS__, 'blockStopSignals'], getmypid());
        } elseif ($forked) {
            // the default handler: gracefulStop of the callback suppresses it
            $signalHandler->setDefaultHandler(SIGTERM, [__CLASS__, 'signalHandler']);
        }

        if ($this->runnable === null) {
            self::log()->addError("[ERROR] Cannot run the empty callback");
            MPCMF_DEBUG && error_log("[ERROR] Cannot run the empty callback");

            exit(1);
        }

        try {
            call_user_func_array($this->runnable, $arguments);
        } catch (\Throwable $exception) {
            self::log()->addError("[EXCEPTION] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");
            MPCMF_DEBUG && error_log("[EXCEPTION] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}"
                . "\nStack trace:\n{$exception->getTraceAsString()}");

            exit(1);
        }
        exit(0);
    }

    /**
     * Shutdown function of a graceful child: block stop signals until the exit
     *
     * @internal
     *
     * @param int $pid the child the function was registered in
     */
    public static function blockStopSignals($pid)
    {
        if (getmypid() !== $pid) {

            return;
        }
        // ignored first: pcntl_signal() unblocks the signal it sets
        foreach (gracefulStop::SIGNALS as $signal) {
            pcntl_signal($signal, SIG_IGN);
        }
        pcntl_sigprocmask(SIG_BLOCK, gracefulStop::SIGNALS);
        foreach (gracefulStop::SIGNALS as $signal) {
            pcntl_signal($signal, SIG_DFL);
            pcntl_sigprocmask(SIG_BLOCK, [$signal]);
        }
    }

    /**
     * attempts to stop the thread
     * returns true on success and false otherwise
     *
     * @param integer $_signal - SIGKILL/SIGTERM
     * @param boolean $_wait
     */
    public function stop($_signal = SIGKILL, $_wait = false)
    {
        $isAlive = (int)$this->isAlive();
        MPCMF_DEBUG && self::log()->addDebug("Stopping process {$this->pid}, alive:{$isAlive}", [__METHOD__]);
        if ($isAlive) {
            $this->signal($_signal);
            if ($_wait) {
                $this->wait();
            }
        }
        $this->dispose();
    }

    /**
     * alias of stop();
     *
     * @param int $_signal
     * @param bool $_wait
     *
     * @return void
     */
    public function kill($_signal = SIGKILL, $_wait = false)
    {
        MPCMF_DEBUG && self::log()->addDebug("Killing process with pid {$this->pid}...", [__METHOD__]);
        // once: a repeated SIGTERM would not stop it faster
        $this->signal($_signal);
        if ($_wait) {
            MPCMF_DEBUG && self::log()->addDebug("Waiting process [pid {$this->pid}]...", [__METHOD__]);
            $this->wait();
        }
        $this->dispose();
        MPCMF_DEBUG && self::log()->addDebug("Killed! [pid {$this->pid}]...", [__METHOD__]);
    }

    /**
     * gets the error's message based on
     * its id
     *
     * @param integer $_code
     *
     * @return string
     */
    public function getError($_code)
    {
        if (isset($this->errors[$_code])) {
            return $this->errors[$_code];
        } else {
            return 'No such error code ' . $_code . '! Quit inventing errors!!!';
        }
    }

    /**
     * signal handler
     *
     * @param integer $_signal
     */
    public function masterSignalHandler($_signal = SIGTERM)
    {
        switch ($_signal) {
            case SIGTERM:
                MPCMF_DEBUG && self::log()->addDebug('[' . posix_getpid() . '] ' . __METHOD__ . ':killchild()', [__METHOD__]);
                $this->kill($_signal);
                break;
        }
    }

    /**
     * signal handler
     *
     * @param integer $_signal
     */
    public static function signalHandler($_signal = SIGTERM)
    {
        switch ($_signal) {
            case SIGTERM:
                MPCMF_DEBUG && self::log()->addDebug(__METHOD__ . ':exit()', [__METHOD__]);
                exit(128 + $_signal);
        }
    }

    /**
     * Block until the child exits and remember its exit code
     */
    protected function wait()
    {
        if ($this->exited) {

            return;
        }
        do {
            $pid = pcntl_waitpid($this->pid, $status);
            // interrupted by a signal handler, the child is alive
        } while ($pid === -1 && pcntl_get_last_error() === PCNTL_EINTR);
        $this->exited = true;
        if ($pid === $this->pid) {
            $this->exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
        }
    }

    protected function dispose()
    {
        $this->runnable = null;
        if ($this->masterHandlerAdded) {
            $signalHandler = signalHandler::getInstance();
            $signalHandler->removeHandler(SIGTERM, [$this, 'masterSignalHandler']);
            $this->masterHandlerAdded = false;
        }
    }
}
