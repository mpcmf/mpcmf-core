<?php

namespace mpcmf\system\threads;

/**
 * Thread forked by the fork server (forkserver start method)
 *
 * The child is not a child of the master: its state, exit code and signals go through the fork server.
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class forkServerThread extends thread
{
    /**
     * @var forkServer
     */
    private $server;

    /**
     * @var string
     */
    private $payload;

    /**
     * @var array
     */
    private $options;

    /**
     * @var int|null
     */
    private $taskId;

    /**
     * @param forkServer $server
     * @param string     $payload see threadTask::encode()
     * @param array      $options title, grace
     */
    public function __construct(forkServer $server, $payload, array $options = [])
    {
        parent::__construct(null);
        $this->server = $server;
        $this->payload = $payload;
        $this->options = $options;
    }

    /**
     * @return self
     * @throws \Exception
     */
    public function start()
    {
        $this->taskId = $this->server->startTask([
            'payload' => $this->payload,
            'graceful' => $this->gracefulStop,
            'signals' => $this->defaultSignals,
            // a forked child inherits the setting of the master, these children get it from here
            'async' => pcntl_async_signals(),
        ] + $this->options);
        $this->payload = null;
        $this->onStarted($this->server->getTask($this->taskId)['pid']);

        return $this;
    }

    /**
     * @return bool
     */
    public function isAlive()
    {
        if ($this->taskId === null || $this->exited) {

            return false;
        }
        $this->server->poll();
        $task = $this->server->getTask($this->taskId);
        if ($task !== null && !$task['exited']) {

            return true;
        }
        $this->exited = true;
        $this->exitCode = $task === null ? null : $task['code'];
        $this->server->releaseTask($this->taskId);

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

        return $this->server->signalTask($this->taskId, $signal);
    }

    protected function wait()
    {
        while ($this->isAlive()) {
            $this->server->poll(0.05);
        }
    }
}
