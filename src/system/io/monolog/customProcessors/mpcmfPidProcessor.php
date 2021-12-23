<?php

namespace mpcmf\system\io\monolog\customProcessors;

class mpcmfPidProcessor
{

    private $placeholder;

    public function __construct(string $placeholder = 'pid')
    {
        $this->placeholder = $placeholder;
    }

    public function __invoke(array $record)
    {
        $record[$this->placeholder] = getmypid();

        return $record;
    }
}