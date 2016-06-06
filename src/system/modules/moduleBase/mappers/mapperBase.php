<?php

namespace mpcmf\modules\moduleBase\mappers;

use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\exceptions\modelException;
use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\modules\moduleBase\models\modelCursor;
use mpcmf\system\helper\io\codes;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\system\helper\module\modulePartsHelper;
use mpcmf\system\pattern\singletonInterface;
use mpcmf\system\storage\exception\storageException;
use mpcmf\system\storage\mongoCrud;

/**
 * Model map abstraction class
 *
 * @package mpcmf\modules\moduleBase\mappers\mapperBase
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
abstract class mapperBase
    implements singletonInterface
{
    use modulePartsHelper;
    use mongoCrud {
        create as protected _create;
        save as protected _save;
        getAllBy as protected _getAllBy;
        getBy as protected _getBy;
        updateAllBy as protected _updateAllBy;
        updateBy as protected _updateBy;
        remove as protected _remove;
        findAndModify as protected _findAndModify;
    }

    const RELATION__ONE_TO_ONE = 'one-to-one';
    const RELATION__ONE_TO_MANY = 'one-to-many';
    const RELATION__MANY_TO_MANY = 'many-to-many';

    const ROLE__MONGO_ID = 'mongo-id';
    const ROLE__PRIMARY_KEY = 'key';
    const ROLE__GENERATE_KEY = 'generate-key';
    const ROLE__TITLE = 'title';
    const ROLE__SEARCHABLE = 'searchable';
    const ROLE__FULLTEXT_SEARCH = 'fulltext-search';
    const ROLE__SORTABLE = 'sortable';
    const ROLE__QUERY_FIELD = 'query-field';
    const ROLE__GEO_AREA = 'geo-area';
    const ROLE__GEO_POINT = 'geo-point';

    const SAVE__MODE_DEFAULT = false;
    const SAVE__MODE_INSERT_ONLY = true;
    const SAVE__MODE_CHANGES_ONLY = 2;

    //ROLES
    private $key;
    private $keyGenerate;
    private $keyType;
    private $titleField;
    private $searchFields;
    private $fulltextSearchFields;
    private $sortFields;
    private $isSearchable;
    private $isSortable;
    private $geoAreaField;
    private $geoPointField;

    private $normalizedMap;
    private $compiledMap;
    private $modelClassName;

    private $fullMap;
    private $mapByTag = [];

    protected function initialize()
    {

    }

    public function __construct()
    {
        $this->initialize();
    }

    /**
     * Entity map
     *
     * @return array[]
     */
    abstract public function getMap();

    /**
     * @param string $tag
     */
    public function getMapByTag($tag)
    {
        if (isset($this->mapByTag[$tag])) {
            return $this->mapByTag[$tag];
        }

        if ($this->fullMap === null) {
            $this->fullMap = $this->getMap();
        }

        $this->mapByTag[$tag] = [];

        foreach ($this->fullMap as $fieldName => $fieldData) {
            if (isset($fieldData['tags']) && in_array($tag, $fieldData['tags'], true)) {
                $this->mapByTag[$tag][$fieldName] = $fieldData;
            }
        }

        return $this->mapByTag[$tag];
    }
    /**
     * Get public name for current entity
     *
     * @return string
     */
    public function getPublicName()
    {
        if(!isset($this->entityPublicName)) {
            $this->entityPublicName = $this->getEntityName();
        }

        return $this->entityPublicName;
    }

    /**
     * Get normalized map
     *
     * @return mixed
     * @throws mapperException
     */
    public function getNormalizedMap()
    {
        if(!isset($this->normalizedMap)) {
            $map = $this->getMap();
            foreach ($map as $field => $mapData) {
                if (isset($mapData['relations'])) {
                    foreach($mapData['relations'] as $relationKey => $relation) {
                        if(!isset($relation['field'])) {
                            $relationClass = $mapData['relations'][$relationKey]['mapper'];
                            if (!is_a($relationClass, __CLASS__, true)) {
                                throw new mapperException('Mapper data error: Invalid mapper for ' . json_encode($mapData['relations']));
                            }
                            /** @var mapperBase $relationMapper */
                            /** @var string|mapperBase $relationClass */
                            $relationMapper = $relationClass::getInstance();
                            $mapData['relations'][$relationKey]['field'] = $relationMapper->getKey();
                        }
                    }
                }
                $this->normalizedMap[$field] = $mapData;
            }
        }

        return $this->normalizedMap;
    }

    /**
     * @param $field
     *
     * @return static
     * @throws mapperException
     */
    public function getRelationMapper($field)
    {
        $relationData = $this->getRelationData($field);
        $relationClass = $relationData['mapper'];
        if(!is_a($relationClass, 'mpcmf\\modules\\moduleBase\\mappers\\mapperBase', true)) {

            throw new mapperException("Mapper data error: Invalid mapper for field `{$field}`: " . json_encode($relationData));
        }
        /** @var mapperBase $relationMapper */
        /** @var string|mapperBase $relationClass */

        return $relationClass::getInstance();
    }

    /**
     * @param $field
     *
     * @return mixed
     * @throws mapperException
     */
    public function getRelationData($field)
    {
        $map = $this->getNormalizedMap();
        if(!isset($map[$field])) {

            throw new mapperException("Unknown field {$field}");
        }

        return $map[$field]['relations'][$this->getDefaultRelation($field)];
    }

    /**
     * Select and return default relation for field
     *
     * @param $field
     *
     * @return mixed
     * @throws mapperException
     */
    public function getDefaultRelation($field)
    {
        $map = $this->getNormalizedMap();
        if(!isset($map[$field])) {

            throw new mapperException("Unknown field {$field}");
        }
        if(!isset($map[$field]['relations'])) {

            throw new mapperException("Relations not found for field {$field}");
        }

        return array_keys($map[$field]['relations'])[0];
    }

    /**
     * @param $field
     *
     * @return mixed
     * @throws mapperException
     */
    public function getRelationField($field)
    {
        $relationData = $this->getRelationData($field);

        return $relationData['field'];
    }

    /**
     * Save new item
     *
     * @param modelBase|array $itemData
     * @param bool            $saveMode
     *
     * @return mixed
     * @throws \Exception
     * @throws \MongoException
     * @throws \MongoCursorTimeoutException
     * @throws \mpcmf\system\configuration\exception\configurationException
     * @throws \MongoCursorException
     * @throws \MongoConnectionException
     * @throws \InvalidArgumentException
     * @throws \mpcmf\modules\moduleBase\exceptions\modelException
     * @throws mapperException
     */
    public function save(&$itemData, $saveMode = self::SAVE__MODE_DEFAULT)
    {
        if (!isset($this->key)) {
            $this->initializeRoleFields();
        }

//        $changedFields = [];
        if($itemData instanceof modelBase) {
            MPCMF_DEBUG && self::log()->addDebug('Input data is instance of modelBase', [__METHOD__]);
            $data = $itemData->export($saveMode === self::SAVE__MODE_CHANGES_ONLY);
            MPCMF_DEBUG && self::log()->addDebug('Data exported', [__METHOD__]);
        } else {
            MPCMF_DEBUG && self::log()->addDebug('Input data is an array', [__METHOD__]);
            $data =& $itemData;
            MPCMF_DEBUG && self::log()->addDebug('Variable linked', [__METHOD__]);
        }
        MPCMF_DEBUG && self::log()->addDebug('Input data prepared', [__METHOD__]);

        $primaryKey = $this->getKey();

        if(isset($data['_id'])) {
            MPCMF_DEBUG && self::log()->addDebug('Input data has _id field', [__METHOD__]);
            if(!($data['_id'] instanceof \MongoId)) {
                if (is_string($data['_id'])) {
                    $data['_id'] = new \MongoId($data['_id']);
                } elseif (isset($data['_id']['$id'])) {
                    $data['_id'] = new \MongoId($data['_id']['$id']);
                }
            }

            MPCMF_DEBUG && self::log()->addDebug('Input data has _id field, saving...', [__METHOD__]);

            try {
                if($saveMode === self::SAVE__MODE_INSERT_ONLY) {
                    return $this->_create($data);
                } elseif($saveMode === self::SAVE__MODE_DEFAULT) {
                    return $this->_save($data);
                } elseif($saveMode === self::SAVE__MODE_CHANGES_ONLY) {
                    return $this->updateBy([
                        '_id' => $data['_id']
                    ], $data);
                }

                throw new mapperException('Unexpected save mode: ' . json_encode($saveMode));
            } catch(storageException $storageException) {
                throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
            }
        } elseif($this->keyGenerate) {
            if (!empty($data[$primaryKey])) {
                MPCMF_DEBUG && self::log()->addDebug('Input data has generated key field, updating...', [__METHOD__]);

                return $this->updateById($data[$primaryKey], $data);
            }

            $isMongoId = $primaryKey === '_id';
            $needUpdateMongoId = !$isMongoId && !isset($data['_id']);
            $data[$primaryKey] = $isMongoId ? new \MongoId() : $this->generateId();
            if($needUpdateMongoId) {
                $data['_id'] = new \MongoId();
            }

            try {
                if ($itemData instanceof modelBase) {
                    $itemData->updateFields($data);
                }
                return $this->_create($data);
            } catch (\Exception $e) {
                throw new mapperException("Storage exception: {$e->getMessage()}", $e->getCode(), $e);
            }
        } elseif(isset($data[$primaryKey]) && !empty($data[$primaryKey])) {
            MPCMF_DEBUG && self::log()->addDebug('Input data has manually typed key field, updating...', [__METHOD__]);

            return $this->updateById($data[$primaryKey], $data, true);
        }

        throw new mapperException("Unable to save item without key field: {$primaryKey} (generate:off)");
    }

    /**
     * @param modelBase $itemData
     *
     * @return string
     */
    public function generateId($itemData = null)
    {
        $id = microtime(true).json_encode($_SERVER);
        if($itemData !== null) {
            $id .= json_encode($itemData);
        }

        return md5($id);
    }

    /**
     * Loading item from storage by mongo-like criteria
     *
     * @param array $criteria
     * @param array $fields
     * @param array $sort
     *
     * @return modelCursor
     * @throws mapperException
     */
    public function getAllBy($criteria = [], array $fields = [], array $sort = null)
    {
        MPCMF_DEBUG && self::log()->addDebug('criteria:' . json_encode($criteria), [__METHOD__]);
        try {
            $class = $this->getModelClass();
        } catch(modelException $modelException) {
            throw new mapperException('Model error, unable to get model class', $modelException->getCode(), $modelException);
        }

        try {
            $cursor = $this->_getAllBy($criteria, $fields);
            if(!empty($sort)) {
                if(!$this->getIsSortable()) {
                    throw new mapperException('That entity mapper has not any sortable fields!');
                }
                array_walk($sort, function(&$value) {
                    $value = (int)$value;
                });
                $notSortableFields = array_diff(array_keys($sort), $this->sortFields);
                if($notSortableFields) {
                    throw new mapperException('These fields are not sortable: ' . implode(', ', $notSortableFields));
                }
                $cursor->sort($sort);
            }
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        } catch(\MongoCursorException $mongoCursorException) {
            throw new mapperException('Some error in storage, request failed', $mongoCursorException->getCode(), $mongoCursorException);
        }

        return $class::createAll($cursor);
    }

    public function getSearchCriteria($query)
    {
        if(!$this->getIsSearchable()) {
            throw new mapperException('That entity mapper has not any searchable fields!');
        }

        $criteria = [
            '$or' => []
        ];

        if(count($this->searchFields)) {
            foreach ($this->searchFields as $field) {
                $criteria['$or'][] = [
                    $field => $this->convert($field, $query)
                ];
            }
        }
        if(count($this->fulltextSearchFields)) {
            foreach ($this->fulltextSearchFields as $field) {
                $criteria['$or'][] = [
                    $field => new \MongoRegex('/' . preg_quote($query) . '/ui')
                ];
            }
        }

        MPCMF_DEBUG && self::log()->addDebug('criteria:' . json_encode($criteria), [__METHOD__]);

        return $criteria;
    }

    /**
     * Search items from storage by mongo-like criteria
     *
     * @param mixed $query
     * @param array $fields
     * @param array $sort
     *
     * @return modelCursor
     * @throws mapperException
     */
    public function searchAllBy($query, array $fields = [], array $sort = null)
    {
        $criteria = $this->getSearchCriteria($query);

        try {
            $class = $this->getModelClass();
        } catch(modelException $modelException) {
            throw new mapperException('Model error, unable to get model class', $modelException->getCode(), $modelException);
        }

        try {
            $cursor = $this->_getAllBy($criteria, $fields);

            if(!empty($sort)) {
                if(!$this->getIsSortable()) {
                    throw new mapperException('That entity mapper has not any sortable fields!');
                }
                array_walk($sort, function(&$value) {
                    $value = (int)$value;
                });
                $notSortableFields = array_diff(array_keys($sort), $this->sortFields);
                if($notSortableFields) {
                    throw new mapperException('These fields are not sortable: ' . implode(', ', $notSortableFields));
                }
                $cursor->sort($sort);
            }
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        } catch(\MongoCursorException $mongoCursorException) {
            throw new mapperException('Some error in storage, request failed', $mongoCursorException->getCode(), $mongoCursorException);
        }

        return $class::createAll($cursor);
    }

    /**
     * Search items from storage by mongo-like criteria
     *
     * @param       $criteria
     * @param array $fields
     * @param array $sort
     *
     * @return modelCursor
     * @throws mapperException
     */
    public function searchAllByCriteria($criteria, array $fields = [], array $sort = null)
    {
        $criteria = $this->convertDataFromForm($criteria);

        if(!$this->getIsSearchable()) {
            throw new mapperException('That entity mapper has not any searchable fields!');
        }

        $notSearchableFields = array_diff(array_keys($criteria), array_merge($this->searchFields, $this->fulltextSearchFields));
        if($notSearchableFields) {
            throw new mapperException('These fields are not searchable: ' . implode(', ', $notSearchableFields));
        }
        MPCMF_DEBUG && self::log()->addDebug('criteria:' . json_encode($criteria), [__METHOD__]);

        try {
            $class = $this->getModelClass();
        } catch(modelException $modelException) {
            throw new mapperException('Model error, unable to get model class', $modelException->getCode(), $modelException);
        }

        try {
            $cursor = $this->_getAllBy($criteria, $fields);

            if(!empty($sort)) {
                if(!$this->getIsSortable()) {
                    throw new mapperException('That entity mapper has not any sortable fields!');
                }
                array_walk($sort, function(&$value) {
                    $value = (int)$value;
                });
                $notSortableFields = array_diff(array_keys($sort), $this->sortFields);
                if($notSortableFields) {
                    throw new mapperException('These fields are not sortable: ' . implode(', ', $notSortableFields));
                }
                $cursor->sort($sort);
            }
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        } catch(\MongoCursorException $mongoCursorException) {
            throw new mapperException('Some error in storage, request failed', $mongoCursorException->getCode(), $mongoCursorException);
        }

        return $class::createAll($cursor);
    }

    /**
     * Loading item from storage by mongo-like criteria
     *
     * @param       $criteria
     * @param array $fields
     *
     * @return modelBase
     * @throws mapperException
     */
    public function getBy($criteria, $fields = [])
    {
        MPCMF_DEBUG && self::log()->addDebug('criteria:' . json_encode($criteria), [__METHOD__]);
        try {
            $class = $this->getModelClass();
        } catch(modelException $modelException) {
            throw new mapperException('Model error, unable to get model class', $modelException->getCode(), $modelException);
        }

        try {
        $found = $this->storage()
             ->selectOne($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, $fields);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }

        if($found === null) {
            MPCMF_DEBUG && self::log()->addInfo("Item not found in storage: {$this->getEntityName()}", [__METHOD__]);
            throw new mapperException("Item not found in storage: {$this->getEntityName()}");
        }

        return $class::fromArray($found);
    }

    /**
     * Update by id
     *
     * @param string|int      $id
     * @param array|modelBase $newData
     * @param bool            $createIfNotExists
     *
     * @return mixed
     * @throws mapperException
     */
    public function updateById($id, $newData, $createIfNotExists = false)
    {
        MPCMF_DEBUG && self::log()->addDebug("id:{$id}", [__METHOD__]);
        MPCMF_LL_DEBUG && self::log()->addDebug("id:{$id} data:" . json_encode($newData), [__METHOD__]);
        $criteria = [
            $this->getKey() => $id
        ];
        $criteria = $this->convertDataFromForm($criteria);
        if($newData instanceof modelBase) {
            $updateData = $newData->export();
        } else {
            $updateData = $newData;
        }

        $options = [];
        if($createIfNotExists) {
            $options['upsert'] = true;
        }

        try {
            return $this->_updateBy($criteria, $updateData, $options);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }
    }

    /**
     * Update all by ids
     *
     * @param string[]|int[]      $ids
     * @param array|modelBase $newData
     *
     * @return mixed
     * @throws mapperException
     */
    public function updateAllByIds($ids, $newData)
    {
        MPCMF_DEBUG && self::log()->addDebug('ids:' . json_encode($ids) . ' data:' . json_encode($newData), [__METHOD__]);



        $key = $this->getKey();
        foreach($ids as $k => $id) {
            $converted = $this->convertDataFromForm([
                $key => $id
            ]);
            $ids[$k] = $converted[$key];
        }
        $criteria = [
            $this->getKey() => [
                '$in' => array_values($ids)
            ]
        ];

        if($newData instanceof modelBase) {
            $updateData = $newData->export();
        } else {
            $updateData = $newData;
        }

        try {
            return $this->_updateAllBy($criteria, $updateData);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }
    }

    /**
     * Get item's data by item ID
     *
     * @param $id
     *
     * @return array|mixed|modelBase|null
     * @throws mapperException
     */
    public function getById($id)
    {
        MPCMF_DEBUG && self::log()->addDebug("id:{$id}", [__METHOD__]);
        $criteria = [
            $this->getKey() => $id
        ];
        $criteria = $this->convertDataFromForm($criteria);

        try {
            $class = $this->getModelClass();
        } catch(modelException $modelException) {
            throw new mapperException('Model error, unable to get model class', $modelException->getCode(), $modelException);
        }

        try {
            $filter = $this->_getBy($criteria);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }
        if($filter === null) {
            throw new mapperException("Item not found (`{$this->getEntityName()}` entity)", codes::RESPONSE_CODE_NOT_FOUND);
        }

        return $class::fromArray($filter);
    }

    /**
     * Remove item by item ID
     *
     * @param $id
     *
     * @return mixed
     * @throws mapperException
     */
    public function removeById($id)
    {
        MPCMF_DEBUG && self::log()->addDebug("id:{$id}", [__METHOD__]);
        $criteria = [
            $this->getKey() => $id
        ];
        $criteria = $this->convertDataFromForm($criteria);

        try {
            return $this->_remove($criteria);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }
    }

    /**
     * Remove items by item IDs
     *
     * @param array $ids
     *
     * @return mixed
     * @throws mapperException
     */
    public function removeAllByIds($ids)
    {
        MPCMF_DEBUG && self::log()->addDebug('ids:' . json_encode($ids), [__METHOD__]);
        $key = $this->getKey();
        foreach($ids as $k => $id) {
            $converted = $this->convertDataFromForm([
                $key => $id
            ]);
            $ids[$k] = $converted[$key];
        }
        $criteria = [
            $this->getKey() => [
                '$in' => array_values($ids)
            ]
        ];

        try {
            return $this->_remove($criteria);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }
    }

    /**
     * Remove item by item ID
     *
     * @param $item
     *
     * @return mixed
     * @throws mapperException
     * @throws modelException
     */
    public function remove($item)
    {
        MPCMF_DEBUG && self::log()->addDebug('item:' . json_encode($item), [__METHOD__]);
        if($item instanceof modelBase) {

            return $this->removeById($item->getIdValue());
        } elseif(isset($item[$this->getKey()])) {

            return $this->removeById($item[$this->getKey()]);
        }
        throw new mapperException('Expected key, but no key given: ' . json_encode($item));
    }

    /**
     * Count items by criteria
     *
     * @param $criteria
     *
     * @return array|mixed|null
     * @throws mapperException
     */
    public function getCountBy($criteria)
    {
        MPCMF_DEBUG && self::log()->addDebug('criteria: ' . json_encode($criteria), [__METHOD__]);

        try {
            return $this->_getBy($criteria);
        } catch(storageException $storageException) {
            throw new mapperException('Some error in storage, request failed', $storageException->getCode(), $storageException);
        }
    }

    /**
     * Find data and modify by single query
     *
     * @param       $criteria
     * @param       $updateData
     * @param array $fields
     * @param array $options
     *
     * @return static
     * @throws mapperException
     */
    public function findAndModify($criteria, $updateData, $fields = [], $options = [])
    {
        MPCMF_DEBUG && self::log()->addDebug('criteria: ' . json_encode($criteria), [__METHOD__]);
        try {
            $class = $this->getModelClass();
        } catch(modelException $modelException) {
            throw new mapperException('Model error, unable to get model class', $modelException->getCode(), $modelException);
        }

        return $class::fromArray($this->_findAndModify($criteria, $updateData, $fields, $options));
    }

    /**
     * Get compiled map
     *
     * @return array
     * @throws mapperException
     */
    public function getCompiledMap()
    {
        if(!isset($this->compiledMap)) {

            $this->compiledMap = [];
            $class = get_called_class();

            foreach ($this->getNormalizedMap() as $field => $mapData) {
                // base.getter
                if (!isset($mapData['getter']) && !isset($mapData['relations'])) {
                    throw new mapperException("Mapper data error: no `getter` found for {$class}.{$field}");
                }
                if (isset($mapData['getter'])) {
                    $this->compiledMap[$mapData['getter']] = [
                        'method' => 'abstractGetter',
                        'data' => [
                            'field' => $field,
                            'map' => $mapData
                        ]
                    ];
                }

                // base.setter
                if (isset($mapData['setter'])) {
                    $this->compiledMap[$mapData['setter']] = [
                        'method' => 'abstractSetter',
                        'data' => [
                            'field' => $field,
                            'map' => $mapData
                        ]
                    ];
                }

                // relations
                if (isset($mapData['relations'])) {
                    foreach ($mapData['relations'] as $relationName => $relation) {
                        if (!isset($relation['getter'])) {
                            throw new mapperException("Mapper relation error: no `getter` found for {$relationName}: {$relation['mapper']}.{$relation['field']}");
                        }

                        $this->compiledMap[$relation['getter']] = [
                            'method' => 'anotherMapperGetter',
                            'data' => [
                                'field' => $field,
                                'foreign_field' => $relation['field'],
                                'relation' => $relation,
                                'map' => $mapData
                            ]
                        ];
                        if (isset($relation['setter'])) {
                            $this->compiledMap[$relation['setter']] = [
                                'method' => 'anotherMapperSetter',
                                'data' => [
                                    'field' => $field,
                                    'foreign_field' => $relation['field'],
                                    'relation' => $relation,
                                    'map' => $mapData
                                ]
                            ];
                        }
                    }
                }
            }
        }

        return $this->compiledMap;
    }

    public function getKey()
    {
        if(!isset($this->key)) {
            $this->initializeRoleFields();
        }

        return $this->key;
    }

    public function getKeyType()
    {
        if(!isset($this->keyType)) {
            $this->keyType = $this->getNormalizedMap()[$this->getKey()]['type'];
        }

        return $this->keyType;
    }

    public function getTitleField()
    {
        if(!isset($this->titleField)) {
            $this->initializeRoleFields();
        }

        return $this->titleField;
    }

    public function getFieldName($field)
    {
        return $this->getNormalizedMap()[$field]['name'];
    }

    /**
     * @throws mapperException
     */
    protected function initializeRoleFields()
    {
        if(!isset($this->key, $this->titleField, $this->searchFields, $this->fulltextSearchFields, $this->sortFields)) {
            MPCMF_DEBUG && self::log()->addDebug('Initializing role keys...', [__METHOD__]);
            $this->searchFields = [];
            $this->fulltextSearchFields = [];
            $this->sortFields = [];
            foreach ($this->getNormalizedMap() as $field => $mapData) {
                if (isset($mapData['role'][self::ROLE__PRIMARY_KEY]) && $mapData['role'][self::ROLE__PRIMARY_KEY]) {
                    $this->key = $field;
                    $this->keyType = $mapData['type'];
                    $this->keyGenerate = isset($mapData['role'][self::ROLE__GENERATE_KEY]) && $mapData['role'][self::ROLE__GENERATE_KEY];
                }
                if (isset($mapData['role'][self::ROLE__TITLE]) && $mapData['role'][self::ROLE__TITLE]) {
                    $this->titleField = $field;
                }
                if (isset($mapData['role'][self::ROLE__SEARCHABLE]) && $mapData['role'][self::ROLE__SEARCHABLE]) {
                    $this->searchFields[] = $field;
                }
                if (isset($mapData['role'][self::ROLE__FULLTEXT_SEARCH]) && $mapData['role'][self::ROLE__FULLTEXT_SEARCH]) {
                    $this->fulltextSearchFields[] = $field;
                }
                if (isset($mapData['role'][self::ROLE__SORTABLE]) && $mapData['role'][self::ROLE__SORTABLE]) {
                    $this->sortFields[] = $field;
                }
                if (isset($mapData['role'][self::ROLE__GEO_AREA]) && $mapData['role'][self::ROLE__GEO_AREA]) {
                    $this->geoAreaField = $field;
                }
                if (isset($mapData['role'][self::ROLE__GEO_POINT]) && $mapData['role'][self::ROLE__GEO_POINT]) {
                    $this->geoPointField = $field;
                }
            }
            if(!isset($this->key)) {
                throw new mapperException('Key field doesn\'t exists!');
            }
            if(!isset($this->titleField)) {
                $this->titleField =& $this->key;
            }
            $this->isSearchable = count($this->searchFields) > 0 || count($this->fulltextSearchFields) > 0;
            $this->isSortable = count($this->sortFields) > 0;

            MPCMF_DEBUG && self::log()->addDebug("Initialized base key: {$this->key}", [__METHOD__]);
        }
    }

    public function convertDataFromForm($input, modelBase $model = null)
    {
        $result = [];
        foreach($input as $field => $value) {
            $converted = $this->convert($field, $value);
            if($model !== null && $model->getFieldValue($field) === $value) {
                $result[$field] = $value;
                continue;
            }
            $result[$field] = $converted;
        }

        return $result;
    }

    public function convert($field, $value)
    {
        $map = $this->getMap();

        if($field === '_id') {
            MPCMF_DEBUG && self::log()->addDebug('Input data has _id field', [__METHOD__]);
            $result = $value;
            if(!($result instanceof \MongoId)) {
                if (is_string($value)) {
                    if(strlen($value) != 24) {
                        throw new mapperException("Incorrect MongoID field value: `{$field}` = '{$value}'", codes::RESPONSE_CODE_UNKNOWN_FIELD);
                    }
                    $result = new \MongoId($value);
                } elseif (isset($value['$id'])) {
                    $result = new \MongoId($value['$id']);
                } else {
                    $result = $value;
                }
            }

            return $result;
        }
        if(!isset($map[$field])) {
            throw new mapperException("Unknown field: `{$field}`", codes::RESPONSE_CODE_UNKNOWN_FIELD);
        }

        $result = null;

        $formType = explode('.', $map[$field]['formType'])[0];
        switch($formType) {
            case 'checkbox':
                static $isTrue = [
                    'on' => true,
                    'true' => true,
                    'yes' => true,
                ];
                $v = trim(strtolower($value));
                if($v == '1' || isset($isTrue[$v])) {
                    $result = true;
                } else {
                    $result = false;
                }
                break;
            case 'datetimepicker':
                $result = (int)trim($value);
                break;
            case 'geojson':
                $result = json_decode($value, true);
                break;
            case 'json':
                $result = json_decode($value, true);
                break;
            case 'statsSelect':
            case 'multitext':
            case 'searcheblemultiselect':
            case 'multiselect':
                if(!is_array($value)) {
                    throw new mapperException("Unable to convert value of field `{$field}`");
                }
                $result = [];
                if(substr($map[$field]['type'], -2) !== '[]') {
                    throw new mapperException("Invalid field type {$map[$field]['type']} for multiform");
                }
                foreach($value as $valueKey => $valueLine) {
                    switch($map[$field]['type']) {
                        case 'int[]':
                        case 'integer[]':
                            $result[$valueKey] = (int)$valueLine;
                            break;
                        case 'float[]':
                        case 'double[]':
                            $result[$valueKey] = (double)$valueLine;
                            break;
                        case 'string[]':
                            if ($valueLine !== '') {
                                $result[$valueKey] = (string)$valueLine;
                            }
                            break;
                        case 'boolean[]':
                            $result[$valueKey] = (bool)$valueLine;
                            break;
                        default:
                            throw new mapperException("Unknown field type: {$map[$field]['type']} for entity {$this->getEntityName()}");
                            break;
                    }
                }
                break;
            case 'radio':
                $v = trim(strtolower($value));
                if($v === 'on' || $v == '1') {
                    $result = true;
                } else {
                    $result = false;
                }
                break;
            case 'operationValueSelect':
            case 'searchebleselect':
            case 'select':
            case 'text':
                switch($map[$field]['type']) {
                    case 'int':
                    case 'integer':
                        $result = (int)$value;
                        break;
                    case 'float':
                    case 'double':
                        $result = (double)$value;
                        break;
                    case 'string':
                        $result = (string)$value;
                        break;
                    case 'boolean':
                        $result = (bool)$value;
                        break;
                    default:
                        throw new mapperException("Unknown field type: {$map[$field]['type']} for entity {$this->getEntityName()}");
                        break;
                }
                break;
            case 'password':
                $result = md5('mpcmf:' . json_encode((string)$value));
                break;
            case 'textarea':
                $result = (string)$value;
                break;
            case 'timepicker':
                $result = (int)$value;
                break;
            default:
                throw new mapperException("Unknown `{$field}` field formType `{$map[$field]['formType']}` as `{$formType}`");
                break;
        }

        return $result;
    }

    public function defaultValue($field)
    {
        $map = $this->getMap();
        $result = null;

        if($field === '_id') {
            $result = new \MongoId();

            return $result;
        }
        if(!isset($map[$field])) {
            throw new mapperException("Unknown field: `{$field}`", codes::RESPONSE_CODE_UNKNOWN_FIELD);
        }

        $formType = explode('.', $map[$field]['formType'])[0];
        switch($formType) {
            case 'geojson':
            case 'json':
            case 'statsSelect':
            case 'multitext':
            case 'searcheblemultiselect':
            case 'multiselect':
                $result = [];
                break;
            case 'radio':
            case 'checkbox':
                $result = false;
                break;
            case 'datetimepicker':
            case 'operationValueSelect':
            case 'searchebleselect':
            case 'select':
            case 'text':
            case 'password':
            case 'textarea':
            case 'timepicker':
                $result = null;
                break;
            default:
                throw new mapperException("Unknown `{$field}` field formType `{$map[$field]['formType']}` as `{$formType}`");
                break;
        }

        return $result;
    }

    /**
     * @return mixed
     * @throws mapperException
     */
    public function getIsSearchable()
    {
        if(!isset($this->isSearchable)) {
            $this->initializeRoleFields();
        }

        return $this->isSearchable;
    }

    /**
     * @return mixed
     * @throws mapperException
     */
    public function getIsSortable()
    {
        if(!isset($this->isSortable)) {
            $this->initializeRoleFields();
        }

        return $this->isSortable;
    }

    /**
     * @return mixed
     * @throws mapperException
     */
    public function getGeoAreaField()
    {
        if(!isset($this->geoAreaField)) {
            $this->initializeRoleFields();
        }

        return $this->geoAreaField;
    }

    /**
     * @return mixed
     * @throws mapperException
     */
    public function getGeoPointField()
    {
        if(!isset($this->geoPointField)) {
            $this->initializeRoleFields();
        }

        return $this->geoPointField;
    }

    /**
     * @return string|modelBase
     * @throws mapperException
     * @throws modelException
     */
    private function getModelClass()
    {
        if(!isset($this->modelClassName)) {
            try {
                $model = $this->getModel();
            } catch(modulePartsHelperException $modulePartsHelperException) {
                throw new mapperException('Unable to get model instance', $modulePartsHelperException->getCode(), $modulePartsHelperException);
            }
            $this->modelClassName = $model->getCurrentClassName();
        }

        return $this->modelClassName;
    }

    /**
     * @param                $fieldName
     * @param modelBase|null $model
     *
     * @return modelCursor
     * @throws mapperException
     */
    public function getAllRelatedModels($fieldName, modelBase $model = null)
    {
        $criteria = $this->relatedMapperCriteria($fieldName, $model);

        return $this->getRelationMapper($fieldName)->getAllBy($criteria);
    }

    /**
     * @param                $fieldName
     * @param modelBase|null $model
     *
     * @return array
     */
    protected function relatedMapperCriteria($fieldName, modelBase $model = null)
    {
        return [];
    }
}
