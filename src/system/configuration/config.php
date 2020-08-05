<?php

namespace mpcmf\system\configuration;

use mpcmf\loader;
use mpcmf\system\configuration\exception\configurationException;
use mpcmf\system\helper\system\profiler;
use mpcmf\system\io\log;

/**
 * System config class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/24/15 5:02 PM
 */
class config
{
    /**
     * Root directory filename
     */
    const ROOT_FILE = '.mpcmfroot';

    /**
     * Namespace separator symbol
     */
    const NAMESPACE_SEPARATOR = '\\';

    /**
     * Config separator to replace namespace separator
     */
    const CONFIG_SEPARATOR = '_';

    /**
     * Loaded configs data
     *
     * @var array[]
     */
    protected static $loaded = [];

    /**
     * Common custom config data
     *
     * @var array[]
     */
    protected static $common = [];

    /**
     * Find project root path
     *
     * @static
     *
     * @return string
     * @throws configurationException
     */
    protected static function getProjectRoot()
    {
        static $projectRoot;

        if($projectRoot === null) {
            if(!defined('APP_ROOT')) {
                $currentPath = __DIR__;
                while (($parentPath = dirname($currentPath)) !== $currentPath) {
                    $currentPath = $parentPath;
                    if (in_array(self::ROOT_FILE, scandir($currentPath), true)) {
                        $projectRoot = $currentPath;
                    }
                }

                if ($projectRoot === null) {
                    throw new configurationException("Unable to find project root, absolute root found: \"{$currentPath}\"");
                }
            } else {
                $projectRoot = APP_ROOT;
            }
        }

        return $projectRoot;
    }

    /**
     * Get package name by class name
     *
     * @static
     * @param string $class
     *
     * @return string Package name
     */
    public static function getPackageName($class)
    {
        static $configNamesCached = [];

        if(!isset($configNamesCached[$class])) {
            $suffix = substr($class, -4);
            if(strtolower($suffix) === '.php') {
                $configNamesCached[$class] = basename($class, $suffix);
            } else {
                $class = trim($class, self::NAMESPACE_SEPARATOR);
                $configNamesCached[$class] = str_replace(self::NAMESPACE_SEPARATOR, self::CONFIG_SEPARATOR, $class);
            }
        }

        return $configNamesCached[$class];
    }

    /**
     * Get full class name by package name
     *
     * @static
     * @param string $packageName
     *
     * @return string Class name with namespace
     */
    public static function getClassName($packageName)
    {
        static $classNamesCached = [];

        if(!isset($classNamesCached[$packageName])) {
            $classNamesCached[$packageName] = str_replace(self::CONFIG_SEPARATOR, self::NAMESPACE_SEPARATOR, $packageName);
        }

        return $classNamesCached[$packageName];
    }

    public static function getConfig($name, $environment = null)
    {
        static $currentEnvironment, $baseEnvironment;

        profiler::addStack('config::get');

        if($currentEnvironment === null) {
            $currentEnvironment = environment::getCurrentEnvironment();
            MPCMF_LL_DEBUG && error_log("Initialize config current environment: {$currentEnvironment}");
        }

        if($baseEnvironment === null) {
            $baseEnvironment = environment::getBaseEnvironment();
            MPCMF_LL_DEBUG && error_log("Initialize config base environment: {$baseEnvironment}");
        }

        if($environment === null) {
            $environment = $currentEnvironment;
        }

        MPCMF_LL_DEBUG && error_log("Input environment: {$environment}");

        $packageName = self::getPackageName($name);

        class_exists('log') && log::factory()->addDebug("Loading config for {$packageName}");

        if(!isset(self::$loaded[$packageName])) {
            self::loadPackageConfig($packageName);
        }

        class_exists('log') && log::factory()->addDebug("Set by name: {$name} / env: {$environment}");

        if($environment !== environment::ENV_DEFAULT) {
            if(isset(self::$loaded[$packageName][$currentEnvironment])) {
                MPCMF_LL_DEBUG && error_log("Return requested config [{$packageName}] {$currentEnvironment}");
                return self::$loaded[$packageName][$currentEnvironment];
            } elseif ($baseEnvironment !== environment::ENV_DEFAULT && isset(self::$loaded[$packageName][$baseEnvironment])) {
                MPCMF_LL_DEBUG && error_log("Use base config [{$packageName}] {$baseEnvironment}");
                return self::$loaded[$packageName][$baseEnvironment];
            } else {
                MPCMF_LL_DEBUG && error_log("Return default config [{$packageName}] {$currentEnvironment}");
                return self::$loaded[$packageName][environment::ENV_DEFAULT];
            }
        }

        MPCMF_LL_DEBUG && error_log("Return config [{$packageName}] " . environment::ENV_DEFAULT);
        return self::$loaded[$packageName][environment::ENV_DEFAULT];
    }

    public static function setConfig($name, $config, $environment = environment::ENV_DEFAULT)
    {
        $packageName = self::getPackageName($name);

        if(!isset(self::$loaded[$packageName])) {
            self::$loaded[$packageName] = [];
        }

        MPCMF_LL_DEBUG && error_log("Set config [{$packageName}] {$environment} ...");

        self::$loaded[$packageName][$environment] = $config;
    }

    public static function getConfigFilepath($name)
    {
        $packageName = self::getPackageName($name);

        return self::getBasePath() . DIRECTORY_SEPARATOR . "{$packageName}.php";
    }

    public static function setConfigByEnvironment($name, $targetEnvironment, $sourceEnvironment = environment::ENV_DEFAULT)
    {
        $packageName = self::getPackageName($name);

        if(!isset(self::$loaded[$packageName])) {
            self::$loaded[$packageName] = [];
        }

        MPCMF_LL_DEBUG && error_log("Set config [{$packageName}] {$sourceEnvironment} => {$targetEnvironment} ...");

        self::$loaded[$packageName][$targetEnvironment] =& self::$loaded[$packageName][$sourceEnvironment];
    }

    protected static function loadPackageConfig($packageName)
    {
        $filename = self::getBasePath() . DIRECTORY_SEPARATOR . "{$packageName}.php";

        if(file_exists($filename) && is_readable($filename)) {
            MPCMF_LL_DEBUG && error_log("Loading config [{$packageName}] {$filename} ...");
            require_once $filename;

            return;
        }

        MPCMF_LL_DEBUG && error_log("Config for [{$packageName}]: Not found {$filename}, try to use module config ...");

        $filename = self::getModuleBasePath($packageName) . DIRECTORY_SEPARATOR . "{$packageName}.php";

        if(!file_exists($filename) || !is_readable($filename)) {
            throw new configurationException("Configuration not found for package: {$packageName}! Please, fix it in {$filename}");
        }

        MPCMF_LL_DEBUG && error_log("Loading config [{$packageName}] {$filename} ...");

        require_once $filename;
    }

    protected static function getBasePath()
    {
        static $cached;

        if($cached === null) {
            $basePath = '%s' . DIRECTORY_SEPARATOR . 'config.%s.d';

            $cached = sprintf($basePath, self::getProjectRoot(), environment::ENV_DEFAULT);
        }

        return $cached;
    }

    protected static function getModuleBasePath($packageName)
    {
        static $cached = [];

        if(!array_key_exists($packageName, $cached)) {
            $className = self::getClassName($packageName);
            $filePath = loader::getLoader()->findFile($className);

            $modulePath = null;
            $currentPath = dirname($filePath);
            while (($parentPath = dirname($currentPath)) !== $currentPath) {
                $currentPath = $parentPath;

                if (in_array('composer.json', scandir($currentPath), true)) {
                    $modulePath = $currentPath;

                    break;
                }
            }

            $cached[$packageName] = null;
            if ($modulePath !== null) {
                $pathPattern = '%s' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config.d';
                $cached[$packageName] = sprintf($pathPattern, $modulePath);
            }
        }

        return $cached[$packageName];
    }
}
