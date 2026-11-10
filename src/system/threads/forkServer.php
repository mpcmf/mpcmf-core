<?php

namespace mpcmf\system\threads;

use mpcmf\system\helper\io\log;

/**
 * Fork server (forkserver start method)
 *
 * The server is a fork of the master made at an explicit point (threadPool::startForkServer()), before the master
 * opens connections; it forks thread children from itself, so children inherit the master state of that moment.
 * The master talks to it over a socket pair: start a task, signal a task; the server reports pids and exit codes.
 * The server checks getppid() and terminates its children when the master is gone, the master reaps the server
 * with waitpid(). The server never runs destructors or shutdown functions of objects inherited from the master:
 * it ends with SIGKILL to itself when all its children are reaped.
 */
class forkServer
{
    use log;

    /**
     * Seconds to wait for server replies
     */
    const TIMEOUT = 30;

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
     * Shutdown was requested, the server exit is expected
     *
     * @var bool
     */
    private $stopping = false;

    /**
     * @var string
     */
    private $title;

    /**
     * @param string $title process title base of the server
     */
    public function __construct($title = '')
    {
        $this->title = (string)$title;
    }

    /**
     * Fork the server from the current process and wait until it is ready
     *
     * @throws \RuntimeException
     */
    public function start()
    {
        if ($this->pid !== null) {
            return;
        }
        $connections = self::getOpenConnections();
        if (count($connections) > 0) {
            $message = '[WARNING] Fork server is started with open connections, its threads inherit them: ' . implode(', ', $connections);
            self::log()->addWarning($message);
            MPCMF_DEBUG && error_log($message);
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Unable to create a socket pair for the fork server');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
            throw new \RuntimeException('Unable to fork the fork server', thread::COULD_NOT_FORK);
        }
        if ($pid === 0) {
            fclose($pair[0]);
            self::serve($pair[1], $this->title);
        }
        fclose($pair[1]);
        $this->pid = $pid;
        $this->ownerPid = getmypid();
        $this->socket = $pair[0];

        $deadline = microtime(true) + self::TIMEOUT;
        while (!$this->dead && !isset($this->tasks[0])) {
            if (microtime(true) > $deadline) {
                posix_kill($this->pid, SIGKILL);
                throw new \RuntimeException('Fork server did not start in ' . self::TIMEOUT . ' seconds');
            }
            $this->poll(0.1);
        }
        if ($this->dead) {
            throw new \RuntimeException('Fork server exited on start');
        }
        unset($this->tasks[0]);
    }

    /**
     * @return bool
     */
    public function isStarted()
    {
        return $this->pid !== null;
    }

    /**
     * @return bool
     */
    public function isAlive()
    {
        if ($this->pid !== null) {
            $this->poll();
        }

        return $this->pid !== null && !$this->dead;
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
        if ($this->pid === null) {
            throw new \LogicException('Fork server is not started');
        }
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
        // a forked copy of the master shares the socket but not the child: waitpid() fails there (ECHILD),
        // which must not be taken for the death of the server, and its messages belong to the original
        if ($this->socket === null || $this->dead || $this->ownerPid !== getmypid()) {
            return;
        }
        $this->read($timeout);
        // the server is our child: reaping it is the reliable death check
        if (pcntl_waitpid($this->pid, $status, WNOHANG) !== 0) {
            // exit reports the server sent before it died
            while ($this->read(0)) {
            }
            $this->onServerDeath($status);
        }
    }

    /**
     * Read and handle available server messages
     *
     * @param float $timeout
     *
     * @return bool something was read
     */
    private function read($timeout)
    {
        $read = [$this->socket];
        $write = $except = null;
        if (@stream_select($read, $write, $except, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000)) < 1) {
            return false;
        }
        $data = fread($this->socket, 65536);
        if ($data === false || $data === '') {
            return false;
        }
        $this->buffer .= $data;
        foreach (self::parse($this->buffer) as $message) {
            $this->handle($message);
        }

        return true;
    }

    /**
     * Stop the server: it exits when its children have finished
     */
    public function stop()
    {
        if ($this->socket !== null && !$this->dead && !$this->stopping) {
            $this->stopping = true;
            $this->send(['op' => 'shutdown']);
        }
    }

    public function __destruct()
    {
        // forked copies of the master must not stop the server of the original
        if ($this->ownerPid !== getmypid()) {
            return;
        }
        $this->stop();
        foreach ($this->tasks as $task) {
            if (!$task['exited']) {
                return;
            }
        }
        // idle server exits right away, reap it to leave no zombie
        for ($i = 0; $i < 100 && !$this->dead; $i++) {
            $this->poll(0.01);
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
     * @param int $status
     */
    private function onServerDeath($status)
    {
        $this->dead = true;
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
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
        if ($this->stopping && $killed === 0) {
            return;
        }
        self::log()->addError("[ERROR] Fork server {$this->pid} died (code {$code}), killed {$killed} orphaned threads");
        MPCMF_DEBUG && error_log("[ERROR] Fork server {$this->pid} died (code {$code}), killed {$killed} orphaned threads");
    }

    /**
     * @param array $message
     *
     * @return bool
     */
    private function send(array $message)
    {
        if ($this->ownerPid !== getmypid()) {
            return false;
        }

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
     * Connections the framework knows to be open in this process
     *
     * @return string[]
     */
    public static function getOpenConnections()
    {
        $pid = getmypid();
        $result = [];
        $classes = [
            'mpcmf\\system\\queue\\rabbit' => function ($instance) use ($pid) {
                $count = 0;
                foreach ((array)self::readProperty($instance, 'connections') as $key => $connection) {
                    $count += strpos($key, "{$pid}:") === 0 && $connection instanceof \AMQPConnection && $connection->isConnected();
                }

                return $count;
            },
            'mpcmf\\system\\cache\\memcached' => function ($instance) use ($pid) {
                return self::readProperty($instance, 'pid') === $pid;
            },
            'mpcmf\\system\\storage\\mongoInstance' => function ($instance) use ($pid) {
                return self::readProperty($instance, 'pid') === $pid && self::readProperty($instance, 'mongo') !== null;
            },
        ];
        foreach ($classes as $class => $isOpen) {
            // factory() keeps its instances in a static variable, a class that is not loaded has none
            if (!class_exists($class, false) || !method_exists($class, 'factory')) {
                continue;
            }
            $instances = (new \ReflectionMethod($class, 'factory'))->getStaticVariables();
            foreach (isset($instances['instances']) ? $instances['instances'] : [] as $section => $instance) {
                $count = (int)$isOpen($instance);
                if ($count > 0) {
                    $result[] = substr($class, strrpos($class, '\\') + 1) . ":{$section}" . ($count > 1 ? " x{$count}" : '');
                }
            }
        }

        return $result;
    }

    /**
     * @param object $object
     * @param string $property
     *
     * @return mixed
     */
    private static function readProperty($object, $property)
    {
        $reflection = new \ReflectionObject($object);
        if (!$reflection->hasProperty($property)) {
            return null;
        }
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /**
     * Server side: run the loop and die without running destructors and shutdown functions of inherited objects
     *
     * @param resource $socket
     * @param string   $title
     */
    private static function serve($socket, $title)
    {
        try {
            self::loop($socket, $title);
        } catch (\Throwable $exception) {
            self::log()->addError("[EXCEPTION] Fork server: {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");
        } finally {
            posix_kill(getmypid(), SIGKILL);
        }
    }

    /**
     * Server loop, returns when the master is gone or asked to stop and all children are reaped
     *
     * @param resource $socket
     * @param string   $title
     */
    private static function loop($socket, $title)
    {
        $masterPid = posix_getppid();
        cli_set_process_title($title . '#fork_server');
        // drop handlers inherited from the master, SIGPIPE stays as php cli set it
        for ($signal = 1; $signal < 32; $signal++) {
            if (!in_array($signal, [SIGKILL, SIGSTOP, SIGPIPE], true)) {
                @pcntl_signal($signal, SIG_DFL);
            }
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
                return;
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
                            // async signals as in the master, set by threadTask::run()
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
