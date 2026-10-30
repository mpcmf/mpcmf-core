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
    private $pid;

    /**
     * Child has been reaped (or is not our child anymore), its pid may belong to another process now
     *
     * @var bool
     */
    private $exited = false;

    /**
     * @var int|null
     */
    private $exitCode;

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

        // reaped now, or -1 (ECHILD): reaped elsewhere. Probing the pid further would hit a reused pid
        $this->exited = true;
        if ($pid === $this->pid) {
            $this->exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
        }

        return false;
    }

    /**
     * Exit code of the finished thread, signal number + 128 if it was killed, null if unknown or still running
     *
     * @return int|null
     */
    public function getExitCode()
    {
        return $this->exitCode;
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
        $signalHandler = signalHandler::getInstance();

        if ($pid == -1) {
            throw new \Exception($this->getError(self::COULD_NOT_FORK), self::COULD_NOT_FORK);
        }

        if ($pid) {
            // master process
            $this->pid = $pid;

            $signalHandler->addHandler(SIGTERM, [$this, 'masterSignalHandler']);
        } else {
            // child process
            cli_set_process_title(cli_get_process_title() . self::CHILD_POSTFIX);

            $signalHandler->addHandler(SIGTERM, [__CLASS__, 'signalHandler']);

            if ($this->runnable === null) {
                self::log()->addError("[ERROR] Cannot run the empty callback");
                MPCMF_DEBUG && error_log("[ERROR] Cannot run the empty callback");

                exit(1);
            }

            $arguments = func_get_args();
            try {
                call_user_func_array($this->runnable, $arguments);
            } catch (\Throwable $exception) {
                self::log()->addError("[EXCEPTION] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");
                MPCMF_DEBUG && error_log("[EXCEPTION] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}\nStack trace:\n{$exception->getTraceAsString()}");

                exit(1);
            }
            exit(0);
        }

        return $this;
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
            posix_kill($this->pid, $_signal);
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
        // only an alive, not yet reaped child is guaranteed to own the pid
        for ($i = 0; $i < 10 && $this->isAlive(); $i++) {
            posix_kill($this->pid, $_signal);
            usleep(10000);
        }
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
                break;
        }
    }

    /**
     * Block until the child exits and remember its exit code
     */
    private function wait()
    {
        if ($this->exited) {

            return;
        }
        $pid = pcntl_waitpid($this->pid, $status);
        $this->exited = true;
        if ($pid === $this->pid) {
            $this->exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
        }
    }

    private function dispose()
    {
        $this->runnable = null;
        if (!empty($this->pid)) {
            $signalHandler = signalHandler::getInstance();
            $signalHandler->removeHandler(SIGTERM, [$this, 'masterSignalHandler']);
        }
    }
}
