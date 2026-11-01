<?php
/**
 * PHPUnit bootstrap.
 *
 * MPCMF_TEST_AUTOLOAD may point to an alternative autoloader, e.g. to run the reactCurl
 * characterization tests against another implementation or another vendor directory.
 */
error_reporting(E_ALL);

$autoload = getenv('MPCMF_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;

require_once __DIR__ . '/system/net/reactCurl/reactCurlTestCase.php';
