<?php

namespace mpcmf\system\pattern;

/**
 * Singleton pattern trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait singleton
{

    /**
     * Get instance for current class
     *
     * @return static
     */
    public static function getInstance()
    {
        // PHP 8.1+: inherited methods share static variables, so keep an instance per called class
        static $instances = [];

        $calledClass = static::class;
        if(!isset($instances[$calledClass])) {
            $instances[$calledClass] = new $calledClass();
        }

        return $instances[$calledClass];
    }
}
