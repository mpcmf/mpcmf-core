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
        // static variables are shared by subclasses since PHP 8.1
        static $instances = [];

        $calledClass = static::class;
        if(!isset($instances[$calledClass])) {
            $instances[$calledClass] = new $calledClass();
        }

        return $instances[$calledClass];
    }
}
