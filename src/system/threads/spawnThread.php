<?php

namespace mpcmf\system\threads;

/**
 * Thread started as a new php process (spawn start method): php runner.php spawn
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class spawnThread extends thread
{
    const ENV_APP_ROOT = 'MPCMF_THREAD_APP_ROOT';
    const ENV_APP_NAME = 'MPCMF_THREAD_APP_NAME';
    const ENV_BOOTSTRAP = 'MPCMF_THREAD_BOOTSTRAP';
    const ENV_TITLE = 'MPCMF_THREAD_TITLE';

    /**
     * @var string
     */
    private $payload;

    /**
     * @var array
     */
    private $env;

    /**
     * @var resource|null
     */
    private $process;

    /**
     * @param string $payload see threadTask::encode()
     * @param array  $env     environment of the child
     */
    public function __construct($payload, array $env)
    {
        parent::__construct(null);
        $this->payload = $payload;
        $this->env = $env;
    }

    /**
     * @return self
     * @throws \Exception
     */
    public function start()
    {
        $null = fopen('/dev/null', 'r');
        $descriptors = self::getInheritedDescriptors($null);
        $descriptors[0] = ['file', '/dev/null', 'r'];
        $descriptors[3] = ['pipe', 'r'];
        $command = [PHP_BINARY ?: 'php', __DIR__ . '/runner.php', 'spawn'];
        $process = proc_open($command, $descriptors, $pipes, null, $this->env);
        if ($null !== false) {
            fclose($null);
        }
        if (!is_resource($process)) {
            throw new \Exception('proc_open() failed, no new process was created', self::COULD_NOT_FORK);
        }
        $this->process = $process;
        $status = proc_get_status($process);
        $this->onStarted($status['pid']);

        // blocks if the message exceeds the pipe buffer
        $message = serialize([
            'payload' => $this->payload,
            'graceful' => $this->gracefulStop,
            'signals' => $this->defaultSignals,
            'title' => isset($this->env[self::ENV_TITLE]) ? $this->env[self::ENV_TITLE] : '',
            'async' => pcntl_async_signals(),
        ]);
        for ($written = 0; $written < strlen($message); $written += $result) {
            $result = @fwrite($pipes[3], substr($message, $written));
            if (!$result) {
                break;
            }
        }
        fclose($pipes[3]);
        $this->payload = null;

        return $this;
    }

    /**
     * Descriptors above stderr, mapped to /dev/null for the child
     *
     * @param resource|false $null
     *
     * @return array
     */
    private static function getInheritedDescriptors($null)
    {
        $descriptors = [];
        if ($null === false) {

            return $descriptors;
        }
        foreach (@scandir('/proc/self/fd') ?: [] as $fd) {
            // skip the closed descriptor of scandir() itself
            if (ctype_digit($fd) && (int)$fd > 2 && @readlink("/proc/self/fd/{$fd}") !== false) {
                $descriptors[(int)$fd] = $null;
            }
        }

        return $descriptors;
    }

    /**
     * @return bool
     */
    public function isAlive()
    {
        if ($this->process === null || $this->exited) {

            return false;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {

            return true;
        }
        $this->exited = true;
        if ($status['signaled']) {
            $this->exitCode = 128 + $status['termsig'];
        } elseif ($status['exitcode'] >= 0) {
            $this->exitCode = $status['exitcode'];
        }

        return false;
    }

    /**
     * @param int $signal
     *
     * @return bool
     */
    public function signal($signal)
    {
        if (!$this->isAlive()) {

            return false;
        }

        return proc_terminate($this->process, $signal);
    }

    protected function wait()
    {
        while ($this->isAlive()) {
            usleep(10000);
        }
    }

    protected function dispose()
    {
        parent::dispose();
        if ($this->process !== null && $this->exited) {
            proc_close($this->process);
            $this->process = null;
        }
    }
}
