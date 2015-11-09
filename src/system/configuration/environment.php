<?php

namespace mpcmf\system\configuration;

/**
 * System environment class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/24/15 5:02 PM
 */
class environment
{

    const ENV_DEFAULT = 'default';
    const ENV_DEBUG = 'debug';
    const ENV_PRODUCTION = 'production';

    /**
     * @var string Environment name
     */
    protected static $environment;

    /**
     * Get name of the current environment
     *
     * @return string
     */
    public static function getCurrentEnvironment()
    {
        if(!isset(self::$environment)) {
            self::$environment = self::ENV_DEFAULT;
        }

        return self::$environment;
    }

    /**
     * Set name of the current environment
     *
     * @param string $inputEnvironment
     */
    public static function setCurrentEnvironment($inputEnvironment)
    {
        self::$environment = (string)$inputEnvironment;
    }
}
