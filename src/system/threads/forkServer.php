<?php

namespace mpcmf\system\threads;

use mpcmf\system\helper\io\log;

/**
 * Fork server (forkserver start method)
 *
 * The server is a fresh php process (`php runner.php forkserver`, a direct child of the master) that loads only
 * the application bootstrap and forks thread children from itself, so children inherit nothing from the master.
 * The master talks to it over a socket pair: start a task, signal a task; the server reports pids and exit codes.
 * Liveness is checked by process relationships, not by EOF: the server polls getppid() and terminates its
 * children when the master is gone, the master polls the server with proc_get_status().
 */
class forkServer
{
    use log;

    const ENV_APP_ROOT = 'MPCMF_THREAD_APP_ROOT';
    const ENV_APP_NAME = 'MPCMF_THREAD_APP_NAME';
    const ENV_BOOTSTRAP = 'MPCMF_THREAD_BOOTSTRAP';
    const ENV_TITLE = 'MPCMF_THREAD_TITLE';

    /**
     * Seconds to wait for the server to load the bootstrap and for replies
     */
    const TIMEOUT = 30;

    /**
     * @var array
     */
    private $env;

    /**
     * @var resource|null
     */
    private $process;

    /**
     * @var resource|null
     */
    private $socket;

    /**
     * @var int|null
     */
    private $pid;

    /**
     * @var int
     */
    private $ownerPid;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var int
     */
    private $nextId = 1;

    /**
     * Task state by id: pid, starttime, exited, code
     *
     * @var array[]
     */
    private $tasks = [];

    /**
     * @var bool
     */
    private $dead = false;

    /**
     * @param array $env environment of the server
     */
    public function __construct(array $env)
    {
        $this->env = $env;
    }

    /**
     * Start the server and wait until it has loaded the bootstrap
     *
     * @throws \RuntimeException
     */
    public function start()
    {
        if ($this->process !== null) {
            return;
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Unable to create a socket pair for the fork server');
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            3 => $pair[1],
        ];
        $process = proc_open([PHP_BINARY ?: 'php', __DIR__ . '/runner.php', 'forkserver'], $descriptors, $pipes, null, $this->env);
        fclose($pair[1]);
        if (!is_resource($process)) {
            fclose($pair[0]);
            throw new \RuntimeException('Unable to start the fork server');
        }
        $this->process = $process;
        $this->pid = proc_get_status($process)['pid'];
        $this->ownerPid = getmypid();
        $this->socket = $pair[0];

        $deadline = microtime(true) + self::TIMEOUT;
        while (!$this->dead && !isset($this->tasks[0])) {
            if (microtime(true) > $deadline) {
                $this->terminate();
                throw new \RuntimeException('Fork server did not start in ' . self::TIMEOUT . ' seconds');
            }
            $this->poll(0.1);
        }
        if ($this->dead) {
            throw new \RuntimeException('Fork server exited on start, see its stderr');
        }
        unset($this->tasks[0]);
    }

    /**
     * @return bool
     */
    public function isStarted()
    {
        return $this->process !== null;
    }

    /**
     * @return bool
     */
    public function isAlive()
    {
        if ($this->process !== null) {
            $this->poll();
        }

        return $this->process !== null && !$this->dead;
    }

    /**
     * @return int|null
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * Ask the server to fork a child for the task, returns the task id
     *
     * @param array $message payload, graceful, signals, title, grace
     *
     * @return int
     * @throws \RuntimeException
     */
    public function startTask(array $message)
    {
        $this->start();
        $this->poll();
        if ($this->dead) {
            throw new \RuntimeException('Fork server is dead, no new threads can be started');
        }
        $id = $this->nextId++;
        $this->send(['op' => 'start', 'id' => $id] + $message);
        $deadline = microtime(true) + self::TIMEOUT;
        while (!isset($this->tasks[$id]) && !$this->dead) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException("Fork server did not reply in " . self::TIMEOUT . " seconds");
            }
            $this->poll(0.1);
        }
        if (!isset($this->tasks[$id])) {
            throw new \RuntimeException('Fork server died while starting a thread');
        }
        if (isset($this->tasks[$id]['error'])) {
            $error = $this->tasks[$id]['error'];
            unset($this->tasks[$id]);
            throw new \RuntimeException($error, thread::COULD_NOT_FORK);
        }

        return $id;
    }

    /**
     * @param int $id
     *
     * @return array|null pid, exited, code
     */
    public function getTask($id)
    {
        return isset($this->tasks[$id]) ? $this->tasks[$id] : null;
    }

    /**
     * Forget a finished task
     *
     * @param int $id
     */
    public function releaseTask($id)
    {
        if (isset($this->tasks[$id]) && $this->tasks[$id]['exited']) {
            unset($this->tasks[$id]);
        }
    }

    /**
     * Ask the server to signal a running child: the server owns the pid, so it cannot hit a reused one
     *
     * @param int $id
     * @param int $signal
     *
     * @return bool
     */
    public function signalTask($id, $signal)
    {
        if (!isset($this->tasks[$id]) || $this->tasks[$id]['exited'] || $this->dead) {
            return false;
        }

        return $this->send(['op' => 'signal', 'id' => $id, 'signal' => $signal]);
    }

    /**
     * Read server messages and check the server is alive
     *
     * @param float $timeout
     */
    public function poll($timeout = 0.0)
    {
        if ($this->socket === null || $this->dead) {
            return;
        }
        $read = [$this->socket];
        $write = $except = null;
        if (@stream_select($read, $write, $except, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000)) > 0) {
            $data = fread($this->socket, 65536);
            if ($data !== false && $data !== '') {
                $this->buffer .= $data;
                foreach (self::parse($this->buffer) as $message) {
                    $this->handle($message);
                }
            }
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->onServerDeath($status);
        }
    }

    /**
     * Stop the server: it exits when its children have finished
     */
    public function stop()
    {
        if ($this->socket !== null && !$this->dead) {
            $this->send(['op' => 'shutdown']);
        }
    }

    public function __destruct()
    {
        // forked copies of the master must not stop the server of the original
        if ($this->ownerPid === getmypid()) {
            $this->stop();
        }
    }

    /**
     * @param array $message
     */
    private function handle(array $message)
    {
        switch ($message['op']) {
            case 'ready':
                $this->tasks[0] = ['pid' => $message['pid'], 'exited' => true, 'code' => null];
                break;
            case 'started':
                $this->tasks[$message['id']] = ['pid' => $message['pid'], 'starttime' => $message['starttime'], 'exited' => false, 'code' => null];
                break;
            case 'failed':
                $this->tasks[$message['id']] = ['pid' => null, 'exited' => true, 'code' => null, 'error' => $message['error']];
                break;
            case 'exited':
                if (isset($this->tasks[$message['id']])) {
                    $this->tasks[$message['id']]['exited'] = true;
                    $this->tasks[$message['id']]['code'] = $message['code'];
                }
                break;
        }
    }

    /**
     * Server is gone: its children are orphans now, kill the ones that still are the processes we know
     *
     * @param array $status
     */
    private function onServerDeath(array $status)
    {
        $this->dead = true;
        $code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
        $killed = 0;
        foreach ($this->tasks as $id => $task) {
            if ($task['exited']) {
                continue;
            }
            // pid + start time identify the process, a reused pid has another start time
            if (isset($task['starttime']) && self::getStartTime($task['pid']) === $task['starttime']) {
                posix_kill($task['pid'], SIGKILL);
                $killed++;
            }
            $this->tasks[$id]['exited'] = true;
        }
        self::log()->addError("[ERROR] Fork server {$this->pid} died (code {$code}), killed {$killed} orphaned threads");
        MPCMF_DEBUG && error_log("[ERROR] Fork server {$this->pid} died (code {$code}), killed {$killed} orphaned threads");
    }

    private function terminate()
    {
        if ($this->process !== null) {
            proc_terminate($this->process, SIGKILL);
            $this->dead = true;
        }
    }

    /**
     * @param array $message
     *
     * @return bool
     */
    private function send(array $message)
    {
        return self::write($this->socket, $message);
    }

    /**
     * @param resource $socket
     * @param array    $message
     *
     * @return bool
     */
    private static function write($socket, array $message)
    {
        $data = serialize($message);
        $data = pack('N', strlen($data)) . $data;
        for ($written = 0; $written < strlen($data); $written += $result) {
            $result = @fwrite($socket, substr($data, $written));
            if (!$result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cut complete frames off the buffer
     *
     * @param string $buffer
     *
     * @return array[]
     */
    private static function parse(&$buffer)
    {
        $messages = [];
        while (strlen($buffer) >= 4) {
            $length = unpack('N', $buffer)[1];
            if (strlen($buffer) < 4 + $length) {
                break;
            }
            $messages[] = unserialize(substr($buffer, 4, $length));
            $buffer = (string)substr($buffer, 4 + $length);
        }

        return $messages;
    }

    /**
     * Process start time in clock ticks since boot, null if there is no such process
     *
     * @param int $pid
     *
     * @return string|null
     */
    private static function getStartTime($pid)
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return null;
        }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));

        // field 22 of stat, fields after the command start from field 3
        return isset($fields[19]) ? $fields[19] : null;
    }

    /**
     * Server loop, runs in the fork server process
     *
     * @param resource $socket
     */
    public static function serve($socket)
    {
        $masterPid = posix_getppid();
        $title = getenv(self::ENV_TITLE);
        if ($title) {
            cli_set_process_title($title . '#fork_server');
        }
        // the server lives in the master's process group: group signals must not kill it before its children
        $ignored = [SIGTERM, SIGINT, SIGHUP, SIGQUIT, SIGUSR1, SIGUSR2];
        foreach ($ignored as $signal) {
            pcntl_signal($signal, SIG_IGN);
        }
        // only to interrupt stream_select() when a child exits
        pcntl_signal(SIGCHLD, function () {}, false);
        pcntl_async_signals(true);

        $children = []; // id => [pid, grace]
        $pids = [];     // pid => id
        $buffer = '';
        $masterGone = false;
        $shutdown = false;
        $terminatedAt = null;
        self::write($socket, ['op' => 'ready', 'pid' => getmypid()]);

        while (true) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                if (!isset($pids[$pid])) {
                    continue;
                }
                $id = $pids[$pid];
                unset($pids[$pid], $children[$id]);
                $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
                $masterGone || self::write($socket, ['op' => 'exited', 'id' => $id, 'code' => $code]);
            }

            if (!$masterGone && posix_getppid() !== $masterPid) {
                $masterGone = true;
                $terminatedAt = microtime(true);
                foreach ($children as $child) {
                    posix_kill($child['pid'], SIGTERM);
                }
            }
            if (($masterGone || $shutdown) && count($children) === 0) {
                exit(0);
            }
            if ($masterGone) {
                foreach ($children as $child) {
                    if (microtime(true) - $terminatedAt >= $child['grace']) {
                        posix_kill($child['pid'], SIGKILL);
                    }
                }
                usleep(100000);
                continue;
            }

            $read = [$socket];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) < 1) {
                continue;
            }
            $data = fread($socket, 65536);
            if ($data === false || $data === '') {
                // master closed the socket but is alive: finish the children and exit
                $shutdown = true;
                usleep(100000);
                continue;
            }
            $buffer .= $data;
            foreach (self::parse($buffer) as $message) {
                switch ($message['op']) {
                    case 'start':
                        threadTask::preload($message['payload']);
                        $pid = pcntl_fork();
                        if ($pid === -1) {
                            self::write($socket, ['op' => 'failed', 'id' => $message['id'], 'error' => 'pcntl_fork() failed in the fork server']);
                            break;
                        }
                        if ($pid === 0) {
                            fclose($socket);
                            foreach (array_merge($ignored, [SIGCHLD]) as $signal) {
                                pcntl_signal($signal, SIG_DFL);
                            }
                            pcntl_async_signals(false);
                            threadTask::run($message);
                        }
                        $children[$message['id']] = ['pid' => $pid, 'grace' => isset($message['grace']) ? $message['grace'] : 10];
                        $pids[$pid] = $message['id'];
                        self::write($socket, ['op' => 'started', 'id' => $message['id'], 'pid' => $pid, 'starttime' => self::getStartTime($pid)]);
                        break;
                    case 'signal':
                        // not reaped yet, so the pid is still ours
                        if (isset($children[$message['id']])) {
                            posix_kill($children[$message['id']]['pid'], $message['signal']);
                        }
                        break;
                    case 'shutdown':
                        $shutdown = true;
                        break;
                }
            }
        }
    }
}
