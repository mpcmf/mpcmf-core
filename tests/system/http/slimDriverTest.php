<?php

namespace mpcmf\tests\system\http;

use mpcmf\system\http\slimDriver;
use PHPUnit\Framework\TestCase;

/**
 * slimDriver::handleErrors(): PHP 7 error levels on PHP 8 and deprecations logged once per process
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class slimDriverTest extends TestCase
{
    private $errorReporting;
    private $errorLog;
    private $logFile;

    protected function setUp(): void
    {
        $this->errorReporting = error_reporting();
        $this->logFile = tempnam(sys_get_temp_dir(), 'slimdriver');
        $this->errorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
        ini_set('error_log', $this->errorLog);
        @unlink($this->logFile);
    }

    public static function php7NoticeProvider()
    {
        return [
            'undefined variable' => ['Undefined variable $x'],
            'undefined array key' => ['Undefined array key "k"'],
            'undefined property' => ['Undefined property: stdClass::$p'],
            'read property on null' => ['Attempt to read property "p" on null'],
            'array offset on null (8.3)' => ['Trying to access array offset on null'],
            'array offset on null (8.1)' => ['Trying to access array offset on value of type null'],
            'array to string' => ['Array to string conversion'],
            'uninitialized string offset' => ['Uninitialized string offset 10'],
            'string offset cast' => ['String offset cast occurred'],
            'resource offset' => ['Resource ID#5 used as offset, casting to integer (5)'],
            'leading numeric string offset' => ['Illegal string offset "1x"'],
            'object to int' => ['Object of class stdClass could not be converted to int'],
            'object to float' => ['Object of class a\b\c could not be converted to float'],
            'compact' => ['compact(): Undefined variable $nope'],
            'unserialize (8.3)' => ['unserialize(): Error at offset 0 of 5 bytes'],
        ];
    }

    /**
     * @dataProvider php7NoticeProvider
     */
    public function testPhp7NoticesRaisedAsWarningsAreHandledAsNotices($message)
    {
        error_reporting(E_ALL & ~E_NOTICE);
        $this->assertNull(slimDriver::handleErrors(E_WARNING, $message, __FILE__, __LINE__));

        error_reporting(E_ALL);
        try {
            slimDriver::handleErrors(E_WARNING, $message, __FILE__, __LINE__);
            $this->fail('ErrorException expected');
        } catch (\ErrorException $exception) {
            // Slim passes the level as the exception code
            $this->assertSame(E_NOTICE, $exception->getCode());
        }
    }

    public static function php7WarningProvider()
    {
        return [
            'non-numeric value' => ['A non-numeric value encountered'],
            'foreach' => ['foreach() argument must be of type array|object, null given'],
            'unserialize extra data (new in 8.3)' => ['unserialize(): Extra data starting at offset 4 of 6 bytes'],
            'object to int, not at the start' => ['x: Object of class stdClass could not be converted to int'],
        ];
    }

    /**
     * @dataProvider php7WarningProvider
     */
    public function testOtherWarningsStayWarnings($message)
    {
        error_reporting(E_ALL & ~E_NOTICE);
        try {
            slimDriver::handleErrors(E_WARNING, $message, __FILE__, __LINE__);
            $this->fail('ErrorException expected');
        } catch (\ErrorException $exception) {
            $this->assertSame(E_WARNING, $exception->getCode());
        }
    }

    public function testEachDeprecationIsLoggedOncePerProcess()
    {
        error_reporting(E_ALL);
        $line = __LINE__;
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(slimDriver::handleErrors(E_DEPRECATED, 'first deprecation ' . $line, __FILE__, $line));
            $this->assertTrue(slimDriver::handleErrors(E_USER_DEPRECATED, 'second deprecation ' . $line, __FILE__, $line + 1));
        }
        $log = file_get_contents($this->logFile);
        $this->assertSame(1, substr_count($log, 'Deprecated: first deprecation ' . $line), $log);
        $this->assertSame(1, substr_count($log, 'Deprecated: second deprecation ' . $line), $log);
    }

    public function testDeprecationsAreNotLoggedWhenNotReported()
    {
        error_reporting(E_ALL & ~E_DEPRECATED);
        $this->assertTrue(slimDriver::handleErrors(E_DEPRECATED, 'hidden deprecation', __FILE__, __LINE__));
        $this->assertSame('', file_get_contents($this->logFile));
    }
}
