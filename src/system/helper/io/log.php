<?php

namespace mpcmf\system\helper\io;

use \mpcmf\system\io\log as monologWrapper;

/**
 * Log trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait log
{
    /**
     * @return monologWrapper
     */
    protected static function log()
    {
        return monologWrapper::factory();
    }
}
