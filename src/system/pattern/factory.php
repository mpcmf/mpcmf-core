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
        static $instances = [];

        if (!isset($instances[$configSection])) {
            $instances[$configSection] = new self($configSection);
        }

        return $instances[$configSection];
    }

    protected function getPackageConfig()
    {
        static $config;

        if ($config === null) {
            $config = config::getConfig(get_called_class());
        }

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
