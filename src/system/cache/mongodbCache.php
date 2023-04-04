<?php

namespace mpcmf\system\cache;


class mongodbCache implements cacheInterface
{
    /**
     * @var \MongoCollection
     */
    private $collection;

    public function __construct(\MongoCollection $collection)
    {
        $this->collection = $collection;
        $this->init($this->collection);
    }

    protected function init(\MongoCollection $collection)
    {
        $collection->createIndex(['e' => 1], ['expireAfterSeconds' => 0]);
        $collection->createIndex(['k' => 1], ['unique' => true]);
    }

    /**
     * @param $key
     * @param $value
     * @param $expire
     *
     * @return bool
     * @throws \MongoCursorException
     * @throws \MongoException
     * @throws \MongoWriteConcernException
     */
    public function set($key, $value, $expire = 60)
    {
        $item = [
            'k' => $key,
            'v' => serialize($value),
            'e' => new \MongoDate(time() + $expire)
        ];

        $result = $this->collection->update(['k' => $key], ['$set' => $item], ['upsert' => true]);

        return $this->checkResult($result);
    }

    protected function checkResult($result): bool
    {
        if (is_bool($result)) {
            return $result;
        }

        return $result['ok'] === 1.0;
    }

    /**
     * @param $key
     * @param $expire
     *
     * @return bool
     * @throws \MongoCursorException
     * @throws \MongoException
     * @throws \MongoWriteConcernException
     */
    public function touch($key, $expire = 60)
    {
        $expire = [
            'e' => new \MongoDate(time() + $expire)
        ];

        $result = $this->collection->update(['k' => $key], ['$set' => $expire]);

        return $this->checkResult($result);
    }

    /**
     * @param $key
     * @param $value
     * @param $expire
     *
     * @return bool
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \MongoException
     */
    public function add($key, $value, $expire = 60)
    {
        $item = [
            'k' => $key,
            'v' => serialize($value),
            'e' => new \MongoDate(time() + $expire)
        ];

        try {
            $this->collection->insert($item);
        } catch (\MongoException $e) {
            if ($e->getCode() === 11000) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * @param $key
     *
     * @return mixed
     * @throws \MongoConnectionException
     * @throws \MongoCursorException
     * @throws \MongoDuplicateKeyException
     * @throws \MongoException
     * @throws \MongoExecutionTimeoutException
     * @throws \MongoWriteConcernException
     */
    public function get($key)
    {
        $object = $this->collection->findOne(['k' => $key]);
        if ($object === null) {
            return false;
        }

        return unserialize($object['v']);
    }

    /**
     * @param $key
     *
     * @return bool
     * @throws \MongoConnectionException
     * @throws \MongoCursorException
     * @throws \MongoDuplicateKeyException
     * @throws \MongoException
     * @throws \MongoExecutionTimeoutException
     * @throws \MongoWriteConcernException
     */
    public function exists($key)
    {
        return $this->collection->findOne(['k' => $key]) !== null;
    }

    /**
     * @param $key
     *
     * @return bool
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     */
    public function remove($key)
    {
        $result = $this->collection->remove(['k' => $key]);

        return $this->checkResult($result);
    }

    /**
     * @param $key
     * @param $howMany
     * @param $initial
     * @param $expire
     *
     * @return int
     * @throws \MongoConnectionException
     * @throws \MongoCursorException
     * @throws \MongoDuplicateKeyException
     * @throws \MongoException
     * @throws \MongoExecutionTimeoutException
     * @throws \MongoWriteConcernException
     */
    public function inc($key, $howMany = 1, $initial = 0, $expire = 0)
    {
        $value = $this->get($key);
        if($value === false) {
            $value = $initial;
        } else {
            $value += $howMany;
        }

        $this->set($key, $value, $expire);

        return $value;
    }

    /**
     * @param $key
     * @param $howMany
     * @param $initial
     * @param $expire
     *
     * @return int
     * @throws \MongoConnectionException
     * @throws \MongoCursorException
     * @throws \MongoDuplicateKeyException
     * @throws \MongoException
     * @throws \MongoExecutionTimeoutException
     * @throws \MongoWriteConcernException
     */
    public function dec($key, $howMany = 1, $initial = 0, $expire = 0)
    {
        $value = $this->get($key);
        if($value === false) {
            $value = $initial;
        } else {
            $value -= $howMany;
        }

        $this->set($key, $value, $expire);

        return $value;
    }

    /**
     * @return bool
     */
    public function clear()
    {
        $this->collection->drop();
        $this->init($this->collection);

        return true;
    }
}