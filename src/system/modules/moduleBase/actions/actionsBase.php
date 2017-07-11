<?php

namespace mpcmf\modules\moduleBase\actions;

use mpcmf\modules\moduleBase\exceptions\actionException;
use mpcmf\system\acl\aclManager;
use mpcmf\system\cache\cache;
use mpcmf\system\helper\module\modulePartsHelper;
use mpcmf\system\pattern\singletonInterface;

/**
 * Base entity actions accessor class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
abstract class actionsBase
    implements singletonInterface
{
    use modulePartsHelper;

    private $actions = [];

    protected $options = [
        'crud.enabled' => true,
        'api.enabled' => true,
    ];

    public function __construct()
    {
        $keyField = $this->getMapper()->getKey();

        $this->actions = [];

        $this->setOptions();

        if($this->options['crud.enabled'] === true) {
            $this->actions = array_merge($this->actions, [
                'crud.create' => new action([
                    'name' => 'Создать',
                    'method' => '__crudCreate',
                    'http' => [
                        'GET',
                        'POST'
                    ],
                    'required' => [

                    ],
                    'path' => '',
                    'template' => 'crud/create.tpl',
                    'type' => action::TYPE__GLOBAL,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_WRITE,
                    ],
                ], $this),
                'crud.get' => new action([
                    'name' => 'Посмотреть',
                    'method' => '__crudGet',
                    'http' => [
                        'GET',
                    ],
                    'required' => [

                    ],
                    'path' => "(/:{$keyField})",
                    'template' => 'crud/get.tpl',
                    'type' => action::TYPE__FOR_ITEM,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_READ,
                    ],
                ], $this),
                'crud.update' => new action([
                    'name' => 'Редактировать',
                    'method' => '__crudUpdate',
                    'http' => [
                        'GET',
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => "(/:{$keyField})",
                    'template' => 'crud/update.tpl',
                    'type' => action::TYPE__FOR_ITEM,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_WRITE,
                    ],
                ], $this),
                'crud.multiUpdate' => new action([
                    'name' => 'МультиРедактирование',
                    'method' => '__crudMultiUpdate',
                    'http' => [
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => '',
                    'template' => 'crud/multiUpdate.tpl',
                    'type' => action::TYPE__DEFAULT,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_WRITE,
                    ],
                ], $this),
                'crud.remove' => new action([
                    'name' => 'Удалить',
                    'method' => '__crudRemove',
                    'http' => [
                        'GET',
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => "(/:{$keyField})",
                    'template' => 'crud/remove.tpl',
                    'type' => action::TYPE__FOR_ITEM,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_WRITE,
                    ],
                ], $this),
                'crud.multiRemove' => new action([
                    'name' => 'МультиУдаление',
                    'method' => '__crudMultiRemove',
                    'http' => [
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => '',
                    'template' => 'crud/multiRemove.tpl',
                    'type' => action::TYPE__DEFAULT,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_WRITE,
                    ],
                ], $this),
                'crud.list' => new action([
                    'name' => 'Все объекты',
                    'method' => '__crudList',
                    'http' => [
                        'GET',
                    ],
                    'required' => [],
                    'path' => '(/:limit(/:offset))',
                    'template' => 'crud/list.tpl',
                    'type' => action::TYPE__GLOBAL,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_CRUD_FULL,
                        aclManager::ACL__GROUP_CRUD_READ,
                    ],
                ], $this),
            ]);
        }
        if($this->options['api.enabled'] === true) {
            $this->actions = array_merge($this->actions, [
                'api.getMap' => new action([
                    'name' => 'API: карта объекта',
                    'method' => 'api_getMap',
                    'http' => [
                        'GET'
                    ],
                    'required' => [],
                    'path' => '',
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_GLOBAL,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_READ,
                    ],
                ], $this),
                'api.create' => new action([
                    'name' => 'API: создать',
                    'method' => 'api_create',
                    'http' => [
                        'GET',
                        'POST'
                    ],
                    'required' => [],
                    'path' => '',
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_GLOBAL,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_WRITE,
                    ],
                ], $this),
                'api.get' => new action([
                    'name' => 'API: посмотреть',
                    'method' => 'api_get',
                    'http' => [
                        'GET',
                    ],
                    'required' => [

                    ],
                    'path' => "(/:{$keyField})",
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_FOR_ITEM,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_READ,
                    ],
                ], $this),
                'api.update' => new action([
                    'name' => 'API: редактировать',
                    'method' => 'api_update',
                    'http' => [
                        'GET',
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => "(/:{$keyField})",
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_FOR_ITEM,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_WRITE,
                    ],
                ], $this),
                'api.remove' => new action([
                    'name' => 'API: удалить',
                    'method' => 'api_remove',
                    'http' => [
                        'GET',
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => "(/:{$keyField})",
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_FOR_ITEM,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_WRITE,
                    ],
                ], $this),
                'api.remove.multi' => new action([
                    'name' => 'API: мультиудаление',
                    'method' => 'api_remove_multi',
                    'http' => [
                        'GET',
                        'POST',
                    ],
                    'required' => [

                    ],
                    'path' => '(/)',
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__DEFAULT,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_WRITE,
                    ],
                ], $this),
                'api.list' => new action([
                    'name' => 'API: все объекты',
                    'method' => 'api_list',
                    'http' => [
                        'GET',
                    ],
                    'required' => [],
                    'path' => '(/:limit(/:offset))',
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_GLOBAL,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_READ,
                    ],
                ], $this),
                'api.getInfo' => new action([
                    'name' => 'API: информация о данных',
                    'method' => 'api_getInfo',
                    'http' => [
                        'GET',
                    ],
                    'required' => [],
                    'path' => '',
                    'useBase' => false,
                    'template' => 'crud/api.json.tpl',
                    'type' => action::TYPE__API_GLOBAL,
                    'acl' => [
                        aclManager::ACL__GROUP_ADMIN,
                        aclManager::ACL__GROUP_API_FULL,
                        aclManager::ACL__GROUP_API_READ
                    ],
                ], $this),
            ]);
        }

        $this->bind();
        $this->registerActionsGroup();
    }

    /**
     * Bind some custom actions
     *
     * @return mixed
     */
    abstract public function bind();

    /**
     * Set options inside this method
     *
     * @return mixed
     */
    abstract public function setOptions();

    public function registerAction($name, $action)
    {
        if(!($action instanceof action)) {
            throw new actionException("Action {$name} is not valid action!");
        }

        $this->actions[$name] = $action;
    }

    public function unregisterAction($name)
    {
        if(!isset($this->actions[$name])) {
            throw new actionException("No action {$name} found to remove!");
        }

        unset($this->actions[$name]);
    }

    /**
     * Load routes for current application
     *
     * @return action[]
     */
    public function getActions()
    {
        return $this->actions;
    }

    /**
     * Load routes for current application
     *
     * @param $name
     *
     * @return action
     * @throws actionException
     */
    public function getAction($name)
    {
        if(!isset($this->actions[$name])) {
            throw new actionException("Unknown action {$name}");
        }

        return $this->actions[$name];
    }

    /**
     * Find action
     *
     * @param action $action
     *
     * @return string Key of action
     * @throws actionException
     */
    public function findAction(action $action)
    {
        return array_search($action, $this->actions, true);
    }

    /**
     * @param array $actions
     */
    public function setActions($actions)
    {
        $this->actions = $actions;
    }

    private function registerActionsGroup()
    {
        $entityName = ltrim($this->getEntityUniqueName(), '/');
        $entityActions = array_keys($this->actions);

        $groupsListHash = md5($entityName . implode(',', $entityActions));
        $cacheKey = "action/groups/{$entityName}/{$groupsListHash}";
        MPCMF_LL_DEBUG && self::log()->addDebug("[{$entityName}] Checking action's groups registration... (key: {$cacheKey})");
        if (!cache::getCached($cacheKey)) {
            MPCMF_LL_DEBUG && self::log()->addDebug("[{$entityName}] Cached not found, building groups...");
            $entityAclGroups = [];

            /** @var action $actionData */
            foreach ($entityActions as $actionName) {
                $entityAclGroups[] = "{$entityName}/{$actionName}";
            }

            MPCMF_DEBUG && self::log()->addDebug("[{$entityName}] Registering action's groups: " . count($entityAclGroups));
            MPCMF_LL_DEBUG && self::log()->addDebug("[{$entityName}] Groups: " . implode(',', $entityAclGroups));

            aclManager::getInstance()->createGroupsByList($entityAclGroups);
            cache::setCached($cacheKey, true);
        } else {
            MPCMF_LL_DEBUG && self::log()->addDebug("[{$entityName}] Found registered groups, skipping");
        }
    }
}