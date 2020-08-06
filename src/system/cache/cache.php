<?php

namespace mpcmf\system\cache;

use mpcmf\system\configuration\environment;
use mpcmf\system\helper\system\profiler;

/**
 * Class cache
 *
 * @package mpcmf\system\cache
 * @author Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date 11/18/15 5:16 PM
 */
class cache
{
    protected static $cacheBasePath = '/tmp/mpcmf';
    protected static $expire = 172800;

    /**
     * @param string $baseCachePath Base cache path (Default: "/tmp/mpcmf")
     */
    public static function setBaseCachePath($baseCachePath)
    {
        self::$cacheBasePath = $baseCachePath;
    }

    public static function getBaseCachePath()
    {
        if (defined('MPCMF_MULTI_ENV') && MPCMF_MULTI_ENV) {
            return self::$cacheBasePath . DIRECTORY_SEPARATOR . environment::getCurrentEnvironment();
        }

        return self::$cacheBasePath;
    }

    protected static function getPath($key)
    {
        return self::getBaseCachePath() . "/{$key}.cache";
    }

    /**
     * @param $key
     *
     * @return array|string
     */
    public static function getCached($key)
    {
        profiler::addStack('file_cache::read');

        $cachePath = self::getPath($key);
        if(!file_exists($cachePath)) {
            return false;
        }

        $expired = false;

        try {
            $storedData = unserialize(file_get_contents($cachePath));
            $now = time();
            if(($storedData['e'] > 0 && $storedData['e'] < $now) || ($now - filemtime($cachePath) > self::$expire)) {
                $expired = true;
            }
        } catch (\ErrorException $errorException) {
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
        profiler::addStack('file_cache::write');

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
