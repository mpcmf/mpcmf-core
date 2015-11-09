<?php

namespace mpcmf\system\application;

use mpcmf\system\application\exception\applicationException;
use mpcmf\system\pattern\singleton;

/**
 * Application accessor class
 *
 * @author GreeveX <greevex@gmail.com>
 */
class applicationInstance
    extends applicationBase
    implements applicationInterface
{

    use singleton;

    /**
     * @var applicationBase|consoleApplicationBase|consoleBase|webApplicationBase
     */
    private $application;

    /**
     * Set current application
     *
     * @param applicationBase|consoleApplicationBase|consoleBase|webApplicationBase $application
     *
     * @throws applicationException
     */
    public function setApplication($application)
    {
        if(!$application instanceof applicationBase) {
            throw new applicationException('Application instance is not an valid application!');
        }
        $this->application = $application;
    }

    /**
     * Runs the current application.
     *
     * @api
     */
    public function run()
    {
        if(!is_object($this->application)) {
            throw new applicationException('Application instance is not exists');
        }
        $this->application->run();
    }

    /**
     * Get current instance of application
     *
     * @return applicationBase|consoleApplicationBase|consoleBase|webApplicationBase
     */
    public function getCurrentApplication()
    {
        return $this->application;
    }
}
