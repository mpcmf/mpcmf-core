<?php
/**
 * PHPUnit bootstrap, MPCMF_TEST_AUTOLOAD overrides the autoloader
 */
error_reporting(E_ALL);

$autoload = getenv('MPCMF_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;

require_once __DIR__ . '/system/net/reactCurl/reactCurlTestCase.php';
