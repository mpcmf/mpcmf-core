<?php

namespace mpcmf\system\application;

/**
 * Description of application
 *
 * @author GreeveX <greevex@gmail.com>
 */
abstract class consoleApplicationBase
    extends applicationBase
{

    /**
     * Handle function
     *
     * @return mixed
     */
    abstract protected function handle();

    /**
     * Runs the current application.
     *
     *
     * @return int 0 if everything went fine, or an error code
     *
     * @throws \Exception When doRun returns Exception
     *
     * @api
     */
    public function run()
    {
        MPCMF_DEBUG && self::log()->addDebug('Console application starts...');
        $this->handle();
        MPCMF_DEBUG && self::log()->addDebug('Console application ends...');
    }
}
