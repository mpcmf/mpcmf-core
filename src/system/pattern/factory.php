<?php

namespace mpcmf\system\pattern;

use mpcmf\system\configuration\config;
use mpcmf\system\configuration\exception\configurationException;

/**
 * Factory pattern trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait factory
{
    protected $configSection = 'default';

    /**
     * Instance factory by driver package name
     *
     * @static
     * @param string $configSection
     * @see \mpr\interfaces\cache
     * @return static
     */
    public static function factory($configSection = 'default')
    {
        // PHP 8.1+: inherited methods share static variables, so keep instances per called class as PHP 7.4 did;
        // still `new self` as before: a subclass without its own `use factory` gets an instance of the class using the trait
        static $instances = [];

        $calledClass = static::class;
        if (!isset($instances[$calledClass][$configSection])) {
            $instances[$calledClass][$configSection] = new self($configSection);
        }

        return $instances[$calledClass][$configSection];
    }

    protected function getPackageConfig()
    {
        // PHP 8.1+: inherited methods share static variables, so cache per called class
        static $configs = [];

        $calledClass = static::class;
        if (!isset($configs[$calledClass])) {
            $configs[$calledClass] = config::getConfig($calledClass);
        }
        $config = $configs[$calledClass];

        if(!isset($config[$this->configSection])) {
            // TODO :: Check, really configurationException needed to throw or factoryException
            throw new configurationException("Unable to find configSection: \"{$this->configSection}\"");
        }

        return $config[$this->configSection];
    }

    public function __construct($configSection)
    {
        $this->configSection = $configSection;
    }
}
