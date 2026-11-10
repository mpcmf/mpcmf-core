<?php

namespace mpcmf\system\net\curl;

use KHR\React\Curl\Exception as curlException;
use KHR\React\Curl\Result as curlResult;
use mpcmf\system\net\curl\exception\cancelledException;
use mpcmf\system\net\curl\exception\deadlineException;
use mpcmf\system\net\curl\exception\optionException;
use mpcmf\system\net\curl\exception\queueFullException;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Asynchronous HTTP client: curl_multi pool polled from a ReactPHP loop timer
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class curlPool
{
    const POLL_ADAPTIVE = 'adaptive';
    const POLL_SELECT = 'select';
    const POLL_LEGACY = 'legacy';

    /**
     * Result streams (MCurl\Client compatible)
     */
    const STREAM_MEMORY = 'php://memory';
    const STREAM_FILE = 'php://temp/maxmemory:0';

    const LEGACY_INTERVAL = 0.01;

    /**
     * Idle ticks before the adaptive interval grows
     */
    const ADAPTIVE_HOLD_TICKS = 5;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var \CurlMultiHandle
     */
    protected $mh;

    /**
     * @var \CurlShareHandle|null
     */
    protected $sh;

    /**
     * Client-level options, task options win
     *
     * @var array
     */
    protected $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ];

    protected $maxRequest = 10;

    /**
     * @var sleepThrottle
     */
    protected $throttle;

    protected $classResult = curlResult::class;

    protected $baseUrl;

    protected $streamResult;

    protected $streamFilters = [];

    protected $enableHeaders = false;

    /**
     * @var \SplQueue
     */
    protected $queue;

    /**
     * Queued tasks, without cancelled and expired ones still in $queue
     *
     * @var int
     */
    protected $queued = 0;

    /**
     * Running tasks by spl_object_id() of the handle
     *
     * @var curlTask[]
     */
    protected $active = [];

    /**
     * [curlTask, exception class, failure] waiting to be rejected
     *
     * @var array[]
     */
    protected $failed = [];

    protected $flushScheduled = false;

    protected $kickScheduled = false;

    /**
     * Finished requests, drive the throttle
     *
     * @var int
     */
    protected $finished = 0;

    /**
     * @var \React\EventLoop\TimerInterface|\React\EventLoop\Timer\TimerInterface|null
     */
    protected $timer;

    protected $timerInterval;

    protected $interval;

    protected $idleTicks = 0;

    protected $inTick = false;

    protected $pollMode = self::POLL_ADAPTIVE;

    protected $minInterval = 0.001;

    protected $maxInterval = 0.01;

    protected $selectTimeout = 0.005;

    /**
     * Legacy pump: transfers were added and not finished yet
     *
     * @var bool
     */
    protected $legacyRunning = false;

    /**
     * Legacy pump: finished, not settled yet
     *
     * @var curlTask[]
     */
    protected $legacyPending = [];

    /**
     * Queue limit, 0 - unlimited
     *
     * @var int
     */
    protected $maxQueue = 0;

    protected $deadline;

    protected $cancellable = false;

    protected $autoRun = false;

    protected $cookieShare = false;

    protected $strictOptions = false;

    /**
     * @var callable|null
     */
    protected $errorLogger;

    /**
     * Log rate limit window, seconds
     *
     * @var float
     */
    private static $logWindow = 60.0;

    /**
     * key => [window start, suppressed count]
     *
     * @var array
     */
    private static $logSeen = [];

    /**
     * @param LoopInterface $loop
     */
    public function __construct($loop)
    {
        $this->loop = $loop;
        $this->mh = curl_multi_init();
        $this->queue = new \SplQueue();
        $this->throttle = new sleepThrottle();
        $this->throttle->configure(1, 0, true);
        $this->interval = $this->minInterval;
    }

    /**
     * Queue a request
     *
     * @param array $opts
     * @param array|string $params request data or id
     *
     * @return PromiseInterface
     */
    public function add($opts = [], $params = [])
    {
        $id = null;
        if (is_string($params)) {
            $id = $params;
            $params = [];
        }
        if ($this->baseUrl !== null && isset($opts[CURLOPT_URL])) {
            $opts[CURLOPT_URL] = $this->baseUrl . $opts[CURLOPT_URL];
        }
        $task = new curlTask($id, [], $params);
        $task->deferred = $deferred = $this->cancellable
            ? new Deferred(function ($resolve, $reject) use ($task) {
                $this->cancelTask($task, $reject);
            })
            : new Deferred();
        $promise = $deferred->promise();

        $streamError = $this->attachStreams($opts);
        $task->opts = $opts;
        if ($streamError !== null) {
            $this->fail($task, optionException::class, 'client', $streamError, CURLE_BAD_FUNCTION_ARGUMENT);
            $this->scheduleFlush();

            return $promise;
        }
        if ($this->maxQueue > 0 && $this->queued >= $this->maxQueue + $this->slotsOnKick()) {
            $this->fail($task, queueFullException::class, 'client', "Queue limit of {$this->maxQueue} requests reached", 0);
            $this->scheduleFlush();

            return $promise;
        }
        if ($this->deadline !== null) {
            $task->deadline = $this->deadline;
            $task->expiresAt = microtime(true) + $this->deadline;
        }
        $this->queue->enqueue($task);
        $this->queued++;

        if (!$this->inTick) {
            if ($this->isLegacy()) {
                if ($this->autoRun && $this->timer === null) {
                    $this->schedule(self::LEGACY_INTERVAL);
                }
            } elseif ($this->timer !== null || $this->autoRun) {
                $this->scheduleKick();
            }
        }

        return $promise;
    }

    /**
     * Process requests until all of them are settled
     */
    public function run()
    {
        if ($this->inTick) {
            return;
        }
        if ($this->isLegacy()) {
            $this->legacyRun();

            return;
        }
        $this->interval = $this->minInterval;
        $this->tick();
        // like khr: an idle pool keeps polling for one round
        if ($this->timer === null) {
            $this->schedule($this->maxInterval);
        }
    }

    /**
     * Set client-level curl options
     *
     * @param array $values
     */
    public function setCurlOption($values)
    {
        foreach ($values as $key => $value) {
            $this->curlOptions[$key] = $value;
        }
    }

    /**
     * Client-level curl options
     *
     * @return array
     */
    public function getCurlOption()
    {
        return $this->curlOptions;
    }

    /**
     * Sleep $second seconds after every $next requests
     *
     * @param int $next
     * @param float $second
     * @param bool $blocking usleep() instead of postponing requests
     */
    public function setSleep($next, $second = 1.0, $blocking = true)
    {
        $this->throttle->configure($next, $second, $blocking);
    }

    /**
     * Max parallel transfers
     *
     * @param int $max
     */
    public function setMaxRequest($max)
    {
        $this->maxRequest = (int)$max;
        if ($this->maxRequest > 0) {
            curl_multi_setopt($this->mh, CURLMOPT_MAXCONNECTS, $this->maxRequest);
        }
    }

    /**
     * Set an option of the curl share handle
     *
     * @param int $option
     * @param mixed $value
     */
    public function setShareOptions($option, $value)
    {
        curl_share_setopt($this->getShareHandle(), $option, $value);
    }

    /**
     * Collect response headers (Result::getHeaders())
     *
     * @param bool $enable
     */
    public function enableHeaders($enable = true)
    {
        $this->enableHeaders = (bool)$enable;
    }

    /**
     * Write bodies into a stream of this wrapper (self::STREAM_*)
     *
     * @param string|null $stream
     */
    public function setStreamResult($stream)
    {
        $this->streamResult = $stream;
    }

    /**
     * Stream filters appended to result streams
     *
     * @param array $filters
     *
     * @return $this
     */
    public function setStreamFilters(array $filters)
    {
        $this->streamFilters = $filters;

        return $this;
    }

    /**
     * Prefix for CURLOPT_URL of added requests
     *
     * @param string|null $baseUrl
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Result class, subclass of \MCurl\Result
     *
     * @param string $name
     */
    public function setClassResult($name)
    {
        $this->classResult = ltrim($name, '\\');
    }

    /**
     * Switch to the select poll mode (MCurl\Client compatible)
     *
     * @param bool $select
     */
    public function isSelect($select)
    {
        if ($select) {
            $this->setPollMode(self::POLL_SELECT);
        } elseif ($this->pollMode === self::POLL_SELECT) {
            $this->setPollMode(self::POLL_ADAPTIVE);
        }
    }

    /**
     * Queued, not started requests
     *
     * @return int
     */
    public function getCountQuery()
    {
        return $this->queued;
    }

    /**
     * Running transfers
     *
     * @return int
     */
    public function getActiveCount()
    {
        return count($this->active);
    }

    /**
     * Adaptive poll interval: $min after progress, doubling up to $max when idle
     *
     * @param float $min
     * @param float|null $max
     */
    public function setPollInterval($min, $max = null)
    {
        $this->minInterval = max(0.0001, (float)$min);
        $this->maxInterval = max($this->minInterval, $max === null ? 0.01 : (float)$max);
        $this->interval = $this->minInterval;
    }

    /**
     * Poll mode: self::POLL_ADAPTIVE (default), self::POLL_SELECT or self::POLL_LEGACY
     *
     * @param string $mode
     * @param float $selectTimeout
     *
     * @throws \InvalidArgumentException
     */
    public function setPollMode($mode, $selectTimeout = 0.005)
    {
        if (!in_array($mode, [self::POLL_ADAPTIVE, self::POLL_SELECT, self::POLL_LEGACY], true)) {
            throw new \InvalidArgumentException("Unknown poll mode: {$mode}");
        }
        $this->pollMode = $mode;
        $this->selectTimeout = max(0.0, (float)$selectTimeout);
    }

    /**
     * Opt-in: reject add() with queueFullException when $max requests are queued
     *
     * @param int $max
     */
    public function setMaxQueue($max)
    {
        $this->maxQueue = max(0, (int)$max);
    }

    /**
     * Opt-in: time limit per request including the queue wait
     *
     * @param float|null $seconds
     */
    public function setDeadline($seconds)
    {
        $this->deadline = $seconds === null ? null : max(0.001, (float)$seconds);
    }

    /**
     * Opt-in: $promise->cancel() aborts the request with cancelledException
     *
     * @param bool $enable
     */
    public function setCancellable($enable = true)
    {
        $this->cancellable = (bool)$enable;
    }

    /**
     * Opt-in: add() starts processing without run()
     *
     * @param bool $enable
     */
    public function setAutoRun($enable = true)
    {
        $this->autoRun = (bool)$enable;
    }

    /**
     * Opt-in: keep cookies in memory of the pool (curl_share) instead of the cookie file
     *
     * @param bool $enable
     */
    public function setCookieShare($enable = true)
    {
        $this->cookieShare = (bool)$enable;
        if ($this->cookieShare) {
            curl_share_setopt($this->getShareHandle(false), CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
        }
    }

    /**
     * Opt-in: reject the request when libcurl rejects one of its options
     *
     * @param bool $enable
     */
    public function setStrictOptions($enable = true)
    {
        $this->strictOptions = (bool)$enable;
    }

    /**
     * Log a message key at most once per $seconds per process, 0 - no limit
     *
     * @param float $seconds
     */
    public static function setLogRateLimit($seconds)
    {
        self::$logWindow = max(0.0, (float)$seconds);
        self::$logSeen = [];
    }

    /**
     * Logger function (string $message, array $context), null - mpcmf log
     *
     * @param callable|null $logger
     */
    public function setErrorLogger($logger)
    {
        $this->errorLogger = $logger;
    }

    /**
     * Log an error, rate-limited per $key (default: the message)
     *
     * @param string $message
     * @param \Throwable|null $exception
     * @param string|null $key
     */
    public function logError($message, $exception = null, $key = null)
    {
        $this->log('error', $message, $exception, $key);
    }

    protected function log($level, $message, $exception = null, $key = null)
    {
        $key = $key ?? $message;
        if (self::$logWindow > 0) {
            $now = microtime(true);
            if (isset(self::$logSeen[$key]) && $now - self::$logSeen[$key][0] < self::$logWindow) {
                self::$logSeen[$key][1]++;

                return;
            }
            $suppressed = isset(self::$logSeen[$key]) ? self::$logSeen[$key][1] : 0;
            self::$logSeen[$key] = [$now, 0];
            if (count(self::$logSeen) > 10000) {
                self::$logSeen = [$key => self::$logSeen[$key]];
            }
            if ($suppressed > 0) {
                $message .= sprintf(' (%d similar messages suppressed in the last %d s)', $suppressed, self::$logWindow);
            }
        }
        $context = ['level' => $level];
        if ($exception !== null) {
            $context['exception'] = get_class($exception) . ': ' . $exception->getMessage()
                . ' at ' . $exception->getFile() . ':' . $exception->getLine();
        }
        if ($this->errorLogger !== null) {
            call_user_func($this->errorLogger, $message, $context);

            return;
        }
        static $mpcmfLogUsable = true;
        if ($mpcmfLogUsable) {
            try {
                $logger = \mpcmf\system\io\log::factory();
                unset($context['level']);
                $level === 'warning' ? $logger->addWarning($message, $context) : $logger->addError($message, $context);

                return;
            } catch (\Throwable $logException) {
                $mpcmfLogUsable = false;
            }
        }
        error_log($message . (isset($context['exception']) ? ' (' . $context['exception'] . ')' : ''));
    }

    protected function isLegacy()
    {
        return $this->pollMode === self::POLL_LEGACY || $this->throttle->isBlocking();
    }

    protected function getShareHandle($asClientOption = true)
    {
        if ($this->sh === null) {
            $this->sh = curl_share_init();
        }
        if ($asClientOption) {
            $this->curlOptions[CURLOPT_SHARE] = $this->sh;
        }

        return $this->sh;
    }

    /**
     * Open result streams of MCurl\Client at add() time
     *
     * @param array $opts
     *
     * @return string|null error
     */
    protected function attachStreams(array &$opts)
    {
        if ($this->streamResult !== null && !isset($opts[CURLOPT_FILE])) {
            $stream = @fopen($this->streamResult, 'r+');
            if ($stream === false) {
                return "Cannot open result stream {$this->streamResult}";
            }
            $opts[CURLOPT_FILE] = $stream;
        }
        if ($this->streamFilters && isset($opts[CURLOPT_FILE])) {
            foreach ($this->streamFilters as $filter) {
                stream_filter_append($opts[CURLOPT_FILE], $filter);
            }
        }
        if ($this->enableHeaders && !isset($opts[CURLOPT_WRITEHEADER])) {
            $stream = @fopen(self::STREAM_MEMORY, 'r+');
            if ($stream === false) {
                return 'Cannot open header stream';
            }
            $opts[CURLOPT_WRITEHEADER] = $stream;
        }

        return null;
    }

    /**
     * Start queued requests allowed by max requests and the throttle
     *
     * @return int
     */
    protected function fill()
    {
        if ($this->queued === 0) {
            return 0;
        }
        $allowed = $this->throttle->allow($this->maxRequest - count($this->active), $this->finished);
        $started = 0;
        while ($allowed > 0 && $this->queued > 0) {
            /** @var curlTask $task */
            $task = $this->queue->dequeue();
            if ($task->state !== curlTask::QUEUED) {
                continue;
            }
            $this->queued--;
            if ($task->expiresAt !== null && microtime(true) >= $task->expiresAt) {
                $this->expire($task);
                continue;
            }
            $allowed--;
            if ($this->start($task)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * Start a transfer, false when the request is rejected
     *
     * @param curlTask $task
     *
     * @return bool
     */
    protected function start(curlTask $task)
    {
        $ch = curl_init();
        $task->ch = $ch;
        $options = $this->effectiveOptions($task);
        $error = null;
        try {
            if (!curl_setopt_array($ch, $options)) {
                $error = $this->applyOptionsOneByOne($ch, $options, $task);
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
        if ($error === null) {
            $code = curl_multi_add_handle($this->mh, $ch);
            if ($code !== 0) {
                $error = 'curl_multi_add_handle(): ' . curl_multi_strerror($code);
            }
        }
        if ($error !== null) {
            // counts as finished, like a failed transfer
            $this->finished++;
            $task->state = curlTask::DONE;
            $this->fail($task, optionException::class, 'client', $error, CURLE_BAD_FUNCTION_ARGUMENT);

            return false;
        }
        $task->state = curlTask::ACTIVE;
        $this->active[spl_object_id($ch)] = $task;

        return true;
    }

    protected function effectiveOptions(curlTask $task)
    {
        $options = $task->opts + $this->curlOptions;
        // PHP 8.5 rejects an integer FOLLOWLOCATION like 20
        if (isset($options[CURLOPT_FOLLOWLOCATION]) && is_int($options[CURLOPT_FOLLOWLOCATION]) && $options[CURLOPT_FOLLOWLOCATION] > 3) {
            $options[CURLOPT_FOLLOWLOCATION] = true;
        }
        if ($this->cookieShare && !isset($task->opts[CURLOPT_COOKIEFILE]) && !isset($task->opts[CURLOPT_COOKIEJAR])) {
            unset($options[CURLOPT_COOKIEJAR], $options[CURLOPT_COOKIEFILE], $options[CURLOPT_SHARE]);
            $options[CURLOPT_SHARE] = $this->getShareHandle(false);
            $options[CURLOPT_COOKIEFILE] = '';
        }
        if ($task->expiresAt !== null) {
            $leftMs = max(1, (int)(($task->expiresAt - microtime(true)) * 1000));
            if (isset($options[CURLOPT_TIMEOUT_MS]) && $options[CURLOPT_TIMEOUT_MS] > 0) {
                $leftMs = min($leftMs, (int)$options[CURLOPT_TIMEOUT_MS]);
            } elseif (isset($options[CURLOPT_TIMEOUT]) && $options[CURLOPT_TIMEOUT] > 0) {
                $leftMs = min($leftMs, (int)$options[CURLOPT_TIMEOUT] * 1000);
            }
            unset($options[CURLOPT_TIMEOUT], $options[CURLOPT_TIMEOUT_MS]);
            $options[CURLOPT_TIMEOUT_MS] = $leftMs;
        }

        return $options;
    }

    /**
     * Set options one by one, skipping those libcurl rejects
     *
     * @param resource|\CurlHandle $ch
     * @param array $options
     * @param curlTask $task
     *
     * @return string|null error
     *
     * @throws \Throwable
     */
    protected function applyOptionsOneByOne($ch, array $options, curlTask $task)
    {
        $skipped = [];
        foreach ($options as $option => $value) {
            if (!curl_setopt($ch, $option, $value)) {
                $skipped[] = self::optionName($option);
            }
        }
        if (!$skipped) {
            return null;
        }
        if ($this->strictOptions) {
            return 'curl_setopt_array(): failed to set ' . implode(', ', $skipped);
        }
        foreach ($skipped as $name) {
            $this->log('warning', "reactCurl: curl rejected {$name}, the option is skipped (url " . ($task->opts[CURLOPT_URL] ?? '') . ')',
                null, "option:{$name}");
        }

        return null;
    }

    /**
     * Name of a curl option constant
     *
     * @param int $option
     *
     * @return string
     */
    public static function optionName($option)
    {
        static $names;
        if ($names === null) {
            $names = [];
            foreach (get_defined_constants(true)['curl'] ?? [] as $name => $value) {
                if (strpos($name, 'CURLOPT_') === 0 && !isset($names[$value])) {
                    $names[$value] = $name;
                }
            }
        }

        return isset($names[$option]) ? "{$names[$option]} ({$option})" : "option {$option}";
    }

    protected function exec()
    {
        do {
            $code = curl_multi_exec($this->mh, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);
    }

    /**
     * Read finished transfers and remove their handles
     *
     * @return curlTask[]
     */
    protected function readFinished()
    {
        $finished = [];
        while (($info = curl_multi_info_read($this->mh)) !== false) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }
            $ch = $info['handle'];
            curl_multi_remove_handle($this->mh, $ch);
            $key = spl_object_id($ch);
            if (!isset($this->active[$key])) {
                continue;
            }
            $task = $this->active[$key];
            unset($this->active[$key]);
            $task->state = curlTask::DONE;
            $this->finished++;
            $finished[] = $task;
        }

        return $finished;
    }

    protected function settle(curlTask $task)
    {
        $deferred = $task->deferred;
        $task->deferred = null;
        if ($deferred === null) {
            return;
        }
        $class = $this->classResult;
        $result = new $class($task->toQuery());
        $task->ch = null;
        try {
            if (!$result->hasError()) {
                $deferred->resolve($result);
            } else {
                $deferred->reject(new curlException($result));
            }
        } catch (\Throwable $exception) {
            $this->logError('reactCurl: exception while settling a request', $exception);
        }
    }

    protected function fail(curlTask $task, $exceptionClass, $type, $message, $code)
    {
        $task->state = curlTask::DONE;
        $this->failed[] = [$task, $exceptionClass, ['type' => $type, 'message' => $message, 'code' => $code]];
    }

    protected function expire(curlTask $task)
    {
        $this->fail($task, deadlineException::class, 'network',
            sprintf('Deadline of %.3f seconds exceeded before the request started', $task->deadline), CURLE_OPERATION_TIMEDOUT);
    }

    protected function flushFailed()
    {
        while ($this->failed) {
            [$task, $exceptionClass, $failure] = array_shift($this->failed);
            $deferred = $task->deferred;
            $task->deferred = null;
            if ($deferred === null) {
                continue;
            }
            $class = $this->classResult;
            $result = new $class($task->toQuery() + ['failure' => $failure]);
            $task->ch = null;
            try {
                $deferred->reject(new $exceptionClass($result));
            } catch (\Throwable $exception) {
                $this->logError('reactCurl: exception while rejecting a request', $exception);
            }
        }
    }

    /**
     * Reject failures on a future tick, not inside add()
     */
    protected function scheduleFlush()
    {
        if ($this->flushScheduled || $this->inTick) {
            return;
        }
        $this->flushScheduled = true;
        $this->loop->futureTick(function () {
            $this->flushScheduled = false;
            $this->flushFailed();
        });
    }

    protected function cancelTask(curlTask $task, callable $reject)
    {
        if ($task->state === curlTask::DONE) {
            return;
        }
        if ($task->state === curlTask::QUEUED) {
            $this->queued--;
        } else {
            curl_multi_remove_handle($this->mh, $task->ch);
            unset($this->active[spl_object_id($task->ch)]);
            $this->finished++;
        }
        $task->state = curlTask::DONE;
        $task->deferred = null;
        $class = $this->classResult;
        $result = new $class($task->toQuery() + [
            'failure' => ['type' => 'client', 'message' => 'Request cancelled', 'code' => CURLE_ABORTED_BY_CALLBACK]
        ]);
        $task->ch = null;
        $reject(new cancelledException($result));
    }

    /**
     * Drop expired and cancelled requests from the head of the queue
     */
    protected function expireQueued()
    {
        $now = null;
        while (!$this->queue->isEmpty()) {
            /** @var curlTask $task */
            $task = $this->queue->bottom();
            if ($task->state !== curlTask::QUEUED) {
                $this->queue->dequeue();
                continue;
            }
            if ($task->expiresAt === null || $task->expiresAt > ($now = $now ?? microtime(true))) {
                return;
            }
            $this->queue->dequeue();
            $this->queued--;
            $this->expire($task);
        }
    }

    /**
     * Free slots the requests added now take on the next tick
     *
     * @return int
     */
    protected function slotsOnKick()
    {
        if ($this->inTick || $this->isLegacy() || ($this->timer === null && !$this->autoRun)) {
            return 0;
        }

        return max(0, $this->maxRequest - count($this->active));
    }

    /**
     * Start what add() queued on the next tick, as one batch like khr
     */
    protected function scheduleKick()
    {
        if ($this->kickScheduled) {
            return;
        }
        $this->kickScheduled = true;
        $this->loop->futureTick(function () {
            $this->kickScheduled = false;
            if (!$this->inTick && !$this->isLegacy() && $this->queued > 0) {
                $this->kick();
            }
        });
    }

    /**
     * Start what add() queued while the pool is running
     */
    protected function kick()
    {
        if ($this->fill() > 0) {
            $this->exec();
        }
        if ($this->failed) {
            $this->scheduleFlush();
        }
        $this->interval = $this->minInterval;
        $this->idleTicks = 0;
        $this->schedule($this->pollMode === self::POLL_SELECT ? 0.0001 : $this->minInterval);
    }

    /**
     * One polling round (adaptive and select modes)
     */
    protected function tick()
    {
        if ($this->isLegacy()) {
            $this->legacyTick();

            return;
        }
        if ($this->inTick) {
            return;
        }
        $this->inTick = true;
        try {
            $this->expireQueued();
            if ($this->active) {
                if ($this->pollMode === self::POLL_SELECT && $this->selectTimeout > 0) {
                    curl_multi_select($this->mh, $this->selectTimeout);
                }
                $this->exec();
            }
            $finished = $this->readFinished();
            // like khr: refill before and after the callbacks
            $started = $this->fill();
            if ($started > 0) {
                $this->exec();
            }
            foreach ($finished as $task) {
                $this->settle($task);
            }
            $this->flushFailed();
            $startedAfter = $this->fill();
            if ($startedAfter > 0) {
                $this->exec();
            }
            $this->flushFailed();
        } finally {
            $this->inTick = false;
        }
        $this->reschedule($finished || $started > 0 || $startedAfter > 0);
    }

    protected function reschedule($progress)
    {
        if (!$this->active && $this->queued === 0 && !$this->failed) {
            $this->stopTimer();
            $this->interval = $this->minInterval;
            $this->idleTicks = 0;

            return;
        }
        if ($progress) {
            $this->interval = $this->minInterval;
            $this->idleTicks = 0;
        } elseif (++$this->idleTicks > self::ADAPTIVE_HOLD_TICKS) {
            $this->interval = min($this->interval * 2, $this->maxInterval);
        }
        if (!$this->active) {
            // only throttled requests are waiting
            $interval = max($this->interval, $this->maxInterval);
        } elseif ($this->pollMode === self::POLL_SELECT) {
            $interval = 0.0001;
        } else {
            $interval = $this->interval;
        }
        $this->schedule($interval);
    }

    protected function schedule($interval)
    {
        if ($this->timer !== null) {
            if (abs($this->timerInterval - $interval) < 1e-9) {
                return;
            }
            $this->loop->cancelTimer($this->timer);
        }
        $this->timerInterval = $interval;
        $this->timer = $this->loop->addPeriodicTimer($interval, function () {
            $this->tick();
        });
    }

    protected function stopTimer()
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
            $this->timerInterval = null;
        }
    }

    /**
     * Legacy pump: fixed 10 ms period and the call order of khr/react-curl
     */
    protected function legacyRun()
    {
        $this->legacyStep();
        $this->legacySettle();
        if ($this->timer === null || $this->timerInterval !== self::LEGACY_INTERVAL) {
            $this->schedule(self::LEGACY_INTERVAL);
        }
    }

    protected function legacyTick()
    {
        if ($this->inTick) {
            return;
        }
        $this->legacyRun();
        $busy = $this->legacyStep();
        if (!($busy || $this->legacyPending || $this->failed)) {
            $this->stopTimer();
        }
    }

    /**
     * Exec, read and start in the legacy order
     *
     * @return bool something is queued or running
     */
    protected function legacyStep()
    {
        $this->inTick = true;
        try {
            if ($this->legacyRunning) {
                $this->exec();
                foreach ($this->readFinished() as $task) {
                    $this->legacyPending[] = $task;
                }
                if ($this->legacyFill() || $this->active) {
                    return true;
                }

                return $this->legacyRunning = false;
            }

            return $this->legacyFill();
        } finally {
            $this->inTick = false;
        }
    }

    protected function legacyFill()
    {
        $this->expireQueued();
        if ($this->queued === 0) {
            return false;
        }
        $this->fill();

        return $this->legacyRunning = true;
    }

    protected function legacySettle()
    {
        $this->inTick = true;
        try {
            while ($this->legacyPending) {
                $this->settle(array_shift($this->legacyPending));
            }
            $this->flushFailed();
        } finally {
            $this->inTick = false;
        }
    }
}
