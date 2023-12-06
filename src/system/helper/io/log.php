<?php

namespace mpcmf\system\helper\io;

/**
 * Log trait
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait log
{

    protected static function log(): \mpcmf\system\io\log
    {
        return \mpcmf\system\io\log::factory();
    }
}
