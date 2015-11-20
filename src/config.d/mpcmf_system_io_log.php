<?php

use Monolog\Logger;
use mpcmf\system\configuration\config;

$path = '/tmp/mpcmf.log';

$defaultLevel = Logger::DEBUG;
config::setConfig(__FILE__, [
    'default' => [
        'name' => 'BaseLog',
        'path' => 'php://stdout',
        'level' => $defaultLevel
    ],
    'applicationBase' => [
        'name' => 'ApplicationLog',
        'path' => 'php://stdout',
        'level' => $defaultLevel
    ],
    'controllerBase' => [
        'name' => 'ControllerLog',
        'path' => 'php://stdout',
        'level' => $defaultLevel
    ],
]);
