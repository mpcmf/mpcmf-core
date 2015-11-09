<?php

namespace mpcmf\system\helper\module;

use mpcmf\modules\msFilter\mappers\userMapper;
use mpcmf\loader;
use mpcmf\modules\moduleBase\exceptions\controllerException;
use mpcmf\modules\moduleBase\exceptions\entityException;
use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\exceptions\modelException;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\modules\moduleBase\actions\actionsBase;
use mpcmf\modules\moduleBase\controllers\controllerBase;
use mpcmf\modules\moduleBase\entities\entityBase;
use mpcmf\modules\moduleBase\mappers\mapperBase;
use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\modules\moduleBase\moduleBase;
use mpcmf\system\helper\io\log;

/**
 * Log trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * */
trait modulePartsHelper
{
    use log;

    private $currentNamespace;
    private $currentClassName;
    private $currentShortName;
    private $currentDirectory;
    private $entityName;
    private $entityUniqueName;
    private $entityInstance;
    private $entityActions;
    private $entityMapper;
    private $entityModel;
    private $entityController;
    private $entityPublicName;
    private $moduleInstance;
    private $moduleName;
    private $moduleNamespace;

    /**
     * Get current namespace
     *
     * @return string
     */
    public function getCurrentNamespace()
    {
        if(!isset($this->currentNamespace)) {
            $currentClass = get_called_class();
            if (!isset(modulePartsStorage::$namespaces[$currentClass])) {
                modulePartsStorage::$namespaces[$currentClass] = (new \ReflectionClass($currentClass))->getNamespaceName();
                MPCMF_DEBUG && self::log()->addDebug('Initializing namespace: ' . modulePartsStorage::$namespaces[$currentClass], [$currentClass]);
            }

            $this->currentNamespace = modulePartsStorage::$namespaces[$currentClass];
        }

        return $this->currentNamespace;
    }

    /**
     * Get current class name
     *
     * @return string
     */
    public function getCurrentClassName()
    {
        if(!isset($this->currentClassName)) {
            $this->currentClassName = get_called_class();
        }

        return $this->currentClassName;
    }

    /**
     * Get current class name
     *
     * @return string
     */
    public function getShortClassName()
    {
        if(!isset($this->currentShortName)) {
            $this->currentShortName = trim(str_replace($this->getCurrentNamespace(), '', $this->getCurrentClassName()), '/\\ ');
        }

        return $this->currentShortName;
    }

    /**
     * Get controller directory
     *
     * @return string
     * @throws modulePartsHelperException
     */
    public function getCurrentDirectory()
    {
        if(!isset($this->currentDirectory)) {
            $currentClass = get_called_class();
            if(!isset(modulePartsStorage::$directories[$currentClass])) {
                $file = loader::getLoader()->findFile($currentClass);
                if(!$file) {
                    self::log()->addCritical('File for class not found!', [$currentClass]);
                    throw new modulePartsHelperException("File for class {$currentClass} not found!");
                }
                modulePartsStorage::$directories[$currentClass] = dirname($file);
            }

            $this->currentDirectory = modulePartsStorage::$directories[$currentClass];
        }

        return $this->currentDirectory;
    }

    /**
     * Get current entity name
     *
     * @return string
     */
    public function getEntityName()
    {
        if(!isset($this->entityName)) {
            $currentClass = get_called_class();
            if(!isset(modulePartsStorage::$entityNames[$currentClass])) {
                MPCMF_DEBUG && self::log()->addDebug(json_encode(modulePartsStorage::$entityNames, 384));
                MPCMF_DEBUG && self::log()->addDebug(">>>> {$currentClass} <<<<");
                modulePartsStorage::$entityNames[$currentClass] = trim(str_replace($this->getCurrentNamespace(), '', $currentClass), " \t/\\");
                modulePartsStorage::$entityNames[$currentClass] = preg_replace('/(?:' . implode('|', modulePartsStorage::$entityTypes) . ')?$/u', '', modulePartsStorage::$entityNames[$currentClass]);
                MPCMF_DEBUG && self::log()->addDebug('Entity found: ' . modulePartsStorage::$entityNames[$currentClass], [__METHOD__, $currentClass]);

                foreach(modulePartsStorage::$entityTypes as $parentNamespace => $entityType) {
                    $class = preg_replace('/\\\\[^\\\\]+$/', '', $this->getCurrentNamespace()) . "\\{$parentNamespace}\\" . modulePartsStorage::$entityNames[$currentClass] . $entityType;
                    if(!isset(modulePartsStorage::$entityNames[$class])) {
                        MPCMF_DEBUG && self::log()->addDebug("Also use this entity for: {$class}", [__METHOD__, $currentClass]);
                        modulePartsStorage::$entityNames[$class] = modulePartsStorage::$entityNames[$currentClass];
                    }
                }
            }

            $this->entityName = modulePartsStorage::$entityNames[$currentClass];
        }

        return $this->entityName;
    }

    /**
     * Get entity public name
     *
     * @return mixed
     * @throws mapperException
     * @throws modulePartsHelperException
     */
    public function getPublicName()
    {
        if(!isset($this->entityPublicName)) {
            $currentClass = get_called_class();
            if(!isset(modulePartsStorage::$entityPublicNames[$currentClass])) {
                modulePartsStorage::$entityPublicNames[$currentClass] = $this->getMapper()->getPublicName();

                foreach(modulePartsStorage::$entityTypes as $parentNamespace => $entityType) {
                    $class = preg_replace('/\\\\[^\\\\]+$/', '', $this->getCurrentNamespace()) . "\\{$parentNamespace}\\" . $this->getEntityName() . $entityType;
                    if(!isset(modulePartsStorage::$entityPublicNames[$class])) {
                        MPCMF_DEBUG && self::log()->addDebug("Also use this entity public name for: {$class}", [__METHOD__, $currentClass]);
                        modulePartsStorage::$entityPublicNames[$class] = modulePartsStorage::$entityPublicNames[$currentClass];
                    }
                }
            }

            $this->entityPublicName = modulePartsStorage::$entityPublicNames[$currentClass];
        }

        return $this->entityPublicName;
    }

    /**
     * Get current entity name
     *
     * @return string
     */
    public function getEntityUniqueName()
    {
        if($this->entityUniqueName === null) {
            $this->entityUniqueName = "/{$this->getModuleName()}/{$this->getEntityName()}";
        }

        return $this->entityUniqueName;
    }

    /**
     * @return entityBase
     * @throws entityException
     * @throws modulePartsHelperException
     */
    public function getEntity()
    {
        if(!isset($this->entityInstance)) {
            $entityUniqName = $this->getEntityUniqueName();
            if(!isset(modulePartsStorage::$entityInstances[$entityUniqName])) {
                /** @var entityBase $entityClass */
                $entityClass = "{$this->getModule()->getModuleNamespace()}\\entities\\" . $this->getEntityName();
                if(!class_exists($entityClass)) {
                    throw new entityException("Unable to find entity `{$this->getEntityName()}`");
                }

                modulePartsStorage::$entityInstances[$entityUniqName] = $entityClass::getInstance();
            }

            $this->entityInstance = modulePartsStorage::$entityInstances[$entityUniqName];
        }

        return $this->entityInstance;
    }

    /**
     * Get all actions for current entity
     *
     * @return actionsBase
     * @throws modulePartsHelperException
     */
    public function getEntityActions()
    {
        if(!isset($this->entityActions)) {
            $entityUniqName = $this->getEntityUniqueName();
            if(!isset(modulePartsStorage::$entityActionsInstances[$entityUniqName])) {
                /** @var actionsBase $actionsClass */
                $actionsClass = "{$this->getModule()->getModuleNamespace()}\\actions\\" . $this->getEntityName() . 'Actions';
                if(!class_exists($actionsClass)) {
                    throw new modulePartsHelperException("Unable to find actions for entity `{$this->getEntityName()}`");
                }

                modulePartsStorage::$entityActionsInstances[$entityUniqName] = $actionsClass::getInstance();
            }
            $this->entityActions = modulePartsStorage::$entityActionsInstances[$entityUniqName];
        }

        return $this->entityActions;
    }

    /**
     * Get mapper for current entity
     *
     * @return mapperBase|userMapper
     * @throws mapperException
     * @throws modulePartsHelperException
     */
    public function getMapper()
    {
        if(!isset($this->entityMapper)) {
            $entityUniqName = $this->getEntityUniqueName();
            if(!isset(modulePartsStorage::$entityMappers[$entityUniqName])) {
                /** @var mapperBase $mapperClass */
                $mapperClass = "{$this->getModule()->getModuleNamespace()}\\mappers\\" . $this->getEntityName() . 'Mapper';
                if (!class_exists($mapperClass)) {
                    throw new mapperException("Unable to find mapper for entity `{$this->getEntityName()}`");
                }

                modulePartsStorage::$entityMappers[$entityUniqName] = $mapperClass::getInstance();
            }

            $this->entityMapper = modulePartsStorage::$entityMappers[$entityUniqName];
        }

        return $this->entityMapper;
    }

    /**
     * Get model of the current entity
     *
     * @return modelBase
     * @throws modelException
     * @throws modulePartsHelperException
     */
    public function getModel()
    {
        if(!isset($this->entityModel)) {
            $entityUniqName = $this->getEntityUniqueName();
            if(!isset(modulePartsStorage::$entityModels[$entityUniqName])) {
                /** @var modelBase $modelClass */
                $modelClass = "{$this->getModule()->getModuleNamespace()}\\models\\" . $this->getEntityName() . 'Model';
                if (!class_exists($modelClass)) {
                    throw new modelException("Unable to find model for entity `{$this->getEntityName()}`");
                }

                modulePartsStorage::$entityModels[$entityUniqName] = $modelClass::getInstance();
            }

            $this->entityModel = modulePartsStorage::$entityModels[$entityUniqName];
        }

        return $this->entityModel;
    }

    /**
     * Get controller for current entity
     *
     * @return controllerBase
     * @throws controllerException
     * @throws modulePartsHelperException
     */
    public function getController()
    {
        if(!isset($this->entityController)) {
            $entityUniqName = $this->getEntityUniqueName();
            if(!isset(modulePartsStorage::$entityControllers[$entityUniqName])) {
                /** @var controllerBase $controllerClass */
                $controllerClass = "{$this->getModule()->getModuleNamespace()}\\controllers\\" . $this->getEntityName() . 'Controller';
                if (!class_exists($controllerClass)) {
                    throw new controllerException("Unable to find controller for entity `{$this->getEntityName()}`");
                }

                modulePartsStorage::$entityControllers[$entityUniqName] = $controllerClass::getInstance();
            }
            $this->entityController = modulePartsStorage::$entityControllers[$entityUniqName];
        }

        return $this->entityController;
    }

    /**
     * Get module instance for current entity
     *
     * @return moduleBase
     * @throws modulePartsHelperException
     */
    public function getModule()
    {
        if(!isset($this->moduleInstance)) {
            $entityUniqName = $this->getEntityUniqueName();
            if(!isset(modulePartsStorage::$moduleInstances[$entityUniqName])) {

                /** @var moduleBase $class */
                $class = "{$this->getModuleNamespace()}\\module";
                $file = loader::getLoader()->findFile($class);
                if (!$file) {
                    self::log()->addCritical("File for class {$class} not found!", [__METHOD__]);
                    throw new modulePartsHelperException("File for class {$class} not found!");
                }

                modulePartsStorage::$moduleInstances[$entityUniqName] = $class::getInstance();
            }

            $this->moduleInstance = modulePartsStorage::$moduleInstances[$entityUniqName];
        }

        return $this->moduleInstance;
    }

    /**
     * Get module base namespace
     *
     * @return string
     */
    public function getModuleNamespace()
    {
        if(!isset($this->moduleNamespace)) {
            $calledClass = get_called_class();

            if(!isset(modulePartsStorage::$moduleNamespaces[$calledClass])) {
                $pattern = '/\\\\?(mpcmf\\\\modules\\\\[^\\\\]+).*$/u';
                modulePartsStorage::$moduleNamespaces[$calledClass] = preg_replace($pattern, '$1', $calledClass);
            }

            $this->moduleNamespace = modulePartsStorage::$moduleNamespaces[$calledClass];
        }

        return $this->moduleNamespace;
    }

    /**
     * Get module base namespace
     *
     * @return string
     */
    public function getModuleName()
    {
        if(!isset($this->moduleName)) {
            $calledClass = get_called_class();

            if(!isset(modulePartsStorage::$moduleNames[$calledClass])) {
                $pattern = '/^\\\\?mpcmf\\\\modules\\\\([^\\\\]+).*$/u';
                modulePartsStorage::$moduleNames[$calledClass] = preg_replace($pattern, '$1', $calledClass);
            }

            $this->moduleName = modulePartsStorage::$moduleNames[$calledClass];
        }

        return $this->moduleName;
    }
}
