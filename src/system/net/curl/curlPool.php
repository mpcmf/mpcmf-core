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
 * Asynchronous HTTP client: a curl_multi pool driven by a ReactPHP event loop.
 *
 * Replaces khr/react-curl + khr/php-mcurl-client (abandoned) under mpcmf\system\net\reactCurl,
 * written from scratch. Keeps their observable behaviour (results, errors, throttling, client
 * options, MCurl\Client setters) and fixes:
 *  - an option failing with TypeError/ValueError rejects only its request with
 *    exception\optionException, the pool keeps working (was: slots leaked, run() never finished);
 *    an option libcurl rejects with false is skipped with a warning and all other options are
 *    applied (was: the rest of the options was lost), see setStrictOptions();
 *  - timers are cancelled with $loop->cancelTimer(): works with react/event-loop 0.4 and 1.x;
 *  - setSleep() with next < 1 no longer divides by zero;
 *  - transfers start right after add() when the pool is running, the poll timer adapts its
 *    interval (1 ms after progress, backs off to 10 ms when idle) instead of a fixed 10 ms;
 *  - exceptions while settling are logged instead of breaking the loop.
 *
 * PHP cannot hand curl sockets to the event loop, so transfers are polled from a periodic timer.
 * Poll modes: adaptive (default), select (curl_multi_select() up to N ms inside the tick: lowest
 * latency, but blocks other loop work), legacy (fixed 10 ms and the call order of khr/react-curl;
 * also used while a blocking setSleep() is active, to keep its timing).
 */
class curlPool
{
    const POLL_ADAPTIVE = 'adaptive';
    const POLL_SELECT = 'select';
    const POLL_LEGACY = 'legacy';

    /** Result written to memory (MCurl\Client compatible) */
    const STREAM_MEMORY = 'php://memory';

    /** Result written to temporary files (MCurl\Client compatible) */
    const STREAM_FILE = 'php://temp/maxmemory:0';

    private const LEGACY_INTERVAL = 0.01;

    /** Ticks without progress before the adaptive interval starts to grow */
    private const ADAPTIVE_HOLD_TICKS = 5;

    /** @var LoopInterface */
    protected $loop;

    /** @var \CurlMultiHandle */
    protected $mh;

    /** @var \CurlShareHandle|null */
    protected $sh;

    /** @var array client-level options, task options win */
    protected $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ];

    /** @var int */
    protected $maxRequest = 10;

    /** @var sleepThrottle */
    protected $throttle;

    /** @var string */
    protected $classResult = curlResult::class;

    /** @var string|null */
    protected $baseUrl;

    /** @var string|null */
    protected $streamResult;

    /** @var array */
    protected $streamFilters = [];

    /** @var bool */
    protected $enableHeaders = false;

    /** @var \SplQueue of curlTask */
    protected $queue;

    /** @var int queued tasks (without cancelled/expired ones still in $queue) */
    protected $queued = 0;

    /** @var curlTask[] by spl_object_id() of the handle */
    protected $active = [];

    /** @var array[] [curlTask, exception class, failure] waiting to be rejected */
    protected $failed = [];

    /** @var bool */
    protected $flushScheduled = false;

    /** @var int finished requests, drives the throttle */
    protected $finished = 0;

    /** @var \React\EventLoop\TimerInterface|\React\EventLoop\Timer\TimerInterface|null */
    protected $timer;

    /** @var float|null */
    protected $timerInterval;

    /** @var float */
    protected $interval;

    /** @var int */
    protected $idleTicks = 0;

    /** @var bool */
    protected $inTick = false;

    /** @var string */
    protected $pollMode = self::POLL_ADAPTIVE;

    /** @var float */
    protected $minInterval = 0.001;

    /** @var float */
    protected $maxInterval = 0.01;

    /** @var float */
    protected $selectTimeout = 0.005;

    /** @var bool legacy pump: transfers were added and not finished yet */
    protected $legacyRunning = false;

    /** @var curlTask[] legacy pump: finished, not settled yet */
    protected $legacyPending = [];

    /** @var int 0 - unlimited */
    protected $maxQueue = 0;

    /** @var float|null */
    protected $deadline;

    /** @var bool */
    protected $cancellable = false;

    /** @var bool */
    protected $autoRun = false;

    /** @var bool */
    protected $cookieShare = false;

    /** @var bool */
    protected $strictOptions = false;

    /** @var callable|null function (string $message, array $context) */
    protected $errorLogger;

    /** @var float seconds, per message key and process */
    private static $logWindow = 60.0;

    /** @var array key => [window start, suppressed count] */
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
     * Queue a request.
     *
     * @param array $opts curl options of this request (returned by Result::getOptions())
     * @param array|string $params data bound to the request (Result::getParams()); a string is the request id
     *
     * @return PromiseInterface resolves with \KHR\React\Curl\Result, rejects with \KHR\React\Curl\Exception
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
        if ($this->maxQueue > 0 && $this->queued >= $this->maxQueue) {
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
                $this->kick();
            }
        }

        return $promise;
    }

    /**
     * Start (or continue) processing: settles what is ready and keeps polling from a loop timer
     * until all requests are settled. Requests added while the pool runs start without run().
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
    }

    /**
     * Client-level curl options, applied to every request after its own options
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
     * @return array client-level curl options
     */
    public function getCurlOption()
    {
        return $this->curlOptions;
    }

    /**
     * @param int $next sleep after every $next requests (< 1 is treated as 1)
     * @param float $second
     * @param bool $blocking usleep() inside the loop instead of postponing requests
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
     * @see curl_share_setopt()
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
     * Write bodies into streams of this wrapper (self::STREAM_*), see Result::getBodyStream().
     * Note: with CURLOPT_RETURNTRANSFER on (reactCurl default) the body is returned, the stream stays empty.
     *
     * @param string|null $stream
     */
    public function setStreamResult($stream)
    {
        $this->streamResult = $stream;
    }

    /**
     * @param array $filters stream filter names appended to result streams
     *
     * @return $this
     */
    public function setStreamFilters(array $filters)
    {
        $this->streamFilters = $filters;

        return $this;
    }

    /**
     * @param string|null $baseUrl prefix for CURLOPT_URL of added requests
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param string $name result class, subclass of \MCurl\Result
     */
    public function setClassResult($name)
    {
        $this->classResult = ltrim($name, '\\');
    }

    /**
     * MCurl\Client compatibility: true switches to the select poll mode.
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
     * @return int queued (not started) requests
     */
    public function getCountQuery()
    {
        return $this->queued;
    }

    /**
     * @return int running transfers
     */
    public function getActiveCount()
    {
        return count($this->active);
    }

    /**
     * Adaptive poll interval: $min after progress, doubling up to $max while nothing happens.
     * setPollInterval(0.01, 0.01) gives the old fixed 10 ms period.
     *
     * @param float $min seconds
     * @param float|null $max seconds, default max($min, 0.01)
     */
    public function setPollInterval($min, $max = null)
    {
        $this->minInterval = max(0.0001, (float)$min);
        $this->maxInterval = max($this->minInterval, $max === null ? 0.01 : (float)$max);
        $this->interval = $this->minInterval;
    }

    /**
     * @param string $mode self::POLL_ADAPTIVE (default), self::POLL_SELECT (opt-in: blocks the loop
     *                     in curl_multi_select() up to $selectTimeout while transfers run) or
     *                     self::POLL_LEGACY (fixed 10 ms, call order of khr/react-curl)
     * @param float $selectTimeout seconds, for self::POLL_SELECT
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
     * Opt-in: reject add() with exception\queueFullException when $max requests are queued already.
     *
     * @param int $max 0 - unlimited (default)
     */
    public function setMaxQueue($max)
    {
        $this->maxQueue = max(0, (int)$max);
    }

    /**
     * Opt-in: total time limit per request including the queue wait, for requests added afterwards.
     * Expired queued requests are rejected with exception\deadlineException (code 28), running
     * ones get CURLOPT_TIMEOUT_MS limited to the time left (curl error 28).
     *
     * @param float|null $seconds null - off (default)
     */
    public function setDeadline($seconds)
    {
        $this->deadline = $seconds === null ? null : max(0.001, (float)$seconds);
    }

    /**
     * Opt-in: $promise->cancel() removes the request from the queue or aborts the transfer and
     * rejects it with exception\cancelledException. Off by default: cancel() does nothing (as before).
     *
     * @param bool $enable
     */
    public function setCancellable($enable = true)
    {
        $this->cancellable = (bool)$enable;
    }

    /**
     * Opt-in: add() starts processing without an explicit run().
     *
     * @param bool $enable
     */
    public function setAutoRun($enable = true)
    {
        $this->autoRun = (bool)$enable;
    }

    /**
     * Opt-in: keep cookies in memory, shared by all requests of this pool (curl_share), instead of
     * reading and rewriting the cookie file on every request. Applies to requests that do not set
     * their own CURLOPT_COOKIEFILE/CURLOPT_COOKIEJAR; the cookie file is neither read nor written.
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
     * Opt-in: reject the request (exception\optionException) when libcurl rejects one of its options
     * with false. Default: the option is skipped with a warning, the request runs with the others.
     * TypeError/ValueError of an option always reject the request.
     *
     * @param bool $enable
     */
    public function setStrictOptions($enable = true)
    {
        $this->strictOptions = (bool)$enable;
    }

    /**
     * Log lines of the engine (callback exceptions, skipped options) are written at most once per
     * message key per $seconds per process, with the count of suppressed repeats. Resets the state.
     *
     * @param float $seconds 0 - no limit
     */
    public static function setLogRateLimit($seconds)
    {
        self::$logWindow = max(0.0, (float)$seconds);
        self::$logSeen = [];
    }

    /**
     * @param callable|null $logger function (string $message, array $context): void, context has 'level'
     *                              (error|warning) and 'exception'; null - mpcmf log
     */
    public function setErrorLogger($logger)
    {
        $this->errorLogger = $logger;
    }

    /**
     * Log an error through the configured logger (mpcmf log by default, error_log() as fallback),
     * rate-limited per $key (default: the message)
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
            $context['exception'] = get_class($exception) . ': ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine();
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
     * Result streams of MCurl\Client: CURLOPT_FILE/CURLOPT_WRITEHEADER are opened at add() time.
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
     * Start queued requests allowed by max requests and the throttle.
     *
     * @return int started transfers
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
     * @return bool false when the request was rejected
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
            // counts as a finished request, like a failed transfer did
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
        // integer FOLLOWLOCATION (e.g. 20) is rejected by PHP 8.5; libcurl >= 8.13 gives 2 and 3 a meaning
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
     * curl_setopt_array() stops at the first option libcurl rejects with false (khr lost the rest
     * of the options this way). Set every option separately: by default a rejected option is
     * skipped with a (rate-limited) warning and the request runs with all other options; in strict
     * mode the request is rejected.
     *
     * @return string|null error that rejects the request
     *
     * @throws \Throwable TypeError/ValueError of an option
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
            $this->log('warning', "reactCurl: curl rejected {$name}, the option is skipped (url " . ($task->opts[CURLOPT_URL] ?? '') . ')', null, "option:{$name}");
        }

        return null;
    }

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
     * @return curlTask[] finished transfers (handles removed from the multi handle)
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
        $this->fail($task, deadlineException::class, 'network', sprintf('Deadline of %.3f seconds exceeded before the request started', $task->deadline), CURLE_OPERATION_TIMEDOUT);
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
     * Reject failures outside of the caller's stack (add() must not call callbacks synchronously)
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
        $result = new $class($task->toQuery() + ['failure' => ['type' => 'client', 'message' => 'Request cancelled', 'code' => CURLE_ABORTED_BY_CALLBACK]]);
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
     * Start what add() queued while the pool is running, without waiting for the next tick
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
            // like khr: refill before the callbacks run, then once more for what they queued
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

    /*
     * Legacy pump: fixed 10 ms period and the call order of khr/react-curl + MCurl\Client:
     * run() = [exec, read, throttle+start] + settle; tick = run() + [exec, read, throttle+start],
     * the second batch of results is settled on the next tick. The throttle in blocking mode
     * usleep()s inside these checks, so its effective timing depends on this order.
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
