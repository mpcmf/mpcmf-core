<?php

namespace mpcmf;

use mpcmf\system\configuration\environment;

environment::setCurrentEnvironment(environment::ENV_PRODUCTION);

$localEnvironmentFile = APP_ROOT . '/environment.local.php';

if(file_exists($localEnvironmentFile)) {
    require_once $localEnvironmentFile;
}

if(!defined('MPCMF_DEBUG')) {
    define('MPCMF_DEBUG', false);
}

if(!defined('MPCMF_LL_DEBUG')) {
    define('MPCMF_LL_DEBUG', false);
}