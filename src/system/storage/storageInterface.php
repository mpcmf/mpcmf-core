<?php

namespace mpcmf\system\storage;

use mpcmf\system\configuration\exception\configurationException;

interface storageInterface 
{
    /**
     * Return \MongoClient instance for current configuration
     *
     * @return \MongoClient
     * @throws configurationException
     * @throws \MongoConnectionException
     */
    public function getMongo();
    
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
    public function select($db, $collection, $criteria = [], $fields = []);

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
    public function selectOne($db, $collection, $criteria = [], $fields = []);

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
    public function selectAndModify($db, $collection, $criteria, $newObject, $selectFields = [], $options = []);

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
    public function selectAndModifyFields($db, $collection, $criteria, $modifyFields, $selectFields = [], $options = []);
    
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
    public function update($db, $collection, $criteria, $newObject, $options = []);

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
    public function updateFields($db, $collection, $criteria, $fields, $options = []);


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
    public function removeOne($db, $collection, $criteria = [], $options = []);

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
    public function remove($db, $collection, $criteria = [], $options = []);

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
    public function insert($db, $collection, $object, $options = []);

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
    public function insertBatch($db, $collection, $objects, $options = []);

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
    public function save($db, $collection, $object, $options = []);

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
    public function getCollection($db, $collection);

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
    public function getDb($db);

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
    public function checkIndexes($db, $collection, $indexes);

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
    public function checkIndicesAuto($config);
}