<?php

/**
 * Child process entry point for the spawn start method of threadPool
 *
 * usage: php runner.php spawn, fd 3 is the task pipe
 */

namespace mpcmf\system\threads;

$mode = isset($argv[1]) ? $argv[1] : '';
if ($mode !== 'spawn') {
    fwrite(STDERR, "usage: php runner.php spawn\n");
    exit(2);
}

foreach (['APP_ROOT' => 'MPCMF_THREAD_APP_ROOT', 'APP_NAME' => 'MPCMF_THREAD_APP_NAME'] as $constant => $variable) {
    $value = getenv($variable);
    if ($value !== false && !defined($constant)) {
        define($constant, $value);
    }
}
if (!defined('APP_ROOT')) {
    fwrite(STDERR, "runner.php: MPCMF_THREAD_APP_ROOT is not set\n");
    exit(2);
}

// the same bootstrap as bin/mpcmf of an application
$bootstrap = getenv('MPCMF_THREAD_BOOTSTRAP');
if ($bootstrap) {
    require_once $bootstrap;
} elseif (file_exists(APP_ROOT . '/loader.php')) {
    require_once APP_ROOT . '/loader.php';
} else {
    require_once APP_ROOT . '/vendor/autoload.php';
    require_once APP_ROOT . '/environment.php';
}

$fd = fopen('php://fd/3', 'r');
if ($fd === false) {
    fwrite(STDERR, "runner.php: fd 3 is not open\n");
    exit(2);
}

$message = @unserialize(stream_get_contents($fd));
fclose($fd);
if (!is_array($message)) {
    fwrite(STDERR, "runner.php: no task on fd 3\n");
    exit(2);
}
threadTask::run($message);
