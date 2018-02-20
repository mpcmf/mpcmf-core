<?php

namespace mpcmf\system\threads;

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
    const FUNCTION_NOT_CALLABLE     = 10;

    /**
     * Status code
     * Couldn't fork
     */
    const COULD_NOT_FORK            = 15;

    /**
     * Status code
     * Fork ready
     */
    const FORK_READY                = -50;

    /**
     * Possible errors
     *
     * @var array
     */
    private $errors = [
        self::FUNCTION_NOT_CALLABLE   => 'You must specify a valid function name that can be called from the current scope.',
        self::COULD_NOT_FORK          => 'pcntl_fork() returned a status of -1. No new process was created',
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
     * Get thread data file path
     *
     * @return string
     */
    public function getPath()
    {
        $pid = $this->getPid();
        if(empty($pid)) {
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
        $required_functions = array(
            'pcntl_fork',
        );

        foreach( $required_functions as $function ) {
            if ( !function_exists( $function ) ) {
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
        if($callable !== null) {
            $this->setRunnable($callable);
        }
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
        if(!file_exists($this->getPath()))
        {
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
        if($this->pid === null) {

            return false;
        }
        $pid = pcntl_waitpid($this->pid, $status, WNOHANG);
        $alive = ($pid === 0);
        if(!$alive) {
            $alive = posix_kill($this->pid, 0);
        }
        if(!$alive) {
            $alive = posix_getpgid($this->pid) !== false;
        }
        if(!$alive) {
            $alive = file_exists("/proc/{$this->pid}");
        }

        return $alive;
    }

    /**
     * starts the thread, all the parameters are
     * passed to the callback function
     *
     * @return $this
     * @throws \Exception
     */
    public function start()
    {
        $pid = @ pcntl_fork();
        if( $pid == -1 ) {
            throw new \Exception( $this->getError( self::COULD_NOT_FORK ), self::COULD_NOT_FORK );
        }
        if( $pid ) {
            // master process
            $this->pid = $pid;
        } else {
            // child process
            cli_set_process_title(cli_get_process_title() . self::CHILD_POSTFIX);
            $arguments = func_get_args();
            pcntl_signal(SIGTERM, array(__CLASS__, 'signalHandler'));
            register_shutdown_function(array(__CLASS__, 'signalHandler'));
            pcntl_signal_dispatch();
            try {
                call_user_func_array($this->runnable, $arguments);
            } catch (\Exception $exception) {
                self::log()->addError("[EXCEPTION] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");
                MPCMF_DEBUG && error_log("[EXCEPTION] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}\nStack trace:\n{$exception->getTraceAsString()}");
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
    public function stop( $_signal = SIGKILL, $_wait = false )
    {
        $isAlive = (int)$this->isAlive();
        MPCMF_DEBUG && self::log()->addDebug("Stopping process {$this->pid}, alive:{$isAlive}", [__METHOD__]);
        if($isAlive) {
            posix_kill( $this->pid, $_signal );
            if( $_wait ) {
                pcntl_waitpid( $this->pid, $status = 0 );
            }
        }
    }

    /**
     * alias of stop();
     *
     * @param int  $_signal
     * @param bool $_wait
     *
     * @return bool
     */
    public function kill( $_signal = SIGKILL, $_wait = false )
    {
        MPCMF_DEBUG && self::log()->addDebug("Killing process with pid {$this->pid}...", [__METHOD__]);
        for($i = 0; $i < 10; $i++) {
            posix_kill( $this->pid, $_signal );
            usleep(10000);
        }
        if( $_wait ) {
            MPCMF_DEBUG && self::log()->addDebug("Waiting process [pid {$this->pid}]...", [__METHOD__]);
            pcntl_waitpid( $this->pid, $status = 0 );
        }
        MPCMF_DEBUG && self::log()->addDebug("Killed! [pid {$this->pid}]...", [__METHOD__]);
    }

    /**
     * gets the error's message based on
     * its id
     *
     * @param integer $_code
     * @return string
     */
    public function getError( $_code )
    {
        if ( isset( $this->errors[$_code] ) ) {
            return $this->errors[$_code];
        }
        else {
            return 'No such error code ' . $_code . '! Quit inventing errors!!!';
        }
    }

    /**
     * signal handler
     *
     * @param integer $_signal
     */
    public static function signalHandler($_signal = SIGTERM)
    {
        switch($_signal) {
            case SIGTERM:
                MPCMF_DEBUG && self::log()->addDebug(__METHOD__ . ':exit()', [__METHOD__]);
                exit();
                break;
        }
    }
}
