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
class mongoInstance implements storageInterface
{
    use factory, log, cache;

    /**
     * @var \MongoClient
     */
    private $mongo;

    private $pid;

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

    public function select($db, $collection, $criteria = [], $fields = [])
    {
        profiler::addStack('mongo::r');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->find($criteria, $fields);
    }

    public function selectOne($db, $collection, $criteria = [], $fields = [])
    {
        profiler::addStack('mongo::r');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->findOne($criteria, $fields);
    }

    public function selectAndModify($db, $collection, $criteria, $newObject, $selectFields = [], $options = [])
    {
        profiler::addStack('mongo::rw');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->findAndModify($criteria, $newObject, $selectFields, $options);
    }

    public function selectAndModifyFields($db, $collection, $criteria, $modifyFields, $selectFields = [], $options = [])
    {
        profiler::addStack('mongo::rw');

        $updateObject = ['$set' => $modifyFields];

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->findAndModify($criteria, $updateObject, $selectFields, $options);
    }

    public function update($db, $collection, $criteria, $newObject, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->update($criteria, $newObject, $options);
    }

    public function updateFields($db, $collection, $criteria, $fields, $options = [])
    {
        profiler::addStack('mongo::w');

        $updateObject = [
            '$set' => $fields
        ];
        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->update($criteria, $updateObject, $options);
    }

    public function removeOne($db, $collection, $criteria = [], $options = [])
    {
        profiler::addStack('mongo::d');

        $options = array_replace($options, [
            'justOne' => true
        ]);

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->remove($criteria, $options);
    }

    public function remove($db, $collection, $criteria = [], $options = [])
    {
        profiler::addStack('mongo::d');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->remove($criteria, $options);
    }

    public function insert($db, $collection, $object, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->insert($object, $options);
    }

    public function insertBatch($db, $collection, $objects, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->batchInsert($objects, $options);
    }

    public function save($db, $collection, $object, $options = [])
    {
        profiler::addStack('mongo::w');

        return $this->getMongo()->selectDB($db)->selectCollection($collection)
                    ->save($object, $options);
    }

    public function getCollection($db, $collection)
    {

        return $this->getMongo()->selectDB($db)->selectCollection($collection);
    }

    public function getDb($db)
    {
        return $this->getMongo()->selectDB($db);
    }

    public function checkIndexes($db, $collection, $indexes)
    {
        $log = self::log();
        $log->addDebug("Checking indexes for `{$collection}`");

        $collectionObject = $this->getMongo()->selectDB($db)->selectCollection($collection);
        $dbIndexes = $collectionObject->getIndexInfo();
        $needToCreate = [];

        foreach ($indexes as $key => $index) {
            $log->addDebug('Checking index ' . json_encode($index));
            $needToCreate[$key] = true;
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
    
    public function setPrimary($db, $collection, $id)
    {
        // TODO: Implement setPrimary() method.
    }
}