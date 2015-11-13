<?php

namespace mpcmf\system\helper\system;

use mpcmf\modules\moduleBase\moduleBase;
use mpcmf\system\pattern\singleton;

/**
 * System structure helper
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class systemStructure
{
    use singleton;

    protected $apps;
    protected $modules;

    public function __construct()
    {
        $this->load();
    }

    /**
     * @return mixed
     */
    public function getApps()
    {
        return $this->apps;
    }

    /**
     * @return mixed
     */
    public function getModules()
    {
        return $this->modules;
    }

    /**
     * @return moduleBase[]
     */
    public function getModuleInstances()
    {
        $instances = [];

        foreach (systemStructure::getInstance()->getModules() as $moduleNamespace => $moduleName) {
            /** @var moduleBase $moduleClass */
            $moduleClass = $moduleNamespace . '\\module';
            $moduleInstance = $moduleClass::getInstance();
            $instances[$moduleInstance->getModuleName()] = $moduleClass::getInstance();
        }

        return $instances;
    }

    protected function load()
    {
        $this->apps = [];
        $this->modules = [];
        $appsRoot = APP_ROOT . '/apps';
        foreach(scandir($appsRoot) as $appName) {
            if($appName[0] === '.' || !file_exists("{$appsRoot}/{$appName}/{$appName}.php")) {
                continue;
            }
            $this->apps["mpcmf\\apps\\{$appName}"] = $appName;
            $modulesRoot = "{$appsRoot}/{$appName}/modules";
            if(!file_exists($modulesRoot)) {
                continue;
            }
            foreach(scandir($modulesRoot) as $moduleName) {
                if($moduleName[0] === '.' || !file_exists("{$modulesRoot}/{$moduleName}/module.php")) {
                    continue;
                }
                $this->modules["mpcmf\\modules\\{$moduleName}"] = $moduleName;
            }
        }
        $modulesRoot = CORE_ROOT . '/system/modules';
        foreach(scandir($modulesRoot) as $moduleName) {
            if($moduleName[0] === '.' || !file_exists("{$modulesRoot}/{$moduleName}/module.php")) {
                continue;
            }
            $this->modules["mpcmf\\modules\\{$moduleName}"] = $moduleName;
        }
    }
}