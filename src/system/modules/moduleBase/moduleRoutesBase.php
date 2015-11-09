<?php

namespace mpcmf\modules\moduleBase;

use mpcmf\modules\moduleBase\exceptions\moduleException;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\modules\moduleBase\exceptions\routerException;
use mpcmf\system\helper\module\moduleHelper;
use mpcmf\system\pattern\singletonInterface;

/**
 * moduleRoutesBase abstraction class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
abstract class moduleRoutesBase
    implements singletonInterface
{

    use moduleHelper;

    protected $routes = [];
    protected $structure = [];

    /**
     * Instantiate route object
     *
     * @throws routerException
     */
    public function __construct()
    {
        $moduleName = $this->getModuleName();
        MPCMF_DEBUG && self::log()->addDebug("Setting up routes for module {$moduleName}");
        try {
            foreach ($this->getEntities() as $entityName => $entity) {
                $key = $entity->getEntityUniqueName();
                if (!isset($this->structure[$key])) {
                    $this->structure[$key] = [
                        'entity' => $entity,
                        'actions' => []
                    ];
                }
                MPCMF_DEBUG && self::log()->addDebug('[Routes] Class: ' . get_called_class() . " / uniq: {$key}");
                MPCMF_DEBUG && self::log()->addDebug("Processing routes for entity {$entityName}...");
                foreach ($entity->getEntityActions()->getActions() as $actionName => $action) {
                    $defaultPath = "{$key}/{$actionName}";
                    if (!isset($this->structure[$key]['actions'][$defaultPath])) {
                        $this->structure[$key]['actions'][$defaultPath] = $action;
                    }

                    $fullRoutePath = $action->isRelative() ? "{$defaultPath}{$action->getPath()}" : $action->getPath();

                    MPCMF_DEBUG && self::log()->addDebug("Registering route action {$defaultPath}...");
                    MPCMF_DEBUG && self::log()->addDebug("Path: {$fullRoutePath}");

                    $this->register($defaultPath, [
                        'route' => $fullRoutePath,
                        'action' => $action
                    ]);
                }
            }
        } catch(moduleException $moduleException) {
            throw new routerException("Unable to load entities: {$moduleException->getMessage()}", $moduleException->getCode(), $moduleException);
        } catch(modulePartsHelperException $modulePartsHelperException) {
            throw new routerException("Unable to load entity actions: {$modulePartsHelperException->getMessage()}", $modulePartsHelperException->getCode(), $modulePartsHelperException);
        }

        $this->bind();
    }

    /**
     * Get routes structure as a multi-dimensional array
     *
     * @return array
     */
    public function getStructure()
    {

        return $this->structure;
    }

    /**
     * Register some routes
     *
     * @return mixed
     */
    abstract public function bind();

    /**
     * Register route by name and route-data-array
     *
     * @param $name
     * @param $route
     */
    public function register($name, $route)
    {
        $this->routes[$name] = $route;
    }

    /**
     * Unregister route by name
     *
     * @param $name
     *
     * @throws routerException
     */
    public function unregister($name)
    {
        if(!isset($this->routes[$name])) {
            throw new routerException("No route {$name} found to remove!");
        }

        unset($this->routes[$name]);
    }

    /**
     * Get all routes for current module
     *
     * @return array
     */
    public function getRoutes()
    {
        return $this->routes;
    }
}