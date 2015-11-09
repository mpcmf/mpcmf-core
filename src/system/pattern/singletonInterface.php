<?php

namespace mpcmf\system\pattern;

/**
 * Singleton interface
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
interface singletonInterface
{
    /**
     * Get instance for current actions
     *
     * @return static
     */
    public static function getInstance();
}