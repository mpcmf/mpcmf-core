<?php

namespace mpcmf\system\storage;

use mpcmf\system\configuration\exception\configurationException;

class mysqlInstance implements storageInterface
{

    public function select($db, $collection, $criteria = [], $fields = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function selectOne($db, $collection, $criteria = [], $fields = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function selectAndModify($db, $collection, $criteria, $newObject, $selectFields = [], $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function selectAndModifyFields($db, $collection, $criteria, $modifyFields, $selectFields = [], $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function update($db, $collection, $criteria, $newObject, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function updateFields($db, $collection, $criteria, $fields, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function removeOne($db, $collection, $criteria = [], $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function remove($db, $collection, $criteria = [], $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function insert($db, $collection, $object, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function insertBatch($db, $collection, $objects, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function save($db, $collection, $object, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function getCollection($db, $collection)
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function getDb($db)
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function checkIndexes($db, $collection, $indexes)
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function checkIndicesAuto($config)
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }
}