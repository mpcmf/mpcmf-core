<?php

namespace mpcmf;

/**
 * Loader
 *
 * @author emelyanov
 * @date 11/18/15
 */

use Composer\Autoload\ClassLoader;
use mpcmf\system\cache\cache;

class loader
{
    private static $loader;

    public static function getLoader()
    {
        if(self::$loader === null) {
            self::$loader = new ClassLoader();
        }

        return self::$loader;
    }

    public static function load()
    {
        if (!defined('CORE_ROOT')) {
            define('CORE_ROOT', __DIR__);
        }

        $loader = self::getLoader();

        $map = require APP_ROOT . '/vendor/composer/autoload_namespaces.php';
        foreach ($map as $namespace => $path) {
            $loader->set($namespace, $path);
        }

        $map = require APP_ROOT . '/vendor/composer/autoload_psr4.php';
        foreach ($map as $namespace => $path) {
            $loader->setPsr4($namespace, $path);
        }

        $classMap = require APP_ROOT . '/vendor/composer/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }

        $loader->register(true);

        $gitFile = APP_ROOT . '/.git/ORIG_HEAD';
        $gitCommit = 'default';
        if(file_exists($gitFile)) {
            $gitCommit = trim(file_get_contents($gitFile));
        }
        $cacheKey = "{$gitCommit}.loader";
        if(!($paths = cache::getCached($cacheKey))) {
            $paths = [
                'modules' => [
                    APP_ROOT . '/system/modules',
                    CORE_ROOT . '/system/modules'
                ],
                'system' => []
            ];

            $appsDir = APP_ROOT . '/apps';
            foreach (scandir($appsDir) as $appName) {
                if ($appName[0] === '.') {
                    continue;
                }

                $currentModulesDir = "{$appsDir}/{$appName}/modules";
                if(file_exists($currentModulesDir)) {
                    $paths['modules'][] = $currentModulesDir;
                }

                $currentModulesSystemDir = "{$appsDir}/{$appName}/system";
                if(file_exists($currentModulesSystemDir)) {
                    $paths['system'][] = $currentModulesSystemDir;
                }
            }

            cache::setCached($cacheKey, $paths);
        }

        $loader->addPsr4('mpcmf\\modules\\', $paths['modules'], false);
        $loader->register(false);

        $loader->addPsr4('mpcmf\\system\\', $paths['system'], true);
        $loader->addPsr4('mpcmf\\', [APP_ROOT, CORE_ROOT], true);
    }
}

loader::load();