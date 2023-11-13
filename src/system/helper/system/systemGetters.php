<?php

namespace mpcmf\system\helper\system;

use mpcmf\system\application\applicationBase;
use mpcmf\system\application\applicationInstance;
use mpcmf\system\application\consoleApplicationBase;
use mpcmf\system\application\consoleBase;
use mpcmf\system\application\exception\webApplicationException;
use mpcmf\system\application\webApplicationBase;
use mpcmf\system\http\slimDriver;

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
        if ($instance === null) {
            $instance = applicationInstance::getInstance()->getCurrentApplication();
        }

        return $instance;
    }

    /**
     * @return slimDriver
     * @throws webApplicationException
     */
    protected function getSlim()
    {
        static $slim;
        if ($slim === null) {
            $slim = $this->getApplication()->slim();
        }

        return $slim;
    }
}
