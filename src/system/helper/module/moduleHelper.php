<?php

namespace mpcmf\system\helper\module;

use mpcmf\loader;
use mpcmf\modules\moduleBase\exceptions\moduleException;
use mpcmf\modules\moduleBase\entities\entityBase;
use mpcmf\modules\moduleBase\moduleBase;
use mpcmf\modules\moduleBase\moduleRoutesBase;
use mpcmf\system\helper\io\log;

/**
 * Log trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * */
trait moduleHelper
{
    use log;

    /** @var moduleRoutesBase */
    private $moduleRoutesInstance;

    /** @var moduleRoutesBase */
    private $moduleInstance;

    /** @var array */
    private $entities;

    /**
     * Get module directory
     *
     * @return string
     * @throws moduleException
     */
    public function getTemplatesDirectory()
    {
        static $templateDirectory;

        if(true || $templateDirectory === null) {
            $templateDirectory = "{$this->getModuleDirectory()}/templates";

            if (!file_exists($templateDirectory) || !is_dir($templateDirectory)) {
                throw new moduleException('Module templates directory doesn\'t exists!');
            }
        }

        return $templateDirectory;
    }

    /**
     * Get module namespace
     *
     * @return string
     */
    public function getModuleNamespace()
    {
        static $namespace;

        if(true || $namespace === null) {
            $namespace = (new \ReflectionClass(get_called_class()))->getNamespaceName();
            MPCMF_DEBUG && self::log()->addDebug("Module namespace: {$namespace}");
        }

        return $namespace;
    }

    public function getModuleRoutes()
    {
        if(true || $this->moduleRoutesInstance === null) {
            /** @var moduleRoutesBase $class */
            $class = $this->getModuleNamespace() . '\routes';
            $this->moduleRoutesInstance = $class::getInstance();
        }

        return $this->moduleRoutesInstance;
    }

    public function getModule()
    {
        if(true || $this->moduleInstance === null) {
            /** @var moduleBase $class */
            $class = $this->getModuleNamespace() . '\module';
            MPCMF_DEBUG && self::log()->addDebug("Add module class: {$class}");
            $this->moduleInstance = $class::getInstance();
        }

        return $this->moduleInstance;
    }

    public function getModuleName()
    {
        static $moduleName;

        if(true || $moduleName === null) {
            $moduleName = basename($this->getModuleDirectory());
        }

        return $moduleName;
    }

    /**
     * @return entityBase[]
     * @throws moduleException
     */
    public function getEntities()
    {
        if($this->entities === null) {
            $this->entities = [];
            $entitiesDirectory = "{$this->getModuleDirectory()}/entities";
            foreach(scandir($entitiesDirectory) as $file) {
                if(strpos($file, '.') === 0) {
                    continue;
                }
                $entityName = basename($file, '.php');
                /** @var entityBase $entityClass */
                $entityClass = "{$this->getModuleNamespace()}\\entities\\{$entityName}";
                $this->entities[$entityName] = $entityClass::getInstance();
            }
        }

        return $this->entities;
    }

    /**
     * Get application directory
     *
     * @return string
     * @throws moduleException
     */
    public function getModuleDirectory()
    {
        static $directory;

        if(true || $directory === null) {
            $calledClass = get_called_class();
            $file = loader::getLoader()->findFile($calledClass);
            if(!$file) {
                throw new moduleException("File for class {$calledClass} not found!");
            }
            $directory = dirname($file);
        }

        return $directory;
    }
}
