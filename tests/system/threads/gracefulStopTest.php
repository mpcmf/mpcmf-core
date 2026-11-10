<?php

namespace mpcmf\tests\system\threads;

use mpcmf\system\helper\service\signalHandler;
use mpcmf\system\threads\gracefulStop;
use PHPUnit\Framework\TestCase;

/**
 * Repeated stop signals and critical section transitions of gracefulStop, in forked children
 */
class gracefulStopTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required');
        }
    }

    /**
     * @return array [signals sent 0.2 s apart, setForceOnRepeat(), expected status]
     */
    public static function enableProvider()
    {
        return [
            'repeated SIGTERM is ignored' => [[SIGTERM, SIGTERM, SIGTERM], null, 'exit 0'],
            'repeated SIGINT exits' => [[SIGINT, SIGINT], null, 'exit 130'],
            'SIGINT after SIGTERM exits' => [[SIGTERM, SIGINT], null, 'exit 130'],
            'force: repeated SIGTERM exits' => [[SIGTERM, SIGTERM], true, 'exit 143'],
            'no force: repeated SIGINT is ignored' => [[SIGINT, SIGINT], false, 'exit 0'],
        ];
    }

    /**
     * @dataProvider enableProvider
     */
    public function testRepeatedSignalWithEnable(array $signals, $force, $expected)
    {
        $status = $this->runChild(function () use ($force) {
            gracefulStop::enable();
            if ($force !== null) {
                gracefulStop::setForceOnRepeat($force);
            }
            while (!gracefulStop::isRequested()) {
                usleep(1000);
            }
            // the unit of work after the stop request, repeated signals arrive here
            self::work(0.8);
            exit(0);
        }, $signals);

        $this->assertSame($expected, $status);
    }

    /**
     * @return array [signals sent 0.2 s apart, setForceOnRepeat(), default handler, expected status]
     */
    public static function criticalProvider()
    {
        return [
            'kernel: repeated SIGTERM waits for the section' => [[SIGTERM, SIGTERM, SIGTERM], null, false, 'signal 15'],
            'default: repeated SIGTERM waits for the section' => [[SIGTERM, SIGTERM], null, true, 'exit 143'],
            'kernel: repeated SIGINT exits' => [[SIGINT, SIGINT], null, false, 'exit 130'],
            'force: repeated SIGTERM exits' => [[SIGTERM, SIGTERM], true, true, 'exit 143 early'],
        ];
    }

    /**
     * @dataProvider criticalProvider
     */
    public function testRepeatedSignalInCriticalSection(array $signals, $force, $defaultHandler, $expected)
    {
        $status = $this->runChild(function () use ($force, $defaultHandler) {
            pcntl_async_signals(true);
            if ($defaultHandler) {
                signalHandler::getInstance()->setDefaultHandler(SIGTERM, function ($signal) {
                    exit(128 + $signal);
                });
            }
            if ($force !== null) {
                gracefulStop::setForceOnRepeat($force);
            }
            gracefulStop::critical(function () {
                self::work(1.0);
            });
            exit(0);
        }, $signals, $timing);

        if ($expected === 'exit 143 early') {
            $this->assertSame('exit 143', $status);
            $this->assertLessThan(0.5, $timing);

            return;
        }
        $this->assertSame($expected, $status);
        if ($expected !== 'exit 130') {
            // the section ends 0.7 s after the first signal
            $this->assertGreaterThan(0.6, $timing, 'the critical section is finished before the stop');
        }
    }

    /**
     * A stop signal at a random moment into a loop of short critical sections terminates the process every time
     *
     * @testWith [false]
     *           [true]
     */
    public function testNoSignalLostOnCriticalSectionTransitions($defaultHandler)
    {
        $lost = 0;
        $trials = 150;
        for ($i = 0; $i < $trials; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                pcntl_async_signals(true);
                if ($defaultHandler) {
                    signalHandler::getInstance()->setDefaultHandler(SIGTERM, function ($signal) {
                        exit(128 + $signal);
                    });
                }
                for (;;) {
                    gracefulStop::critical(function () {
                    });
                }
            }
            usleep(random_int(2000, 8000));
            posix_kill($pid, SIGTERM);
            $start = microtime(true);
            while (($done = pcntl_waitpid($pid, $status, WNOHANG)) === 0 && microtime(true) - $start < 0.5) {
                usleep(1000);
            }
            if ($done === 0) {
                $lost++;
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }

        $this->assertSame(0, $lost, "lost {$lost} of {$trials} signals");
    }

    /**
     * Fork, run the callback in the child, send the signals 0.2 s apart starting 0.3 s after the fork
     *
     * @param callable   $child
     * @param int[]      $signals
     * @param float|null $timing seconds from the first signal to the exit
     *
     * @return string "exit N" or "signal N"
     */
    private function runChild(callable $child, array $signals, &$timing = null)
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $child();
            } finally {
                exit(99);
            }
        }
        usleep(300000);
        $first = microtime(true);
        foreach ($signals as $index => $signal) {
            if ($index > 0) {
                usleep(200000);
            }
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                $timing = microtime(true) - $first;

                return self::describe($status);
            }
            posix_kill($pid, $signal);
        }
        pcntl_waitpid($pid, $status);
        $timing = microtime(true) - $first;

        return self::describe($status);
    }

    private static function describe($status)
    {
        return pcntl_wifexited($status) ? 'exit ' . pcntl_wexitstatus($status) : 'signal ' . pcntl_wtermsig($status);
    }

    private static function work($seconds)
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            usleep(1000);
        }
    }
}
