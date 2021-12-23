<?php

namespace mpcmf\system\io\monolog\customProcessors;

class mpcmfPidProcessor
{

    public function __invoke(array $record)
    {
        $record['pid'] = getmypid();

        return $record;
    }
}