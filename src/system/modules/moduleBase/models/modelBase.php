<?php

namespace mpcmf\modules\moduleBase\models;

use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\exceptions\modelException;
use mpcmf\modules\moduleBase\mappers\mapperBase;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\system\helper\module\modulePartsHelper;
use mpcmf\system\pattern\singletonInterface;
use mpcmf\system\validator\exception\validatorException;
use mpcmf\system\validator\metaValidator;

/**
 * Model abstraction class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
abstract class modelBase
    implements singletonInterface
{
    use modulePartsHelper;

    const UPDATE__MODE_ERROR_ON_MISSED = false;
    const UPDATE__MODE_DEFAULT_ON_MISSED = true;
    const UPDATE__MODE_CHANGES_ONLY = 2;

    private $primaryKey;
    private $titleKey;
    private $geoAreaKey;
    private $geoPointKey;
    private $data = [
    ];
    private $changes = [

    ];

    /**
     * @param $fields
     *
     * @return static
     */
    public static function fromArray($fields)
    {
        return new static($fields);
    }

    /**
     * @param \MongoCursor $cursor
     *
     * @return modelCursor
     */
    public static function createAll($cursor)
    {
        return new modelCursor(get_called_class(), $cursor);
    }

    /**
     * @param      $fields
     *
     * @param bool $onlyExistingFields
     *
     * @return array
     * @throws modelException
     */
    public static function validate($fields, $onlyExistingFields = false)
    {
        static $map;
        if($map === null) {
            $entity = static::getInstance();
            try {
                $map = $entity->getMapper()->getMap();
            } catch(modulePartsHelperException $modulePartsHelperException) {
                throw new modelException(
                    "Unable to get own mapper: {$entity->getEntityName()}",
                    $modulePartsHelperException->getCode(),
                    $modulePartsHelperException
                );
            } catch(mapperException $modulePartsHelperException) {
                throw new modelException(
                    "Unable to map for mapper: {$entity->getEntityName()}",
                    $modulePartsHelperException->getCode(),
                    $modulePartsHelperException
                );
            }
        }
        $errors = [];
        foreach($map as $field => $fieldData) {
            if(isset($fields[$field]) && !empty($fieldData['validator'])) {
                try {
                    $validator = new metaValidator($fieldData['validator']);
                    if(substr($fieldData['type'], -2) === '[]') {
                        if(!is_array($fields[$field])) {
                            /** @noinspection NotOptimalIfConditionsInspection */
                            if(isset($fieldData['options']['required']) && $fieldData['options']['required']) {
                                $errorStr = "Field: `{$field}` Error: Expected array, but given " . gettype($fields[$field]);
                                MPCMF_DEBUG && self::log()->addError($errorStr);
                                $errors[$field][] = $errorStr;
                            }
                        } else {
                            foreach ($fields[$field] as $fieldItem) {
                                $validator->validate($fieldItem);
                            }
                        }
                    } else {
                        $validator->validate($fields[$field]);
                    }
                } catch(validatorException $validatorException) {
                    if(!isset($errors[$field])) {
                        $errors[$field] = [];
                    }
                    MPCMF_DEBUG && self::log()->addError("Field: `{$field}` Error: {$validatorException->getMessage()}");
                    $errors[$field][] = $validatorException->getMessage();
                }
            } elseif(!$onlyExistingFields && !isset($fields[$field]) && isset($fieldData['options']['required']) && $fieldData['options']['required']) {
                if(!isset($errors[$field])) {
                    $errors[$field] = [];
                }
                $errorStr = "Field: `{$field}` Error: Required field missed";
                MPCMF_DEBUG && self::log()->addError($errorStr);
                $errors[$field][] = $errorStr;
            }
        }

        return [
            'status' => count($errors) == 0,
            'errors' => $errors
        ];
    }

    /**
     * @param array $fields
     *
     * @throws mapperException
     * @throws modelException
     */
    public function __construct(array $fields = [])
    {
        if(isset($fields['_id']) && !empty($fields['_id'])) {
            $this->data['_id'] = $fields['_id'];
        }
        $this->updateFields($fields, true);
    }

    /**
     * Magic people voodoo people
     *
     * @param       $method
     * @param array $arguments
     *
     * @return mixed
     * @throws modelException
     */
    public function __call($method, array $arguments = [])
    {
        try {
            $mapper = $this->getMapper();
            $compiledMap = $mapper->getCompiledMap();
        } catch(modulePartsHelperException $modulePartsHelperException) {
            throw new modelException(
                "Unable to get something of {$this->getEntityName()}",
                $modulePartsHelperException->getCode(),
                $modulePartsHelperException
            );
        } catch(mapperException $modulePartsHelperException) {
            throw new modelException(
                "Unable to get own mapper or compiled map: {$this->getEntityName()}",
                $modulePartsHelperException->getCode(),
                $modulePartsHelperException
            );
        }

        if(!isset($compiledMap[$method])) {
            throw new modelException("Unknown method {$method}");
        }

        return $this->{$compiledMap[$method]['method']}($compiledMap[$method]['data'], $arguments);
    }

    /**
     * @param $mapperData
     *
     * @return mixed
     */
    protected function abstractGetter($mapperData)
    {
        return $this->data[$mapperData['field']];
    }

    /**
     * @param       $mapperData
     * @param array $arguments
     *
     * @return $this
     * @throws modelException
     */
    protected function abstractSetter($mapperData, array $arguments = [])
    {
        if(!is_array($arguments) || !count($arguments)) {
            throw new modelException('Expected argument, but nothing given');
        }

        if(!empty($mapperData['map']['validator'])) {
            $validator = new metaValidator($mapperData['map']['validator']);
            if(substr($mapperData['map']['type'], -2) === '[]') {
                if (!is_array($arguments[0]) && $mapperData['map']['options']['required']) {
                    /** @noinspection NotOptimalIfConditionsInspection */
                    throw new modelException('Field error: expected array, but given ' . gettype($arguments[0]));
                } else {
                    foreach ($arguments[0] as $fieldItem) {
                        $validator->validate($fieldItem);
                    }
                }
            } else {
                $validator->validate($arguments[0]);
            }
        }

        $this->data[$mapperData['field']] = $arguments[0];
        $this->changes[$mapperData['field']] = true;

        return $this;
    }

    /**
     * @param $mapperData
     *
     * @return modelBase|modelCursor
     * @throws mapperException
     * @throws modelException
     */
    protected function anotherMapperGetter($mapperData)
    {
        $relationClass = $mapperData['relation']['mapper'];
        if(!is_a($relationClass, 'mpcmf\\modules\\moduleBase\\mappers\\mapperBase', true)) {
            throw new modelException('Mapper data error: Invalid mapper for ' . json_encode($mapperData['relation']));
        }
        /** @var mapperBase $relationMapper */
        /** @var string|mapperBase $relationClass */
        $relationMapper = $relationClass::getInstance();
        switch($mapperData['relation']['type']) {
            case mapperBase::RELATION__ONE_TO_ONE:
                $criteria = [
                    $mapperData['foreign_field'] => $relationMapper->convert($mapperData['foreign_field'], $this->getFieldValue($mapperData['field']))
                ];

                return $relationMapper->getBy($criteria);
            case mapperBase::RELATION__ONE_TO_MANY:
                $criteria = [
                    $mapperData['foreign_field'] => $relationMapper->convert($mapperData['foreign_field'], $this->getFieldValue($mapperData['field']))
                ];

                return $relationMapper->getAllBy($criteria);
            case mapperBase::RELATION__MANY_TO_MANY:
                $inValues = $this->getFieldValue($mapperData['field']);
                foreach($inValues as &$value) {
                    $value = $relationMapper->convert($mapperData['foreign_field'], $value);
                }
                unset($value);

                $criteria = [
                    $mapperData['foreign_field'] => [
                        '$in' => $inValues
                    ]
                ];

                return $relationMapper->getAllBy($criteria);
            default:
                throw new mapperException("Unknown relation type: {$mapperData['relation']['type']}");
        }
    }

    /**
     * @param       $mapperData
     * @param array $arguments
     *
     * @return $this
     * @throws mapperException
     * @throws modelException
     */
    protected function anotherMapperSetter($mapperData, array $arguments = [])
    {
        if(!isset($arguments[0])) {
            throw new modelException('Expected argument, but nothing given');
        }
        if(!($arguments[0] instanceof modelBase)) {
            throw new modelException('Expected model, given ' . gettype($arguments[0]));
        }
        $relationClass = $mapperData['relation']['mapper'];
        if(!($relationClass instanceof mapperBase)) {
            throw new modelException('Mapper data error: Invalid mapper for ' . json_encode($mapperData['relation']));
        }
        /** @var modelBase $foreignModel */
        $foreignModel = $arguments[0];
        try {
            $foreignMapper = $foreignModel->getMapper();
        } catch(modulePartsHelperException $modulePartsHelperException) {
            throw new modelException(
                "Unable to get mapper of the input model: {$foreignModel->getEntityName()}",
                $modulePartsHelperException->getCode(),
                $modulePartsHelperException
            );
        }
        if(is_a($foreignMapper, $mapperData['relation']['mapper'])) {
            throw new modelException(
                "Model error: Wrong input model, expected {$mapperData['relation']['mapper']}, given "
                . $foreignMapper->getCurrentClassName()
            );
        }
        $this->changes[$mapperData['field']] = true;
        switch($mapperData['type']) {
            case mapperBase::RELATION__ONE_TO_ONE:
            case mapperBase::RELATION__ONE_TO_MANY:
                $this->data[$mapperData['field']] = $foreignModel->getFieldValue($mapperData['foreign_field']);
                break;
            case mapperBase::RELATION__MANY_TO_MANY:
                if(!is_array($this->data[$mapperData['field']])) {
                    $this->data[$mapperData['field']] = [];
                }
                $this->data[$mapperData['field']][] = $foreignModel->getFieldValue($mapperData['foreign_field']);
                break;
            default:
                throw new mapperException("Unknown relation type: {$mapperData['type']}");
        }

        return $this;
    }

    /**
     * @param      $fields
     *
     * @param bool $force
     *
     * @throws modelException
     * @throws mapperException
     */
    public function updateFields($fields, $force = self::UPDATE__MODE_ERROR_ON_MISSED)
    {
        $isEmpty = empty($fields);
        try {
            $mapper = $this->getMapper();
        } catch(modulePartsHelperException $modulePartsHelperException) {
            throw new modelException(
                "Unable to get own mapper: {$this->getEntityName()}",
                $modulePartsHelperException->getCode(),
                $modulePartsHelperException
            );
        }
        $this->primaryKey = $mapper->getKey();
        $this->titleKey = $mapper->getTitleField();
        $this->geoAreaKey = $mapper->getGeoAreaField();
        $this->geoPointKey = $mapper->getGeoPointField();
        foreach($mapper->getMap() as $field => $fieldData) {
            if(isset($fields[$field])) {
                if(isset($fieldData['validator'])) {
                    $validator = new metaValidator($fieldData['validator']);
                    if(substr($fieldData['type'], -2) === '[]') {
                        if(!is_array($fields[$field])) {
                            /** @noinspection NotOptimalIfConditionsInspection */
                            if($fieldData['options']['required']) {
                                throw new modelException('Field error: expected array, but given ' . gettype($fields[$field]));
                            }
                            /** @noinspection NotOptimalIfConditionsInspection */
                            elseif($force === self::UPDATE__MODE_DEFAULT_ON_MISSED) {
                                $this->data[$field] = $mapper->defaultValue($field);
                                $this->changes[$field] = true;
                            }
                        } else {
                            foreach ($fields[$field] as $fieldItem) {
                                $validator->validate($fieldItem);
                            }
                        }
                    } else {
                        $validator->validate($fields[$field]);
                    }
                }
                $this->data[$field] = $fields[$field];
                $this->changes[$field] = true;
            } elseif($force === self::UPDATE__MODE_ERROR_ON_MISSED && !$isEmpty && $fieldData['options']['required']) {
                throw new modelException("Required field missed: {$field}");
            } elseif($force === self::UPDATE__MODE_DEFAULT_ON_MISSED) {
                $this->data[$field] = $mapper->defaultValue($field);
                $this->changes[$field] = true;
            }
        }
    }

    /**
     * @return mixed
     * @throws modelException
     */
    public function getIdValue()
    {
        return $this->getFieldValue($this->primaryKey);
    }

    /**
     * @return mixed
     * @throws modelException
     */
    public function getTitleValue()
    {
        return $this->getFieldValue($this->titleKey);
    }

    /**
     * @return mixed
     * @throws modelException
     */
    public function getGeoAreaValue()
    {
        return $this->getFieldValue($this->geoAreaKey);
    }

    /**
     * @return mixed
     * @throws modelException
     */
    public function getGeoPointValue()
    {
        return $this->getFieldValue($this->geoPointKey);
    }

    /**
     * @param $field
     *
     * @return mixed
     * @throws modelException
     */
    public function getFieldValue($field)
    {
        if(!array_key_exists($field, $this->data)) {
            throw new modelException("Unexpected field {$field}, doesn't exists for model: " . $this->getCurrentClassName());
        }

        return $this->data[$field];
    }

    /**
     * @param bool $changesOnly
     *
     * @return array
     * @throws \mpcmf\modules\moduleBase\exceptions\modelException
     */
    public function &export($changesOnly = false)
    {
        if($changesOnly) {
            $result = [];
            foreach($this->getChangedFields() as $field => $value) {
                if(!$value) {
                    continue;
                }
                $result[$field] = $this->data[$field];
            }
            if(!isset($result[$this->primaryKey])) {
                $result[$this->primaryKey] = $this->getIdValue();
            }
        } else {
            $result = $this->data;
        }

        return $result;
    }

    /**
     *
     * @return array
     */
    public function getChangedFields()
    {
        return $this->changes;
    }
}