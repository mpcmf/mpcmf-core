<?php

namespace mpcmf\system\helper\system;

use mpcmf\system\application\applicationBase;
use mpcmf\system\application\applicationInstance;
use mpcmf\system\application\consoleApplicationBase;
use mpcmf\system\application\consoleBase;
use mpcmf\system\application\webApplicationBase;
use mpcmf\system\application\exception\webApplicationException;
use Slim\Slim;

/**
 * System getters helper
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait systemGetters
{

    /**
     * @return applicationBase|consoleApplicationBase|consoleBase|webApplicationBase
     */
    protected function getApplication()
    {
        static $instance;
        if (!isset($instance)) {
            $instance = applicationInstance::getInstance()->getCurrentApplication();
        }

        return $instance;
    }

    /**
     * @return Slim
     * @throws webApplicationException
     */
    protected function getSlim()
    {
        static $slim;
        if (!isset($slim)) {
            $slim = $this->getApplication()->slim();
        }

        return $slim;
    }
}
