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
     * @var string Base environment name
     */
    protected static $baseEnvironment;

    /**
     * Get name of the current environment
     *
     * @return string
     */
    public static function getCurrentEnvironment()
    {
        if(self::$environment === null) {
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

    /**
     * Set name of the current environment
     *
     * @param string $inputEnvironment
     */
    public static function setBaseEnvironment($inputEnvironment)
    {
        self::$baseEnvironment = (string)$inputEnvironment;
    }

    /**
     * Set name of the current environment
     *
     * @return string
     */
    public static function getBaseEnvironment()
    {
        if (self::$baseEnvironment === null) {
            self::$baseEnvironment = self::ENV_DEFAULT;
        }

        return self::$baseEnvironment;
    }
}
