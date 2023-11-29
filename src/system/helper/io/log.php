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

    public function addDebug()
    {
        /** @see \mpcmf\system\io\log::debug() */
        $this->debug(...func_get_args());
    }

    public function addInfo()
    {
        /** @see \mpcmf\system\io\log::info() */
        $this->info(...func_get_args());
    }

    public function addNotice()
    {
        /** @see \mpcmf\system\io\log::notice() */
        $this->notice(...func_get_args());
    }

    public function addWarning()
    {
        /** @see \mpcmf\system\io\log::warning() */
        $this->warning(...func_get_args());
    }

    public function addError()
    {
        /** @see \mpcmf\system\io\log::error() */
        $this->error(...func_get_args());
    }

    public function addCritical()
    {
        /** @see \mpcmf\system\io\log::critical() */
        $this->critical(...func_get_args());
    }

    public function addAlert()
    {
        /** @see \mpcmf\system\io\log::alert() */
        $this->alert(...func_get_args());
    }

    public function addEmergency()
    {
        /** @see \mpcmf\system\io\log::emergency() */
        $this->emergency(...func_get_args());
    }
}
