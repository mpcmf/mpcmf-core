<?php

namespace mpcmf\system\helper\cache;

use mpcmf\system\cache\memcached;

/**
 * Cache trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait cache
{
    /**
     * @return memcached
     */
    protected static function cache()
    {
        static $instance;

        if($instance === null) {
            $instance = memcached::factory();
        }

        return $instance;
    }

    public static function getCacheKey($cacheKey, $prefix = '', $md5 = false)
    {
        return ($prefix ? "{$prefix}/" : '') . ($md5 ? md5($cacheKey) : $cacheKey);
    }
}
