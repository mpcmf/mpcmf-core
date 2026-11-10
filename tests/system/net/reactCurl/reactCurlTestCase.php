<?php

namespace mpcmf\tests\system\net\reactCurl;

use mpcmf\system\net\reactCurl;
use PHPUnit\Framework\TestCase;

/**
 * Base for reactCurl tests: local fixtures and loop helpers
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
abstract class reactCurlTestCase extends TestCase
{
    /**
     * Fixture processes
     *
     * @var array|null
     */
    private static $processes;

    protected static function httpBase()
    {
        return 'http://127.0.0.1:' . self::port('MPCMF_TEST_HTTP_PORT', 18080);
    }

    /**
     * @param int $shift 0 - accept+close, 1 - never answers, 2 - truncated body, 3 - see delayUrl()
     */
    protected static function rawUrl($shift)
    {
        return 'http://127.0.0.1:' . (self::port('MPCMF_TEST_RAW_PORT', 19001) + $shift) . '/';
    }

    /**
     * URL answered after $seconds by the non-blocking fixture
     */
    protected static function delayUrl($seconds, $query = '')
    {
        return self::rawUrl(3) . '?s=' . $seconds . ($query === '' ? '' : '&' . $query);
    }

    protected static function refusedUrl()
    {
        return 'http://127.0.0.1:' . self::port('MPCMF_TEST_REFUSED_PORT', 18099) . '/';
    }

    private static function port($env, $default)
    {
        return (int)(getenv($env) ?: $default);
    }

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required');
        }
        self::startFixtures();
    }

    private static function startFixtures()
    {
        if (self::$processes !== null) {
            return;
        }
        self::$processes = [];
        $dir = __DIR__ . '/fixtures';
        $httpPort = self::port('MPCMF_TEST_HTTP_PORT', 18080);
        $rawPort = self::port('MPCMF_TEST_RAW_PORT', 19001);
        if (!self::isListening($httpPort)) {
            self::$processes[] = self::spawn([PHP_BINARY, '-S', "127.0.0.1:{$httpPort}", "{$dir}/router.php"], ['PHP_CLI_SERVER_WORKERS' => '16']);
        }
        if (!self::isListening($rawPort)) {
            self::$processes[] = self::spawn([PHP_BINARY, "{$dir}/raw.php", (string)$rawPort], []);
        }
        $deadline = microtime(true) + 10;
        while (!(self::isListening($httpPort) && self::isListening($rawPort))) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('test fixtures did not start');
            }
            usleep(20000);
        }
        register_shutdown_function(function () {
            foreach (self::$processes as $process) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        });
    }

    private static function spawn(array $command, array $env)
    {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open($command, $spec, $pipes, null, $env + getenv());
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start ' . implode(' ', $command));
        }

        return $process;
    }

    private static function isListening($port)
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * Check if the own engine is tested, not khr/react-curl
     */
    protected static function isOwnEngine()
    {
        return class_exists('mpcmf\\system\\net\\curl\\curlPool');
    }

    protected function requireOwnEngine()
    {
        if (!self::isOwnEngine()) {
            $this->markTestSkipped('fix of the own engine, not present in khr/react-curl');
        }
    }

    protected function createLoop()
    {
        return \React\EventLoop\Factory::create();
    }

    /**
     * @return reactCurl
     */
    protected function createClient($loop, $noThrottle = true)
    {
        $client = new reactCurl($loop);
        if ($noThrottle) {
            $client->setSleep(1000000, 0, false);
        }

        return $client;
    }

    /**
     * Run the loop until $isDone() returns true or the limit is hit
     */
    protected function runLoop($loop, callable $isDone, $limit = 10.0)
    {
        $started = microtime(true);
        $hitLimit = false;
        $check = $loop->addPeriodicTimer(0.005, function () use ($loop, $isDone, $started, $limit, &$hitLimit, &$check) {
            if ($isDone() || ($hitLimit = microtime(true) - $started > $limit)) {
                $loop->cancelTimer($check);
                $loop->stop();
            }
        });
        $loop->run();
        $elapsed = microtime(true) - $started;
        $this->assertFalse($hitLimit, "work did not finish within {$limit}s");

        return $elapsed;
    }

    /**
     * Run PHP code in a separate process
     */
    protected function runIsolated($code, $timeout = 10.0)
    {
        $autoload = getenv('MPCMF_TEST_AUTOLOAD') ?: dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = tempnam(sys_get_temp_dir(), 'rc');
        file_put_contents($script, "<?php\nerror_reporting(E_ALL);\nrequire " . var_export($autoload, true) . ";\n" . $code);
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script], $spec, $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $started = microtime(true);
        while (true) {
            $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started > $timeout) {
                proc_terminate($process, 9);
                $status = ['exitcode' => -9];
                break;
            }
            usleep(10000);
        }
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);
        unlink($script);

        return [$status['exitcode'], $output, microtime(true) - $started];
    }

    /**
     * Run one task through a fresh client
     */
    protected function runOne($url, $method = 'GET', $params = null, array $options = [])
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $outcome = [];
        $client->prepareTask($url, $method, $params, $options)->then(function ($result) use (&$outcome) {
            $outcome['fulfilled'] = $result;
        }, function ($exception) use (&$outcome) {
            $outcome['rejected'] = $exception;
        });
        $client->run();
        $this->runLoop($loop, function () use (&$outcome) {
            return count($outcome) > 0;
        }, 15);
        $this->assertCount(1, $outcome, 'task must settle exactly once');

        return $outcome;
    }
}
