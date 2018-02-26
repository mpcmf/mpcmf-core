<?php

namespace mpcmf\system\cache;

use mpcmf\system\cache\exception\cacheException;
use mpcmf\system\configuration\config;
use mpcmf\system\helper\system\profiler;
use mpcmf\system\pattern\factory;

/**
 * Memcached driver wrapper for mpr_cache package
 *
 * @author GreeveX <greevex@gmail.com>
 */
class memcached
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

        return $this->memcached->set($key, $value, $expire);
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

        return $this->memcached->setMulti($items, $expire);
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

        return $this->memcached->touch($key, $expire);
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

        return $this->memcached->add($key, $value, $expire);
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

        return $this->memcached->get($key);
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

        $value = $this->memcached->get($key);
        if($value === false) {
            $value = $initial;
        } else {
            $value += $howMany;
        }

        $this->memcached->set($key, $value, $expire);

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

        $value = $this->memcached->get($key);
        if($value === false) {
            $value = $initial;
        } else {
            $value -= $howMany;
        }

        $this->memcached->set($key, $value, $expire);

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

        $data = $this->memcached->get($key);
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

        return $this->memcached->delete($key);
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

        return $this->memcached->flush();
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