<?php

namespace mpcmf\system\application;

use mpcmf\system\configuration\config;
use mpcmf\system\helper\io\log;

/**
 * Description of application
 *
 * @author GreeveX <greevex@gmail.com>
 */
abstract class applicationBase
    implements applicationInterface
{
    use log;

    /**
     * Get package config data
     *
     * @return mixed
     */
    protected function getPackageConfig()
    {
        return config::getConfig(get_called_class());
    }

    /**
     * Get package config data
     *
     * @return mixed
     */
    public function getApplicationName()
    {
        $reflection = new \ReflectionClass(get_called_class());
        return $reflection->getShortName();
    }
}
