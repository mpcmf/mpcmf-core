<?php

namespace mpcmf\modules\moduleBase\controllers;

use mpcmf\cache;
use mpcmf\modules\moduleBase\exceptions\controllerException;
use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\exceptions\modelException;
use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\system\application\exception\webApplicationException;
use mpcmf\system\helper\io\codes;
use mpcmf\system\helper\io\response;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\system\helper\module\modulePartsHelper;
use mpcmf\system\helper\system\systemGetters;
use mpcmf\system\pattern\singletonInterface;

/**
 * Base controller class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */

abstract class controllerBase
    implements singletonInterface, controllerInterface
{
    use modulePartsHelper, systemGetters, response;

    protected function getRouteNameForAction($actionName)
    {
        return "{$this->getEntityUniqueName()}/{$actionName}";
    }

    public function __nothing()
    {
        return self::nothing([

        ]);
    }

    /**
     *
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     * @throws \RuntimeException
     */
    public function __crudCreate()
    {
        if($this->getSlim()->request()->isPost()) {
            $item = $this->getSlim()->request()->post('item');

            $input = $this->getMapper()->convertDataFromForm($item);

            if (!$this->checkInputByValidator($input, $errors)) {
                return self::error([
                    'item' => $item,
                    'errors' => $errors
                ]);
            }
            try {
                $model = $this->createModelByInput($input);
            } catch(modelException $modelException) {

                return self::error([
                    'errors' => [$modelException->getMessage()],
                    'item' => $item
                ], codes::RESPONSE_CODE_FAIL);
            }

            try {
                $result = $model->getMapper()->save($model);
            } catch (mapperException $mapperException) {

                return self::error([
                    'errors' => [$mapperException->getMessage()],
                    'item' => $model
                ], codes::RESPONSE_CODE_FAIL);
            }

            return self::success([
                'result' => $result,
                'item' => $model
            ], codes::RESPONSE_CODE_CREATED);
        } else {
            return self::nothing([

            ]);
        }
    }

    /**
     * @param $input
     *
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     */
    public function __crudGet($input = null)
    {
        if($input === null) {
            return self::error([
                'errors' => [
                    "Required field `{$this->getMapper()->getKey()}` missed!"
                ]
            ]);
        }

        try {
            $model = $this->getMapper()->getById($input);
        } catch (mapperException $mapperException) {

            return self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL);
        }

        return self::success([
            'result' => $model !== null,
            'item' => $model
        ]);
    }

    /**
     * @param $input
     *
     * @return array|null|void
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     * @throws \RuntimeException
     */
    public function __crudUpdate($input = null)
    {
        try {
            $model = $this->getMapper()->getById($input);
        } catch(mapperException $mapperException) {
            return self::errorByException($mapperException, $mapperException->getCode());
        }

        if($this->getSlim()->request()->isPost()) {
            $inputItem = $this->getSlim()->request()->post('item');

            $item = $this->getMapper()->convertDataFromForm($inputItem, $model);

            if (!$this->checkInputByValidator($item, $errors)) {

                return self::error([
                    'item' => $model,
                    'errors' => $errors
                ]);
            }

            try {
                $model->updateFields($item);
            } catch(modelException $exception) {
                return self::errorByException($exception);
            }
            try {
                $result = $model->getMapper()->updateById($input, $model);
            } catch (mapperException $mapperException) {

                return self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL);
            }

            return self::success([
                'result' => $result,
                'item' => $model,
            ], codes::RESPONSE_CODE_SAVED);
        } elseif($input !== null) {

            return self::nothing([
                'item' => $model
            ]);
        }

        return self::nothing([

        ]);
    }

    /**
     * @return array|null|void
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     * @throws \RuntimeException
     */
    public function __crudMultiUpdate()
    {
        $items = $this->getSlim()->request()->post('items');
        if (empty($items) || !count($items)) {

            return self::error([
                'errors' => [
                    'No items found: ' . json_encode($items)
                ],
                'items' => $items
            ]);
        }

        $confirm = $this->getSlim()->request()->post('confirm');

        if($confirm != 'yes') {
            return self::nothing([
                'items' => $items
            ]);
        } else {
            $inputItem = $this->getSlim()->request()->post('item');
            $updateFields = $this->getSlim()->request()->post('update');
            foreach($inputItem as $iiKey => $iiValue) {
                if(!isset($updateFields[$iiKey])) {
                    unset($inputItem[$iiKey]);
                }
            }
            $item = $this->getMapper()->convertDataFromForm($inputItem);

            try {
                $result = $this->getMapper()->updateAllByIds($items, $item);
            } catch (mapperException $mapperException) {

                return self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL);
            }

            return self::success([
                'result' => $result,
                'items' => $items,
            ], codes::RESPONSE_CODE_OK);
        }
    }

    /**
     * @param $input
     *
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     */
    public function __crudRemove($input = null)
    {
        if($this->getSlim()->request()->isPost()) {
            if ($input === null) {

                return self::error([
                    'errors' => [
                        "Required field `{$this->getMapper()->getKey()}` missed!"
                    ]
                ]);
            }
            try {
                $result = $this->getMapper()->removeById($input);
            } catch (mapperException $mapperException) {

                return self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL);
            }

            return self::success([
                'result' => $result
            ], codes::RESPONSE_CODE_REMOVED);
        }

        return self::nothing([

        ]);
    }

    /**
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     */
    public function __crudMultiRemove()
    {
        $items = $this->getSlim()->request()->post('items');
        if (empty($items) || !count($items)) {

            return self::error([
                'errors' => [
                    'No items found: ' . json_encode($items)
                ],
                'items' => $items
            ]);
        }

        $confirm = $this->getSlim()->request()->post('confirm');
        if($confirm != 'yes') {
            return self::nothing([
                'items' => $items
            ]);
        } else {
            try {
                $result = $this->getMapper()->removeAllByIds($items);
            } catch (mapperException $mapperException) {

                return self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL);
            }

            return self::success([
                'result' => $result,
                'items' => $items,
            ], codes::RESPONSE_CODE_OK);
        }
    }

    /**
     *
     * @param int $limit
     * @param int $offset
     *
     * @return array|null|void
     * @throws mapperException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     */
    public function __crudList($limit = 10, $offset = 0)
    {
        try {
            $sort = $this->getSlim()->request()->get('sort');
            $query = $this->getSlim()->request()->get('q');
            $item = $this->getSlim()->request()->get('item');
            if(!empty($query)) {
                $cursor = $this->getMapper()->searchAllBy($query, [], $sort);
            } elseif(!empty($item)) {
                $item = $this->getMapper()->convertDataFromForm($item);
                $cursor = $this->getMapper()->searchAllByCriteria($item, [], $sort);
            } else {
                $cursor = $this->getMapper()->getAllBy([], [], $sort);
            }
        } catch (mapperException $mapperException) {

            return self::errorByException($mapperException);
        }
        if(isset($offset)) {
            $cursor->skip($offset);
        }
        if(isset($limit)) {
            $cursor->limit($limit);
        }

        return self::success([
            'result' => true,
            'items' => $cursor,
            'query' => $query,
            'sort' => $sort
        ]);
    }

    /**
     *
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws \RuntimeException
     * @throws webApplicationException
     */
    public function api_create()
    {
        if ($this->getSlim()->request()->isPost()) {
            $item = $this->getSlim()->request()->post('item');

            $input = $this->getMapper()->convertDataFromForm($item);

            if (!$this->checkInputByValidator($input, $errors)) {
                return self::error([
                    'errors' => $errors
                ]);
            }

            $model = $this->createModelByInput($input);

            try {
                $result = $model->getMapper()->save($model);
            } catch (mapperException $mapperException) {

                switch((int)$mapperException->getCode()) {
                    case codes::RESPONSE_CODE_DUPLICATE_STORAGE:
                    case codes::RESPONSE_CODE_DUPLICATE:
                        $found = $model->getMapper()->getBy($input);
                        if($found) {
                            return [
                                'response' => self::error([
                                    'item' => $found->export(),
                                ], codes::RESPONSE_CODE_DUPLICATE, $mapperException->getCode())
                            ];
                        } else {
                            return [
                                'response' => self::error('Unknown error: unique item already exists, but not found', codes::RESPONSE_CODE_DUPLICATE, $mapperException->getCode())
                            ];
                        }
                        break;
                    case codes::RESPONSE_CODE_UNKNOWN_FIELD:
                        return [
                            'response' => self::error($mapperException->getMessage(), $mapperException->getCode())
                        ];
                        break;
                    default:
                        return [
                            'response' => self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL)
                        ];
                        break;
                }
            }

            return [
                'response' => self::success([
                    'result' => $result,
                    'item' => $model->export()
                ], codes::RESPONSE_CODE_CREATED)
            ];
        } else {
            return [
                'response' => self::nothing([

                ])
            ];
        }
    }

    /**
     * @param $input
     *
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     */
    public function api_get($input = null)
    {
        if ($input === null) {
            return [
                'response' => self::error([
                    'errors' => [
                        "Required field `{$this->getMapper()->getKey()}` missed!"
                    ]
                ])
            ];
        }

        try {
            $model = $this->getMapper()->getById($input);
        } catch (mapperException $mapperException) {

            if($mapperException->getCode() === codes::RESPONSE_CODE_NOT_FOUND) {
                return [
                    'response' => self::error($mapperException->getMessage(), codes::RESPONSE_CODE_FAIL)
                ];
            } else {
                return [
                    'response' => self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL)
                ];
            }
        }

        return [
            'response' => self::success([
                'result' => $model !== null,
                'item' => $model->export()
            ])
        ];
    }

    /**
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     */
    public function api_getMap()
    {
        try {
            $map = $this->getMapper()->getMap();
        } catch (mapperException $mapperException) {

            return [
                'response' => self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL)
            ];
        }

        $result = [];
        foreach($map as $field => $fieldData) {
            $result[$field] = [
                'fieldName' => $field,
                'type' => $fieldData['type'],
                'role' => isset($fieldData['role']) && count($fieldData['role']) ? $fieldData['role'] : [],
                'name' => $fieldData['name'],
                'description' => $fieldData['description'],
                'options' => $fieldData['options']
            ];
        }

        return [
            'response' => self::success([
                'map' => $result,
            ])
        ];
    }

    /**
     * @param $input
     *
     * @return array|null|void
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     * @throws \RuntimeException
     */
    public function api_update($input = null)
    {
        try {
            $model = $this->getMapper()->getById($input);
        } catch(mapperException $mapperException) {
            return [
                'response' => self::errorByException($mapperException, $mapperException->getCode())
            ];
        }

        if ($this->getSlim()->request()->isPost()) {
            $inputItem = $this->getSlim()->request()->post('item');
            $item = $this->getMapper()->convertDataFromForm($inputItem);

            if (!$this->checkInputByValidator($item, $errors)) {

                return [
                    'response' => self::error([
                        'item' => $model->export(),
                        'errors' => $errors
                    ])
                ];
            }

            try {
                $model->updateFields($item);
            } catch (modelException $exception) {
                return [
                    'response' => self::errorByException($exception)
                ];
            }
            try {
                $result = $model->getMapper()->updateById($input, $model);
            } catch (mapperException $mapperException) {

                return [
                    'response' => self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL)
                ];
            }

            return [
                'response' => self::success([
                    'result' => $result,
                    'item' => $model->export(),
                ], codes::RESPONSE_CODE_SAVED)
            ];
        } elseif ($input !== null) {

            return [
                'response' => self::nothing([
                    'item' => $model->export()
                ])
            ];
        }

        return [
            'response' => self::nothing([

            ])
        ];
    }

    /**
     * @param $input
     *
     * @return array|null|void
     *
     * @throws mapperException
     * @throws modelException
     * @throws controllerException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     */
    public function api_remove($input = null)
    {
        if ($this->getSlim()->request()->isPost()) {
            if ($input === null) {

                return [
                    'response' => self::error([
                        'errors' => [
                            "Required field `{$this->getMapper()->getKey()}` missed!"
                        ]
                    ])
                ];
            }
            try {
                $result = $this->getMapper()->removeById($input);
            } catch (mapperException $mapperException) {

                return [
                    'response' => self::errorByException($mapperException, codes::RESPONSE_CODE_FAIL)
                ];
            }

            return [
                'response' => self::success([
                    'result' => $result
                ], codes::RESPONSE_CODE_REMOVED)
            ];
        }

        return [
            'response' => self::nothing([

            ])
        ];
    }

    /**
     *
     * @param int $limit
     * @param int $offset
     *
     * @return array|null|void
     * @throws mapperException
     * @throws modulePartsHelperException
     * @throws webApplicationException
     * @throws \RuntimeException
     */
    public function api_list($limit = 10, $offset = 0)
    {
        $countCacheKey = $this->getCurrentClassName() . '/count/';
        try {
            $sort = $this->getSlim()->request()->get('sort');
            $query = $this->getSlim()->request()->get('q');
            $item = $this->getSlim()->request()->get('item');
            if(!empty($query)) {
                $cursor = $this->getMapper()->searchAllBy($query);
                $countCacheKey .= 'q/' . md5($query);
            } elseif(!empty($item)) {
                $item = $this->getMapper()->convertDataFromForm($item);
                $cursor = $this->getMapper()->searchAllByCriteria($item);
                $countCacheKey .= 'i/' . md5(json_encode($item));
            } else {
                $cursor = $this->getMapper()->getAllBy();
                $countCacheKey .= md5(date('Y-m-d_H-i'));
            }
        } catch (mapperException $mapperException) {

            return [
                'response' => self::errorByException($mapperException)
            ];
        }
        if (isset($offset)) {
            $offset = (int)$offset;
            if($offset < 0) {
                return [
                    'response' => self::error('offset must be >= 0', codes::RESPONSE_CODE_FAIL, codes::RESPONSE_CODE_FORM_FIELDS_ERROR)
                ];
            }
            $cursor->skip($offset);
        }

        $limit = (int)$limit;
        if($limit < 1 || $limit > 500) {
            return [
                'response' => self::error('limit must be 1..500', codes::RESPONSE_CODE_FAIL, codes::RESPONSE_CODE_FORM_FIELDS_ERROR)
            ];
        }
        $cursor->limit($limit);

        if (isset($sort)) {
            array_walk($sort, function(&$value) {
                $value = (int)$value;
            });
            try {
                $cursor->getCursor()->sort($sort);
            } catch (\MongoCursorException $mongoCursorException) {

                return [
                    'response' => self::errorByException($mongoCursorException)
                ];
            }
        }

        $mongoCursor = $cursor->getCursor();

        if(($count = cache::getCached($countCacheKey)) === false) {
            $count = $mongoCursor->count();
            cache::setCached($countCacheKey, $count, 60);
        }

        return [
            'response' => self::success([
                'items' => iterator_to_array($mongoCursor),
                'info' => [
                    'count' => $count,
                    'params' => [
                        'offset' => $offset,
                        'limit' => $limit,
                        'sort' => $sort,
                        'query' => !empty($query) ? $query : $item,
                    ]
                ],
            ])
        ];
    }

    /**
     *
     * @return array|null|void
     * @throws mapperException
     * @throws modulePartsHelperException
     */
    public function api_getInfo()
    {
        $countCacheKey = $this->getCurrentClassName() . '/count/' . md5(date('Y-m-d_H-i'));

        try {
            $cursor = $this->getMapper()->getAllBy();
        } catch (mapperException $mapperException) {

            return [
                'response' => self::errorByException($mapperException)
            ];
        } catch (modulePartsHelperException $modulePartsHelperException) {

            return [
                'response' => self::errorByException($modulePartsHelperException)
            ];
        }

        $mongoCursor = $cursor->getCursor();

        if(($count = cache::getCached($countCacheKey)) === false) {
            $count = $mongoCursor->count();
            cache::setCached($countCacheKey, $count, 60);
        }

        return [
            'response' => self::success([
                'identity' => [
                    'module' => $this->getModuleName(),
                    'entity' => $this->getEntityName(),
                    'entityUniqueName' => $this->getEntityUniqueName(),
                    'publicName' => $this->getPublicName(),
                ],
                'actions' => array_keys($this->getEntityActions()->getActions()),
                'count' => $count,
            ])
        ];
    }

    /**
     * @param       $input
     *
     * @param array $errors
     * @param bool  $onlyInputFields
     *
     * @return bool
     * @throws modelException
     * @throws modulePartsHelperException
     */
    protected function checkInputByValidator($input, &$errors = [], $onlyInputFields = false)
    {
        $model = $this->getModel();
        $validateResponse = $model::validate($input, $onlyInputFields);
        $errors = $validateResponse['errors'];

        return $validateResponse['status'];
    }

    /**
     * @param $input
     *
     * @return modelBase
     * @throws modelException
     * @throws modulePartsHelperException
     */
    protected function createModelByInput($input)
    {
        $model = $this->getModel();
        return $model::fromArray($input);
    }
}