<?php
/**
 * @author greevex
 * @date   : 11/16/12 5:11 PM
 */

use Monolog\Logger;
use mpcmf\system\configuration\config;
use mpcmf\system\helper\io\codes;

$level = Logger::DEBUG;

config::setConfig(__FILE__, [
    'default' => [
        codes::RESPONSE_CODE_FAIL => 'Ошибка:( %s',
        codes::RESPONSE_CODE_OK => 'Всё хорошо.',
        codes::RESPONSE_CODE_SAVED => 'Объект %s сохранён!',
        codes::RESPONSE_CODE_REMOVED => 'Объект удалён!',
        codes::RESPONSE_CODE_CREATED => 'Объект %s создан!',
    ],
]);