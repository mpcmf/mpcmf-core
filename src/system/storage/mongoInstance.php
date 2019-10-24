<?php

namespace mpcmf\system\storage;

use mpcmf\system\configuration\exception\configurationException;
use mpcmf\system\helper\cache\cache;
use mpcmf\system\helper\io\log;
use mpcmf\system\helper\system\profiler;
use mpcmf\system\pattern\factory;

/**
 * MongoDB accessor class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @package mpcmf\system\storage
 */
class mongoInstance
{
    use factory, log, cache;

    /**
     * @var \MongoClient
     */
    private $mongo;

    private $pid;

    /**
     * Sets custom \MongoClient
     *
     * @param \MongoClient $mongoClient
     */
    public function setMongo(\MongoClient $mongoClient)
    {
        $this->pid = getmypid();
        $this->mongo = $mongoClient;
    }

    /**
     * Return \MongoClient instance for current configuration
     *
     * @return \MongoClient
     * @throws configurationException
     * @throws \MongoConnectionException
     */
    public function getMongo()
    {
        $currentPid = getmypid();
        if ($this->mongo === null || $this->pid !== $currentPid) {
            $config = $this->getPackageConfig();
            MPCMF_LL_DEBUG && self::log()->addDebug("Connecting to {$config['uri']}", [__METHOD__]);
            $this->mongo = new \MongoClient($config['uri'], $config['options']);
            $this->pid = $currentPid;
        }

        return $this->mongo;
    }

    /**
     * Get mongo cursor by params
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $fields
     *
     * @return \MongoCursor
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function select($db, $collection, $criteria = [], $fields = [])
    {
        profiler::addStack('mongo::r');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->find($criteria, $fields);
    }

    /**
     * Get single item by params
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $fields
     *
     * @return array|null
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function selectOne($db, $collection, $criteria = [], $fields = [])
    {
        profiler::addStack('mongo::r');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->findOne($criteria, $fields);
    }

    /**
     * Get single item by params and update like native mongo update
     *
     * In the default way it's rewrite all fields that typed in $newObject
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $newObject
     * @param array  $selectFields
     * @param array  $options
     *
     * @return array
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function selectAndModify($db, $collection, $criteria, $newObject, $selectFields = [], $options = [])
    {
        profiler::addStack('mongo::rw');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->findAndModify($criteria, $newObject, $selectFields, $options);
    }

    /**
     * Get single item by params and update it by typed fields. Other fields would not be changed
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $modifyFields
     * @param array  $selectFields
     * @param array  $options
     *
     * @return array
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function selectAndModifyFields($db, $collection, $criteria, $modifyFields, $selectFields = [], $options = [])
    {
        profiler::addStack('mongo::rw');

        $updateObject = ['$set' => $modifyFields];

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->findAndModify($criteria, $updateObject, $selectFields, $options);
    }

    /**
     * Basic item update method
     *
     * In the default way it's rewrite all fields that typed in $newObject
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $newObject
     * @param array  $options
     *
     * @return bool
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \Exception
     */
    public function update($db, $collection, $criteria, $newObject, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->update($criteria, $newObject, $options);
    }

    /**
     * Update item by typed fields. Other fields would not be changed
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $fields
     * @param array  $options
     *
     * @return bool
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \Exception
     */
    public function updateFields($db, $collection, $criteria, $fields, $options = [])
    {
        profiler::addStack('mongo::w');

        $updateObject = [
            '$set' => $fields
        ];
        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->update($criteria, $updateObject, $options);
    }

    /**
     * Remove single item by params
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $options
     *
     * @return array|bool
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \Exception
     */
    public function removeOne($db, $collection, $criteria = [], $options = [])
    {
        profiler::addStack('mongo::d');

        $options = array_replace($options, [
            'justOne' => true
        ]);

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->remove($criteria, $options);
    }

    /**
     * Remove items by params
     *
     * @param string $db
     * @param string $collection
     * @param array  $criteria
     * @param array  $options
     *
     * @return array|bool
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \Exception
     */
    public function remove($db, $collection, $criteria = [], $options = [])
    {
        profiler::addStack('mongo::d');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->remove($criteria, $options);
    }

    /**
     * Insert new item to storage
     *
     * @param string $db
     * @param string $collection
     * @param array  $object
     * @param array  $options
     *
     * @return array|bool
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \MongoException
     * @throws \Exception
     */
    public function insert($db, $collection, $object, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->insert($object, $options);
    }

    /**
     * Insert new items to storage in single request (batch)
     *
     * @param string  $db
     * @param string  $collection
     * @param array[] $objects
     * @param array   $options
     *
     * @return mixed
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \Exception
     */
    public function insertBatch($db, $collection, $objects, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->batchInsert($objects, $options);
    }

    /**
     * Save item by itself _id
     *
     * @param string $db
     * @param string $collection
     * @param array  $object
     * @param array  $options
     *
     * @return array|bool
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \MongoException
     * @throws \Exception
     */
    public function save($db, $collection, $object, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->save($object, $options);
    }

    /**
     * Get native \MongoCollection instance by params
     *
     * @param string $db
     * @param string $collection
     *
     * @return \MongoCollection
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getCollection($db, $collection)
    {

        return $this->getMongo()->selectDB($db)->selectCollection($collection);
    }

    /**
     * Get native \MongoDB instance by params
     *
     * @param string $db
     *
     * @return \MongoDB
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     */
    public function getDb($db)
    {
        return $this->getMongo()->selectDB($db);
    }

    /**
     * Check and create indexes by params
     *
     * @param string $db
     * @param string $collection
     * @param array  $indexes
     *
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function checkIndexes($db, $collection, $indexes)
    {
        $log = self::log();
        $log->addDebug("Checking indexes for `{$collection}`");

        $collectionObject = $this->getMongo()->selectDB($db)->selectCollection($collection);
        $dbIndexes = $collectionObject->getIndexInfo();
        $needToCreate = [];
        $indexCount = count($dbIndexes);
        $needCreateAllIndexes = $indexCount <= 1;

        foreach ($indexes as $key => $index) {
            $log->addDebug('Checking index ' . json_encode($index));
            if ($needCreateAllIndexes) {
                $log->addInfo('NOT found index ' . json_encode($index));
                $needToCreate[$key] = true;
            } else {
                foreach ($dbIndexes as $dbIndex) {
                    if (json_encode($dbIndex['key']) === json_encode($index['keys'])) {
                        $log->addDebug('Found index ' . json_encode($index['keys']) . ', checking options...');
                        if (!empty($index['options'])) {
                            $log->addDebug('Options in cfg found');
                            $ok = true;
                            foreach ($index['options'] as $option => $optionValue) {
                                if (!isset($dbIndex[$option]) || $dbIndex[$option] !== $optionValue) {
                                    $log->addInfo('Option not found in dbIndex, need to create!');
                                    $ok = false;
                                    $needToCreate[$key] = true;
                                }
                            }
                            if ($ok) {
                                $log->addDebug('Index OK, skipping...');
                                unset($needToCreate[$key]);
                                break;
                            }
                        } else {
                            $log->addDebug('Options in cfg not found, Index OK, skipping...');
                            unset($needToCreate[$key]);
                            break;
                        }
                    } else {
                        $log->addInfo('Not found index ' . json_encode($index));
                        $needToCreate[$key] = true;
                    }
                }
            }
        }
        $log->addInfo('Need to create indexes: ' . count($needToCreate));
        foreach ($needToCreate as $key => $v) {
            profiler::addStack('mongo::i');
            $log->addInfo('Creating index ' . json_encode($indexes[$key]) . '...');
            if (array_key_exists('options', $indexes[$key])) {
                $collectionObject->ensureIndex($indexes[$key]['keys'], $indexes[$key]['options']);
            } else {
                $collectionObject->ensureIndex($indexes[$key]['keys']);
            }
            $log->addInfo('Index created ' . json_encode($indexes[$key]));
        }
    }

    /**
     * Periodically check and create indexes by params
     *
     * @param array $config ['db', 'collection', 'indices']
     *
     * @return bool
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function checkIndicesAuto($config)
    {
        static $cache, $instancePeriod;

        if ($cache === null || $instancePeriod === null) {
            $cache = self::cache();
            $instancePeriod = [];
        }
        $cacheKey = __METHOD__ . ":{$this->configSection}:" . md5(json_encode($config));
        if (!isset($instancePeriod[$cacheKey])) {
            $packageConfig = $this->getPackageConfig();
            $instancePeriod[$cacheKey] = 60;
            if (isset($packageConfig['auto_index_check_period']) && $packageConfig['auto_index_check_period']) {
                $instancePeriod[$cacheKey] = (int)$packageConfig['auto_index_check_period'];
            }
        }

        if (!$cache->add($cacheKey, true, $instancePeriod[$cacheKey])) {

            return false;
        }

        self::log()->addDebug("Need to check indexes for `{$config['db']}.{$config['collection']}`");
        $this->checkIndexes($config['db'], $config['collection'], $config['indices']);

        return true;
    }
}