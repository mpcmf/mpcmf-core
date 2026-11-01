<?php

namespace mpcmf\tests\system\net\reactCurl;

use KHR\React\Curl\Exception as curlException;
use mpcmf\system\net\curl\curlPool;
use mpcmf\system\net\curl\exception\cancelledException;
use mpcmf\system\net\curl\exception\deadlineException;
use mpcmf\system\net\curl\exception\optionException;
use mpcmf\system\net\curl\exception\queueFullException;

/**
 * Fixes and opt-in features of the own engine (mpcmf\system\net\curl\curlPool).
 * Skipped when running against khr/react-curl.
 */
class reactCurlReliabilityTest extends reactCurlTestCase
{
    protected function setUp(): void
    {
        $this->requireOwnEngine();
    }

    private function collect($client, array &$log)
    {
        $client->setDoneCallback(function ($result) use (&$log) {
            $log[] = 'ok ' . $result->getOptions()[CURLOPT_URL];
        });
        $client->setErrorCallback(function ($exception) use (&$log) {
            $log[] = 'err ' . get_class($exception) . ' ' . $exception->getCode();
        });
    }

    public function testDefaultClientOptions()
    {
        $client = $this->createClient($this->createLoop(), false);
        $this->assertSame([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_VERBOSE => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.155 Safari/537.36',
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
            CURLOPT_COOKIEJAR => '/tmp/mpcmf.cookie',
            CURLOPT_COOKIEFILE => '/tmp/mpcmf.cookie',
            CURLOPT_AUTOREFERER => true,
        ], $client->getCurlClient()->getCurlOption());
    }

    /**
     * P1: TypeError/ValueError/false from curl_setopt_array() rejects only that task.
     */
    public function testRejectedOptionRejectsOnlyItsTask()
    {
        $probe = curl_init();
        $this->assertFalse(@curl_setopt($probe, CURLOPT_HTTP_VERSION, 99), 'precondition: libcurl rejects HTTP version 99');

        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxRequest(5);
        $log = [];
        $this->collect($client, $log);
        $exceptions = [];
        $client->prepareTask(self::httpBase() . '/ok?1');
        $client->prepareTask(self::httpBase() . '/ok?2', 'GET', null, [CURLOPT_HTTPHEADER => 'X-A: 1'])->then(null, function ($e) use (&$exceptions) {
            $exceptions[] = $e;
        });
        $client->prepareTask(self::httpBase() . '/ok?3', 'GET', null, [CURLOPT_HTTP_VERSION => 99])->then(null, function ($e) use (&$exceptions) {
            $exceptions[] = $e;
        });
        $client->prepareTask(self::httpBase() . '/ok?4');
        $client->run();
        $this->runLoop($loop, function () use (&$log) {
            return count($log) >= 4;
        });
        sort($log);
        $this->assertSame([
            'err ' . optionException::class . ' 43',
            'err ' . optionException::class . ' 43',
            'ok ' . self::httpBase() . '/ok?1',
            'ok ' . self::httpBase() . '/ok?4',
        ], $log);
        $this->assertCount(2, $exceptions);
        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(curlException::class, $exception);
            $this->assertSame('client', $exception->result->getErrorType());
            $this->assertSame($exception->getMessage(), $exception->result->getError());
            $this->assertArrayHasKey(CURLOPT_URL, $exception->result->getOptions());
        }
        $messages = implode("\n", array_map(function ($e) {
            return $e->getMessage();
        }, $exceptions));
        $this->assertStringContainsString('CURLOPT_HTTPHEADER', $messages);
        $this->assertStringContainsString('failed to set CURLOPT_HTTP_VERSION (' . CURLOPT_HTTP_VERSION . ')', $messages);
        $this->assertSame(0, $client->getCurlClient()->getActiveCount());
        $this->assertSame(0, $client->getCurlClient()->getCountQuery());

        // the client keeps working
        $client->prepareTask(self::httpBase() . '/ok?5');
        $client->run();
        $this->runLoop($loop, function () use (&$log) {
            return count($log) >= 5;
        });
        $this->assertContains('ok ' . self::httpBase() . '/ok?5', $log);
    }

    public function testRejectionIsNeverSynchronousInsidePrepareTask()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxQueue(1);
        $client->setMaxRequest(1);
        $client->setAutoRun(true);
        $inside = false;
        $calledInside = [];
        $client->setErrorCallback(function ($exception) use (&$inside, &$calledInside) {
            $calledInside[] = $inside;
        });
        $inside = true;
        $client->prepareTask(self::httpBase() . '/ok', 'GET', null, [CURLOPT_HTTPHEADER => 'bad']);
        $client->prepareTask(self::httpBase() . '/ok');
        $client->prepareTask(self::httpBase() . '/ok');
        $client->prepareTask(self::httpBase() . '/ok');
        $inside = false;
        $this->runLoop($loop, function () use (&$calledInside) {
            return count($calledInside) >= 2;
        });
        $this->assertSame([false, false], $calledInside);
    }

    /**
     * P2: exceptions thrown in done/error callbacks are logged, control flow is unchanged.
     */
    public function testCallbackExceptionsAreLoggedNotPropagated()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $logged = [];
        $client->setErrorLogger(function ($message, array $context) use (&$logged) {
            $logged[] = $message . ' | ' . $context['exception'];
        });
        $errorCallbackCalls = 0;
        $client->setDoneCallback(function () {
            throw new \LogicException('bug in done');
        });
        $client->setErrorCallback(function () use (&$errorCallbackCalls) {
            $errorCallbackCalls++;
            throw new \LogicException('bug in error');
        });
        $chained = [];
        $client->prepareTask(self::httpBase() . '/ok')->then(function () use (&$chained) {
            $chained[] = 'own then() still called';
        });
        $client->prepareTask(self::httpBase() . '/code?c=500');
        $client->run();
        $this->runLoop($loop, function () use (&$logged) {
            return count($logged) >= 2;
        });
        sort($logged);
        $this->assertCount(2, $logged);
        $this->assertStringStartsWith('reactCurl: uncaught exception in done callback for ' . self::httpBase() . '/ok | LogicException: bug in done', $logged[0]);
        $this->assertStringStartsWith('reactCurl: uncaught exception in error callback for ' . self::httpBase() . '/code?c=500 | LogicException: bug in error', $logged[1]);
        $this->assertSame(1, $errorCallbackCalls, 'a throwing done callback does not trigger the error callback');
        $this->assertSame(['own then() still called'], $chained);
    }

    /**
     * P9: setSleep(0, ...) used to divide by zero.
     */
    public function testSleepAfterZeroRequestsMeansEveryRequest()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setSleep(0, 0.2, false);
        $client->setMaxRequest(2);
        $done = 0;
        for ($i = 0; $i < 4; $i++) {
            $client->prepareTask(self::httpBase() . '/ok')->then(function () use (&$done) {
                $done++;
            });
        }
        $client->run();
        $elapsed = $this->runLoop($loop, function () use (&$done) {
            return $done >= 4;
        });
        $this->assertGreaterThanOrEqual(0.19, $elapsed);
    }

    /**
     * P4: integer FOLLOWLOCATION from application options is normalized (PHP 8.5 rejects it).
     */
    public function testIntegerFollowLocationStillFollows()
    {
        $result = $this->runOne(self::httpBase() . '/redir', 'GET', null, [CURLOPT_FOLLOWLOCATION => 20])['fulfilled'];
        $this->assertSame('ok', $result->getBody());
        $this->assertSame(20, $result->getOptions()[CURLOPT_FOLLOWLOCATION], 'getOptions() shows what was given');
    }

    /**
     * P3: with react/event-loop >= 0.5 `$timer->cancel()` does not exist; the loop must end by itself.
     */
    public function testLoopEndsByItselfWhenWorkIsDone()
    {
        $code = '
            $loop = \React\EventLoop\Factory::create();
            $client = new \mpcmf\system\net\reactCurl($loop);
            $client->setSleep(1000000, 0, false);
            $n = 0;
            for ($i = 0; $i < 3; $i++) {
                $client->prepareTask(' . var_export(self::httpBase() . '/ok', true) . ')->then(function () use (&$n) { $n++; });
            }
            $client->prepareTask(' . var_export(self::httpBase() . '/code?c=404', true) . ')->then(null, function () use (&$n) { $n++; });
            $client->run();
            $loop->run();
            echo "settled=$n\n";
        ';
        list($exitCode, $output, $elapsed) = $this->runIsolated($code, 10);
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('settled=4', $output);
        $this->assertStringNotContainsString('Fatal', $output);
        $this->assertLessThan(5, $elapsed);
    }

    /**
     * P10 stays as before by default: prepareTask() while the client is idle needs run().
     */
    public function testIdleClientNeedsRunUnlessAutoRun()
    {
        foreach ([false, true] as $autoRun) {
            $loop = $this->createLoop();
            $client = $this->createClient($loop);
            $client->setAutoRun($autoRun);
            $settled = false;
            $loop->addTimer(0.01, function () use ($client, &$settled) {
                $client->prepareTask(self::httpBase() . '/ok')->then(function () use (&$settled) {
                    $settled = true;
                });
            });
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });
            $loop->run();
            $this->assertSame($autoRun, $settled, 'autoRun=' . var_export($autoRun, true));
        }
    }

    public function testTasksAddedWhileRunningStartWithoutRun()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $order = [];
        $client->prepareTask(self::httpBase() . '/slow?s=0.4')->then(function () use (&$order) {
            $order[] = 'slow';
        });
        $client->run();
        $loop->addTimer(0.05, function () use ($client, &$order) {
            $client->prepareTask(self::httpBase() . '/ok')->then(function () use (&$order) {
                $order[] = 'fast';
            });
        });
        $this->runLoop($loop, function () use (&$order) {
            return count($order) >= 2;
        });
        $this->assertSame(['fast', 'slow'], $order);
    }

    public static function pollModeProvider()
    {
        return [
            'adaptive' => [curlPool::POLL_ADAPTIVE, null, 0.008],
            'fixed 10ms' => [curlPool::POLL_ADAPTIVE, [0.01, 0.01], 0.03],
            'select' => [curlPool::POLL_SELECT, null, 0.006],
            'legacy' => [curlPool::POLL_LEGACY, null, 0.03],
        ];
    }

    /**
     * P6: sequential requests (C=1) - latency per request by poll mode.
     *
     * @dataProvider pollModeProvider
     */
    public function testPollModes($mode, $interval, $maxLatency)
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setPollMode($mode);
        if ($interval !== null) {
            $client->setPollInterval($interval[0], $interval[1]);
        }
        $done = 0;
        $fire = function () use (&$fire, &$done, $client) {
            $client->prepareTask(self::httpBase() . '/ok')->then(function () use (&$fire, &$done) {
                if (++$done < 40) {
                    $fire();
                }
            });
        };
        $fire();
        $client->run();
        $elapsed = $this->runLoop($loop, function () use (&$done) {
            return $done >= 40;
        });
        $this->assertLessThan($maxLatency, $elapsed / 40, sprintf('%s: %.1f ms per request', $mode, 1000 * $elapsed / 40));
    }

    public function testCancelIsNoOpByDefault()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $outcome = null;
        $promise = $client->prepareTask(self::httpBase() . '/slow?s=0.2');
        $promise->then(function () use (&$outcome) {
            $outcome = 'fulfilled';
        }, function () use (&$outcome) {
            $outcome = 'rejected';
        });
        $client->run();
        $loop->addTimer(0.05, function () use ($promise) {
            $promise->cancel();
        });
        $this->runLoop($loop, function () use (&$outcome) {
            return $outcome !== null;
        });
        $this->assertSame('fulfilled', $outcome);
    }

    public function testCancelQueuedAndRunningWhenEnabled()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setCancellable(true);
        $client->setMaxRequest(1);
        $log = [];
        $this->collect($client, $log);
        $running = $client->prepareTask(self::httpBase() . '/slow?s=2');
        $queued = $client->prepareTask(self::httpBase() . '/slow?s=2');
        $client->prepareTask(self::httpBase() . '/ok?after');
        $client->run();
        $loop->addTimer(0.05, function () use ($running, $queued) {
            $queued->cancel();
            $running->cancel();
        });
        $elapsed = $this->runLoop($loop, function () use (&$log) {
            return count($log) >= 3;
        });
        $this->assertLessThan(1, $elapsed);
        $this->assertSame([
            'err ' . cancelledException::class . ' 42',
            'err ' . cancelledException::class . ' 42',
            'ok ' . self::httpBase() . '/ok?after',
        ], $log);
        $this->assertSame(0, $client->getCurlClient()->getActiveCount());
    }

    public function testMaxQueue()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxQueue(3);
        $log = [];
        $this->collect($client, $log);
        for ($i = 0; $i < 5; $i++) {
            $client->prepareTask(self::httpBase() . '/ok?' . $i);
        }
        $client->run();
        $this->runLoop($loop, function () use (&$log) {
            return count($log) >= 5;
        });
        $this->assertSame(2, count(array_filter($log, function ($line) {
            return $line === 'err ' . queueFullException::class . ' 0';
        })));
    }

    public function testDeadlineIncludesQueueWait()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop, false);
        $client->setMaxRequest(2);
        $client->setSleep(1, 0.5, false);
        $client->setDeadline(0.3);
        $log = [];
        $this->collect($client, $log);
        $client->prepareTask(self::httpBase() . '/slow?s=1');
        $client->prepareTask(self::httpBase() . '/ok?first-batch');
        $client->prepareTask(self::httpBase() . '/ok?throttled');
        $client->run();
        $elapsed = $this->runLoop($loop, function () use (&$log) {
            return count($log) >= 3;
        });
        sort($log);
        $this->assertSame([
            'err KHR\\React\\Curl\\Exception 28',
            'err ' . deadlineException::class . ' 28',
            'ok ' . self::httpBase() . '/ok?first-batch',
        ], $log);
        $this->assertLessThan(0.6, $elapsed);
    }

    public function testCookieShareKeepsCookiesInMemory()
    {
        // handles of earlier tests write the shared jar when they are released
        gc_collect_cycles();
        @unlink('/tmp/mpcmf.cookie');
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setCookieShare(true);
        $echo = null;
        $client->prepareTask(self::httpBase() . '/setcookie?n=mem&v=1')->then(function () use ($client, &$echo) {
            $client->prepareTask(self::httpBase() . '/echo')->then(function ($result) use (&$echo) {
                $echo = json_decode($result->getBody(), true);
            });
        });
        $client->run();
        $this->runLoop($loop, function () use (&$echo) {
            return $echo !== null;
        });
        $this->assertSame('mem=1', $echo['cookie']);
        unset($client, $loop);
        gc_collect_cycles();
        $this->assertFileDoesNotExist('/tmp/mpcmf.cookie');
    }

    public function testNoDeprecationsOrWarningsFromCore()
    {
        $issues = [];
        $coreDir = dirname(__DIR__, 4) . '/src/';
        set_error_handler(function ($severity, $message, $file, $line) use (&$issues, $coreDir) {
            if (strpos($file, $coreDir) === 0 || strpos($file, '/src/compat/') !== false || strpos($file, '/net/curl/') !== false) {
                $issues[] = "{$message} at {$file}:{$line}";
            }

            return false;
        });
        try {
            $loop = $this->createLoop();
            $client = $this->createClient($loop);
            $client->getCurlClient()->enableHeaders();
            $settled = 0;
            $count = function () use (&$settled) {
                $settled++;
            };
            foreach (['/ok', '/code?c=404', '/redir', '/echo'] as $path) {
                $client->prepareTask(self::httpBase() . $path, 'POST', ['a' => 1])->then($count, $count);
            }
            $client->prepareTask(self::refusedUrl())->then($count, $count);
            $client->prepareTask(self::httpBase() . '/ok', 'GET', null, [CURLOPT_HTTPHEADER => 'bad'])->then($count, $count);
            $client->run();
            $this->runLoop($loop, function () use (&$settled) {
                return $settled >= 6;
            });
            unset($client, $loop);
            gc_collect_cycles();
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $issues);
    }
}
