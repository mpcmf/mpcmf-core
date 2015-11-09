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
        static $instance;

        if(!isset($instance)) {
            $calledClass = get_called_class();
            $instance = new $calledClass();
        }

        return $instance;
    }
}
