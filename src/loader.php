<?php

namespace mpcmf;

use Composer\Autoload\ClassLoader;
use mpcmf\system\io\log;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'mpcmf');
}
$GLOBALS['MPCMF_START_TIME'] = microtime(true);

require_once __DIR__ . '/../vendor/autoload.php';

class cache
{
    protected static $cacheBasePath = '/tmp/mpcmf';
    protected static $expire = 172800;

    public static function getBaseCachePath()
    {
        return self::$cacheBasePath;
    }

    protected static function getPath($key)
    {
        return self::$cacheBasePath . "/{$key}.cache";
    }

    /**
     * @param $key
     *
     * @return array|string
     */
    public static function getCached($key)
    {
        profiler::addStack('file_cache');

        $cachePath = self::getPath($key);
        if(!file_exists($cachePath)) {
            return false;
        }

        $expired = false;

        $storedData = unserialize(file_get_contents($cachePath));
        $now = time();
        if(($storedData['e'] > 0 && $storedData['e'] < $now) || ($now - filemtime($cachePath) > self::$expire)) {
            $expired = true;
        }

        if($expired) {
            @unlink($cachePath);

            return false;
        }

        return $storedData['v'];
    }

    public static function setCached($key, $value, $expire = 0)
    {
        profiler::addStack('file_cache');

        $cachePath = self::getPath($key);
        $dirname = dirname($cachePath);
        if (file_exists($cachePath) && !@chmod($cachePath, 0777)) {
            @unlink($cachePath);
        } elseif(!file_exists($dirname)) {
            @mkdir($dirname, 0777, true);
        }

        $storeData = [
            'e' => $expire > 0 ? (time() + $expire) : 0,
            'v' => $value
        ];

        $attempts = 5;
        do {
            $result = file_put_contents($cachePath, serialize($storeData));
            if($result !== false) {
                break;
            }
            usleep(10000);
        } while($result === false && --$attempts > 0);
    }
}

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
        $loader = self::getLoader();
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
                    APP_ROOT . '/system/modules'
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
        $loader->addPsr4('mpcmf\\', [APP_ROOT], true);
    }
}

class profiler
{
    private static $instance;
    private static $internalCounter = 0;

    private $timing = [
        'pending' => [],
        'results' => [],
    ];

    private static $stack = [];

    public static function addStack($key)
    {
        if(!isset(self::$stack[$key])) {
            self::$stack[$key] = 1;
        } else {
            self::$stack[$key]++;
        }
    }

    public static function getStack()
    {
        return self::$stack;
    }

    public static function resetStack()
    {
        self::$stack = [];
    }

    public static function getStackAsString($implodeBy = ' / ', $filterRegex = null)
    {
        $now = microtime(true);
        $strings = [
            'php::time: ' . number_format($now - $_SERVER['REQUEST_TIME_FLOAT'], 6),
            'app::time: ' . number_format($now - $GLOBALS['MPCMF_START_TIME'], 6),
        ];
        foreach(self::$stack as $key => $value) {
            if($filterRegex === null || preg_match($filterRegex, $key)) {
                $strings[] = "{$key}: {$value}";
            }
        }

        return implode($implodeBy, $strings);
    }

    /**
     * @return profiler
     */
    public static function get()
    {
        if(self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function start($key)
    {
        $this->timing['pending'][$key] = [
            'counter' => self::$internalCounter++,
            'mem' => memory_get_usage(true),
            'time' => microtime(true)
        ];
    }

    public function stop($key)
    {
        if(!isset($this->timing['results'][$key])) {
            $this->timing['results'][$key] = [];
        }
        $record = $this->timing['pending'][$key];
        $this->timing['results'][$key][$record['counter']] = [
            'key' => $key,
            'mem' => memory_get_usage(true) - $record['mem'],
            'time' => microtime(true) - $record['time'],
        ];
        unset($this->timing['pending'][$key]);
    }

    public function popResults()
    {
        return array_splice($this->timing['results'], 0);
    }

    public function getResults()
    {
        return $this->timing['results'];
    }

    public function getAvgResults()
    {
        $results = [];
        foreach($this->timing['results'] as $key => $result) {
            $results[$key] = [
                'calls' => 0,
                'memTotal' => 0,
                'memAvg' => 0,
                'timeAvg' => 0
            ];
            foreach($result as $keyCounter => $keyData) {
                $results[$key]['calls']++;
                $results[$key]['memTotal'] += $keyData['mem'];
                $results[$key]['timeTotal'] += $keyData['time'];
            }
            $results[$key]['memAvg'] = $results[$key]['memTotal'] / $results[$key]['calls'];
            $results[$key]['timeAvg'] = $results[$key]['timeTotal'] / $results[$key]['calls'];
        }

        return $results;
    }
}
loader::load();

require_once APP_ROOT . '/environment.php';

MPCMF_DEBUG && log::factory()->addDebug('Base project directory: ' . APP_ROOT);
