<?php

namespace mpcmf\system\cache;

use mpcmf\system\cache\exception\cacheException;
use mpcmf\system\configuration\config;
use mpcmf\system\helper\system\profiler;
use mpcmf\system\pattern\factory;

/**
 * Memcached driver wrapper
 *
 * @author GreeveX <greevex@gmail.com>
 */
class memcached implements cacheInterface
{
    use factory {
        __construct as protected factoryConstruct;
    }

    /**
     * Memcached native driver instance
     *
     * @var \Memcached
     */
    protected $memcached;

    protected $pid;

    /**
     * Connection errors an idempotent request is repeated after
     */
    const RETRY_RESULT_CODES = [
        \Memcached::RES_FAILURE,
        \Memcached::RES_CONNECTION_FAILURE,
        \Memcached::RES_WRITE_FAILURE,
        \Memcached::RES_READ_FAILURE,
        \Memcached::RES_UNKNOWN_READ_FAILURE,
        \Memcached::RES_ERRNO,
        \Memcached::RES_SERVER_MARKED_DEAD,
    ];

    /**
     * errno of a write to a connection the server has closed (EPIPE on Linux)
     */
    const ERRNO_BROKEN_PIPE = 32;

    /**
     * Backend methods safe to repeat when their reply was lost: applied twice they give the same state and result
     */
    const IDEMPOTENT_METHODS = [
        'get' => true,
        'getbykey' => true,
        'getmulti' => true,
        'getmultibykey' => true,
        'getdelayed' => true,
        'getdelayedbykey' => true,
        'set' => true,
        'setbykey' => true,
        'setmulti' => true,
        'setmultibykey' => true,
        'delete' => true,
        'deletebykey' => true,
        'deletemulti' => true,
        'deletemultibykey' => true,
        'touch' => true,
        'touchbykey' => true,
        'flush' => true,
        'getstats' => true,
        'getversion' => true,
        'getallkeys' => true,
    ];

    /**
     * Commit changes
     *
     * @not implemented for this driver!
     * @throws \Exception
     */
    public function commit()
    {
        throw new cacheException('Transactions not implemented');
    }

    /**
     * Enable auto commit changes
     *
     * @not implemented for this driver!
     * @throws \Exception
     */
    public function enableAutoCommit()
    {
        throw new cacheException('Transactions not implemented');
    }

    /**
     * Disable auto commit changes
     *
     * @not implemented for this driver!
     * @throws \Exception
     */
    public function disableAutoCommit()
    {
        throw new cacheException('Transactions not implemented');
    }

    /**
     * Initialize driver and connect to host
     *
     * @var string $configSection
     * @throws cacheException
     */
    public function __construct($configSection = 'default')
    {
        $this->factoryConstruct($configSection);
        $config = config::getConfig(__CLASS__);
        if(!isset($config[$configSection])) {
            throw new cacheException("Config section {$configSection} not found!");
        }
        $this->memcached = new \Memcached();
        foreach($config[$configSection]['servers'] as $server) {
            $this->memcached->addServer($server['host'], $server['port']);
        }
    }

    protected function reconnect()
    {
        $config = config::getConfig(__CLASS__)[$this->configSection];
        $this->memcached = new \Memcached();
        foreach ($config['servers'] as $server) {
            $this->memcached->addServer($server['host'], $server['port']);
        }
    }

    /**
     * Call the backend, once more on a connection error
     *
     * A process forked from this one closes the shared connection when it releases its inherited copy of the driver
     * (libmemcached sends `quit`), the next request here fails once and libmemcached reconnects. Such a request is
     * never applied: the server stops reading the connection at `quit`, and the write fails (RES_ERRNO with
     * errno EPIPE, or RES_WRITE_FAILURE). A request whose reply was lost for another reason (read failure,
     * timeout, connection closed after the request was sent) may have been applied by the server.
     *
     * | backend methods                                                  | repeated once after                       |
     * |------------------------------------------------------------------|-------------------------------------------|
     * | idempotent (IDEMPOTENT_METHODS): get*, set*, delete*, touch*,     | any connection error (RETRY_RESULT_CODES) |
     * | flush, getStats, getVersion, getAllKeys                          |                                           |
     * | others: add*, increment*, decrement*, cas*, append*, prepend*,   | only a request that was not sent:         |
     * | replace*, ... (applied twice: NOTSTORED of add, double increment) | RES_WRITE_FAILURE, RES_ERRNO with errno   |
     * |                                                                  | EPIPE (getLastErrorErrno() === 32)        |
     *
     * @param string $method
     * @param mixed  ...$arguments
     *
     * @return mixed
     */
    protected function call($method, ...$arguments)
    {
        $result = $this->memcached->{$method}(...$arguments);
        if ($result === false && $this->isRetryable($method)) {
            $result = $this->memcached->{$method}(...$arguments);
        }

        return $result;
    }

    /**
     * @param string $method backend method that failed
     *
     * @return bool
     */
    protected function isRetryable($method)
    {
        $code = $this->memcached->getResultCode();
        if (isset(self::IDEMPOTENT_METHODS[strtolower($method)])) {

            return in_array($code, self::RETRY_RESULT_CODES, true);
        }

        return $this->isNotSent($code);
    }

    /**
     * @param int $code result code of the failed request
     *
     * @return bool the request did not reach the server
     */
    protected function isNotSent($code)
    {
        if ($code === \Memcached::RES_WRITE_FAILURE) {

            return true;
        }

        // write() to a connection closed by the server: EPIPE is reported only for a request that was not sent
        return $code === \Memcached::RES_ERRNO && method_exists($this->memcached, 'getLastErrorErrno')
            && $this->memcached->getLastErrorErrno() === self::ERRNO_BROKEN_PIPE;
    }

    protected function checkConnection()
    {
        $currentPid = getmypid();
        if($currentPid !== $this->pid) {
            $this->pid = $currentPid;
            $this->reconnect();
        }
    }

    /**
     * Set value by key
     *
     * @param string $key
     * @param mixed $value
     * @param int $expire
     *
     * @return bool|mixed
     */
    public function set($key, $value, $expire = 60)
    {
        profiler::addStack('memcached::w');

        $this->checkConnection();

        return $this->call('set', $key, $value, $expire);
    }

    /**
     * Set value by items
     *
     * @param array $items
     * @param int $expire
     *
     * @return bool|mixed
     */
    public function setMulti($items, $expire = 60)
    {
        profiler::addStack('memcached::w');

        $this->checkConnection();

        return $this->call('setMulti', $items, $expire);
    }

    /**
     * Update expiration time
     *
     * @param string $key
     * @param int $expire
     *
     * @return bool|mixed
     */
    public function touch($key, $expire = 60)
    {
        profiler::addStack('memcached::w');

        $this->checkConnection();

        return $this->call('touch', $key, $expire);
    }

    /**
     * Add value by key
     *
     * @param string $key
     * @param mixed $value
     * @param int $expire
     *
     * @return bool|mixed
     */
    public function add($key, $value, $expire = 60)
    {
        profiler::addStack('memcached::w');

        $this->checkConnection();

        return $this->call('add', $key, $value, $expire);
    }

    /**
     * Get value by key
     *
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        profiler::addStack('memcached::r');

        $this->checkConnection();

        return $this->call('get', $key);
    }

    /**
     * Increment by key
     *
     * @param string $key
     * @param int    $howMany
     *
     * @param int    $initial
     * @param int    $expire
     *
     * @return mixed
     */
    public function inc($key, $howMany = 1, $initial = 0, $expire = 0)
    {
//        return $this->memcached->increment($key, $howMany, $initial, $expire);
        profiler::addStack('memcached::rw');

        $this->checkConnection();

        $value = $this->call('get', $key);
        if($value === false) {
            $value = $initial;
        } else {
            $value += $howMany;
        }

        $this->call('set', $key, $value, $expire);

        return $value;
    }

    /**
     * Decrement by key
     *
     * @param string $key
     * @param int    $howMany
     * @param int    $initial
     * @param int    $expire
     *
     * @return mixed
     */
    public function dec($key, $howMany = 1, $initial = 0, $expire = 0)
    {
//        return $this->memcached->decrement($key, $howMany, $initial, $expire);
        profiler::addStack('memcached::rw');

        $this->checkConnection();

        $value = $this->call('get', $key);
        if($value === false) {
            $value = $initial;
        } else {
            $value -= $howMany;
        }

        $this->call('set', $key, $value, $expire);

        return $value;
    }

    /**
     * Check is key exists
     *
     * @param string $key
     * @return bool
     */
    public function exists($key)
    {
        profiler::addStack('memcached::r');

        $this->checkConnection();

        $data = $this->call('get', $key);
        return !($data === false || $data === null);
    }

    /**
     * Remove record from cache by key
     *
     * @param string $key
     * @return bool
     */
    public function remove($key)
    {
        profiler::addStack('memcached::w');

        $this->checkConnection();

        return $this->call('delete', $key);
    }

    /**
     * WARNING! Clear all cache!
     *
     * @return bool
     */
    public function clear()
    {
        profiler::addStack('memcached::w');

        $this->checkConnection();

        return $this->call('flush');
    }

    /**
     * Return last error
     *
     * @return mixed
     */
    public function getResultCode()
    {
        $this->checkConnection();

        return $this->memcached->getResultCode();
    }

    /**
     * Cache driver backend
     *
     * @return mixed
     */
    public function getBackend()
    {
        $this->checkConnection();

        return $this->memcached;
    }
}