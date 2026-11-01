<?php

namespace mpcmf\tests\system\net\reactCurl;

use KHR\React\Curl\Exception as curlException;
use KHR\React\Curl\Result as curlResult;

/**
 * Characterization of the observable reactCurl contract (as of khr/react-curl@95626ed +
 * khr/php-mcurl-client 3.1.0). Must pass unchanged for any replacement implementation.
 */
class reactCurlContractTest extends reactCurlTestCase
{
    public function testSuccessfulGetResolvesWithResult()
    {
        $outcome = $this->runOne(self::httpBase() . '/ok');
        $this->assertArrayHasKey('fulfilled', $outcome);
        $result = $outcome['fulfilled'];
        $this->assertInstanceOf(curlResult::class, $result);
        $this->assertInstanceOf(\MCurl\Result::class, $result);
        $this->assertSame('ok', $result->getBody());
        $this->assertSame('ok', (string)$result);
        $this->assertSame(200, $result->getHttpCode());
        $this->assertFalse($result->hasError());
        $this->assertNull($result->getErrorType());
        $this->assertNull($result->getError());
        $this->assertNull($result->getErrorCode());
        $this->assertNull($result->getId());
        $this->assertSame([], $result->getParams());
        $this->assertNull($result->getHeaders(), 'headers are not collected unless enableHeaders()');
        $this->assertSame([CURLOPT_CUSTOMREQUEST => 'GET', CURLOPT_URL => self::httpBase() . '/ok'], $result->getOptions());
        $info = $result->getInfo();
        $this->assertIsArray($info);
        $this->assertSame(200, $info['http_code']);
        $this->assertSame(self::httpBase() . '/ok', $info['url']);
        $this->assertSame('ok', $result->body, 'magic __get maps to getters');
        $this->assertNull($result->noSuchThing);
    }

    public function testHandleIsAliveInsideCallbacks()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $seen = [];
        $client->setDoneCallback(function ($result) use (&$seen) {
            $seen[] = curl_getinfo($result->getCh(), CURLINFO_HTTP_CODE);
            $seen[] = curl_getinfo($result->getCh(), CURLINFO_EFFECTIVE_URL);
        });
        $client->setErrorCallback(function ($exception) use (&$seen) {
            $seen[] = curl_getinfo($exception->result->getCh(), CURLINFO_HTTP_CODE);
        });
        $client->prepareTask(self::httpBase() . '/ok');
        $client->prepareTask(self::httpBase() . '/code?c=404');
        $client->run();
        $this->runLoop($loop, function () use (&$seen) {
            return count($seen) >= 3;
        });
        $this->assertCount(3, $seen);
        $this->assertContains(200, $seen);
        $this->assertContains(404, $seen);
        $this->assertContains(self::httpBase() . '/ok', $seen);
    }

    public static function requestBuildingProvider()
    {
        return [
            'get with params array' => ['/echo?x=1', 'get', ['a' => 'b c', 'n' => [1, 2]], 'GET', '/echo?x=1&a=b+c&n%5B0%5D=1&n%5B1%5D=2', '', 2],
            'get with params string' => ['/echo', 'GET', 'a=1&b', 'GET', '/echo?a=1&b', '', 2],
            'get with empty params' => ['/echo', 'GET', [], 'GET', '/echo', '', 2],
            'post array' => ['/echo', 'post', ['a' => 1, 'b' => 'z'], 'POST', '/echo', 'a=1&b=z', 4],
            'post string' => ['/echo', 'POST', 'raw=1&y', 'POST', '/echo', 'raw=1&y', 4],
            'post null' => ['/echo', 'POST', null, 'POST', '/echo', '', 4],
            'put' => ['/echo', 'PUT', ['k' => 'v'], 'PUT', '/echo', 'k=v', 3],
            'delete without params' => ['/echo', 'delete', null, 'DELETE', '/echo', '', 3],
            'patch' => ['/echo?q=1', 'patch', 'x=y', 'PATCH', '/echo?q=1', 'x=y', 3],
        ];
    }

    /**
     * @dataProvider requestBuildingProvider
     */
    public function testRequestBuilding($path, $method, $params, $expectMethod, $expectPath, $expectBody, $optionCount)
    {
        $outcome = $this->runOne('  ' . self::httpBase() . $path . "\n", $method, $params);
        $this->assertArrayHasKey('fulfilled', $outcome);
        $result = $outcome['fulfilled'];
        $echo = json_decode($result->getBody(), true);
        $this->assertSame($expectMethod, $echo['m']);
        $this->assertSame($expectBody, $echo['p']);
        $options = $result->getOptions();
        $this->assertSame(self::httpBase() . $expectPath, $options[CURLOPT_URL], 'url is trimmed and query appended');
        $this->assertSame($expectMethod, $options[CURLOPT_CUSTOMREQUEST]);
        $this->assertCount($optionCount, $options);
        if ($expectMethod === 'POST') {
            $this->assertSame([CURLOPT_CUSTOMREQUEST, CURLOPT_POSTFIELDS, CURLOPT_POST, CURLOPT_URL], array_keys($options));
            $this->assertSame(1, $options[CURLOPT_POST]);
            $this->assertSame($expectBody, $options[CURLOPT_POSTFIELDS]);
            $this->assertSame('application/x-www-form-urlencoded', $echo['ctype']);
        } elseif ($expectMethod !== 'GET') {
            $this->assertSame([CURLOPT_CUSTOMREQUEST, CURLOPT_POSTFIELDS, CURLOPT_URL], array_keys($options));
        }
    }

    public function testUserOptionsKeepTheirPositionAndAppearInGetOptions()
    {
        $outcome = $this->runOne(self::httpBase() . '/echo', 'GET', null, [CURLOPT_PRIVATE => 'job-42', CURLOPT_URL => 'ignored', CURLOPT_CUSTOMREQUEST => 'PUT']);
        $result = $outcome['fulfilled'];
        $this->assertSame([CURLOPT_PRIVATE => 'job-42', CURLOPT_URL => self::httpBase() . '/echo', CURLOPT_CUSTOMREQUEST => 'GET'], $result->getOptions());
        $this->assertSame('job-42', curl_getinfo($result->getCh(), CURLINFO_PRIVATE));
        $this->assertSame('GET', json_decode($result->getBody(), true)['m']);
    }

    public function testDefaultOptionsOnTheWire()
    {
        @unlink('/tmp/mpcmf.cookie');
        $result = $this->runOne(self::httpBase() . '/echo')['fulfilled'];
        $echo = json_decode($result->getBody(), true);
        $this->assertSame('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.155 Safari/537.36', $echo['ua']);
        $this->assertSame('HTTP/1.0', $echo['proto']);
        unset($result);
        gc_collect_cycles();
        $this->assertFileExists('/tmp/mpcmf.cookie', 'shared cookie jar is written when the handle is released');
    }

    public function testCookiesAreSharedThroughTheJarFile()
    {
        @unlink('/tmp/mpcmf.cookie');
        $this->runOne(self::httpBase() . '/setcookie?n=sid&v=s' . getmypid());
        gc_collect_cycles();
        $echo = json_decode($this->runOne(self::httpBase() . '/echo')['fulfilled']->getBody(), true);
        $this->assertSame('sid=s' . getmypid(), $echo['cookie'], 'a new client (another process) reads the shared jar');
        @unlink('/tmp/mpcmf.cookie');
    }

    public function testRedirectsAreFollowedWithAutoReferer()
    {
        $result = $this->runOne(self::httpBase() . '/redir')['fulfilled'];
        $this->assertSame('ok', $result->getBody());
        $this->assertSame(200, $result->getHttpCode());
        $this->assertSame(self::httpBase() . '/redir', $result->getOptions()[CURLOPT_URL]);
        $this->assertSame(self::httpBase() . '/ok?from=redir', $result->getInfo()['url']);
        $this->assertSame(1, $result->getInfo()['redirect_count']);
    }

    public function testRedirectLoopStopsAfterTwentyRedirects()
    {
        $exception = $this->runOne(self::httpBase() . '/loop')['rejected'];
        $this->assertInstanceOf(curlException::class, $exception);
        $this->assertSame(47, $exception->getCode());
        $this->assertSame('Maximum (20) redirects followed', $exception->getMessage());
        $this->assertSame('network', $exception->result->getErrorType());
        $this->assertSame(302, $exception->result->getHttpCode());
    }

    /**
     * P11: POST redirected with 301/302/303 stays POST (CUSTOMREQUEST) but loses its body.
     */
    public function testPostRedirectQuirk()
    {
        foreach ([301, 302, 303] as $code) {
            $result = $this->runOne(self::httpBase() . "/redir-echo?c={$code}", 'POST', ['a' => 1])['fulfilled'];
            $echo = json_decode($result->getBody(), true);
            $this->assertSame('POST', $echo['m'], "after {$code}");
            $this->assertSame('', $echo['p'], "after {$code}");
        }
    }

    public static function httpErrorProvider()
    {
        return [[400], [404], [500], [503]];
    }

    /**
     * @dataProvider httpErrorProvider
     */
    public function testHttpErrorsReject($code)
    {
        $exception = $this->runOne(self::httpBase() . "/code?c={$code}")['rejected'];
        $this->assertInstanceOf(curlException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame("http error {$code}", $exception->getMessage());
        $this->assertSame($code, $exception->getCode());
        $this->assertInstanceOf(curlResult::class, $exception->result);
        $this->assertSame('http', $exception->result->getErrorType());
        $this->assertTrue($exception->result->hasError());
        $this->assertTrue($exception->result->hasError('http'));
        $this->assertFalse($exception->result->hasError('network'));
        $this->assertSame("body-of-{$code}", $exception->result->getBody());
        $this->assertSame($code, $exception->result->getHttpCode());
        $this->assertIsInt($exception->getLine());
        $this->assertIsString($exception->getFile());
    }

    public function testRedirectStatusWithoutLocationIsNotAnError()
    {
        $result = $this->runOne(self::httpBase() . '/code?c=302')['fulfilled'];
        $this->assertSame(302, $result->getHttpCode());
    }

    public static function networkErrorProvider()
    {
        return [
            'refused' => ['refused', [7], 'GET', [], 0],
            'empty reply' => ['raw0', [52, 56], 'GET', [], 0],
            'truncated body' => ['raw2', [18, 56], 'GET', [], 200],
            'timeout on slow server' => ['/slow?s=3', [28], 'GET', [CURLOPT_TIMEOUT => 1], 0],
            'timeout on silent server' => ['raw1', [28], 'GET', [CURLOPT_TIMEOUT => 1], 0],
            'nxdomain' => ['http://no-such-host.invalid/', [6], 'GET', [], 0],
            'unsupported scheme' => ['foo://x/', [1], 'GET', [], 0],
        ];
    }

    /**
     * Messages come from libcurl and differ between libcurl versions, codes (curl_errno) are stable.
     *
     * @dataProvider networkErrorProvider
     */
    public function testNetworkErrorsReject($target, array $codes, $method, array $options, $httpCode)
    {
        $map = ['refused' => self::refusedUrl(), 'raw0' => self::rawUrl(0), 'raw1' => self::rawUrl(1), 'raw2' => self::rawUrl(2)];
        $url = $map[$target] ?? (strpos($target, '/') === 0 ? self::httpBase() . $target : $target);
        $exception = $this->runOne($url, $method, null, $options)['rejected'];
        $this->assertInstanceOf(curlException::class, $exception);
        $this->assertContains($exception->getCode(), $codes);
        $this->assertNotSame('', $exception->getMessage());
        $this->assertSame($exception->result->getError(), $exception->getMessage());
        $this->assertSame($exception->result->getErrorCode(), $exception->getCode());
        $this->assertSame(curl_error($exception->result->getCh()), $exception->getMessage());
        $this->assertSame('network', $exception->result->getErrorType());
        $this->assertTrue($exception->result->hasError('network'));
        $this->assertSame($httpCode, $exception->result->getHttpCode());
    }

    public function testTimeoutMessageFormat()
    {
        $exception = $this->runOne(self::rawUrl(1), 'GET', null, [CURLOPT_TIMEOUT => 1])['rejected'];
        $this->assertMatchesRegularExpression('/^Operation timed out after \d+ milliseconds with 0 bytes received$/', $exception->getMessage());
    }

    public function testCallbacksAreBoundAtPrepareTime()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $calls = [];
        $client->setDoneCallback(function ($result) use (&$calls) {
            $calls[] = 'done1:' . $result->getHttpCode();
        });
        $client->setErrorCallback(function ($exception) use (&$calls) {
            $calls[] = 'error1:' . $exception->getCode();
        });
        $first = $client->prepareTask(self::httpBase() . '/ok');
        $client->prepareTask(self::httpBase() . '/code?c=500');
        $client->setDoneCallback(function () use (&$calls) {
            $calls[] = 'done2';
        });
        $client->setErrorCallback(null);
        $client->prepareTask(self::httpBase() . '/ok?2');
        $client->prepareTask(self::httpBase() . '/code?c=404');
        $chained = null;
        $first->then(function ($result) use (&$chained) {
            $chained = $result->getBody();
        });
        $client->run();
        $this->runLoop($loop, function () use (&$calls, &$chained) {
            return count($calls) >= 3 && $chained !== null;
        });
        sort($calls);
        $this->assertSame(['done1:200', 'done2', 'error1:500'], $calls);
        $this->assertSame('ok', $chained);
    }

    public function testDoneCallbackCanQueueMoreTasks()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxRequest(3);
        $done = 0;
        $client->setDoneCallback(function () use ($client, &$done) {
            $done++;
            if ($done < 10) {
                $client->prepareTask(self::httpBase() . '/ok?n=' . $done);
            }
        });
        $client->prepareTask(self::httpBase() . '/ok');
        $client->run();
        $this->runLoop($loop, function () use (&$done) {
            return $done >= 10;
        });
        $this->assertSame(10, $done);
    }

    public function testMaxRequestLimitsParallelTransfers()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxRequest(2);
        $n = 0;
        for ($i = 0; $i < 6; $i++) {
            $client->prepareTask(self::httpBase() . '/slow?s=0.3')->then(function () use (&$n) {
                $n++;
            });
        }
        $client->run();
        $elapsed = $this->runLoop($loop, function () use (&$n) {
            return $n >= 6;
        });
        $this->assertSame(6, $n);
        $this->assertGreaterThanOrEqual(0.88, $elapsed, '3 waves of 2 x 0.3s');
        $this->assertLessThan(1.5, $elapsed);
    }

    public function testManyParallelRequests()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxRequest(20);
        $ok = 0;
        $urls = [];
        for ($i = 0; $i < 60; $i++) {
            $client->prepareTask(self::httpBase() . '/echo', 'GET', ['i' => $i])->then(function ($result) use (&$ok, &$urls) {
                $ok++;
                $urls[] = json_decode($result->getBody(), true)['q']['i'];
            });
        }
        $client->run();
        $this->runLoop($loop, function () use (&$ok) {
            return $ok >= 60;
        });
        $this->assertSame(60, $ok);
        sort($urls);
        $this->assertSame(array_map('strval', range(0, 59)), $urls);
    }

    public static function proxyProvider()
    {
        return [
            'http' => [['proxy_type' => 'http', 'initial' => '1.2.3.4:3128'], [CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_PROXY => '1.2.3.4:3128']],
            'https' => [['proxy_type' => 'https', 'initial' => '1.2.3.4:3128'], [CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_PROXY => '1.2.3.4:3128']],
            'socks4' => [['proxy_type' => 'socks4', 'initial' => '1.2.3.4:1080'], [CURLOPT_PROXYTYPE => CURLPROXY_SOCKS4, CURLOPT_PROXY => '1.2.3.4:1080']],
            'socks5 basic auth' => [['proxy_type' => 'socks5', 'initial' => 'u:p@1.2.3.4:1080', 'auth_type' => 'basic'], [CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5, CURLOPT_PROXY => 'u:p@1.2.3.4:1080', CURLOPT_PROXYAUTH => CURLAUTH_BASIC]],
            'http ntlm auth' => [['proxy_type' => 'http', 'initial' => 'u:p@1.2.3.4:3128', 'auth_type' => 'ntlm'], [CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_PROXY => 'u:p@1.2.3.4:3128', CURLOPT_PROXYAUTH => CURLAUTH_NTLM]],
            'unknown type' => [['proxy_type' => 'ftp', 'initial' => '1.2.3.4:21'], [CURLOPT_PROXY => '1.2.3.4:21']],
        ];
    }

    /**
     * @dataProvider proxyProvider
     */
    public function testGetProxyOptions(array $proxy, array $expected)
    {
        $client = $this->createClient($this->createLoop());
        $this->assertSame($expected, $client->getProxyOptions($proxy));
    }

    public function testClientLevelOptionsApplyToEveryRequestButNotToGetOptions()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->getCurlClient()->setCurlOption([CURLOPT_USERAGENT => 'client-level-ua', CURLOPT_HTTPHEADER => ['X-A: 1']]);
        $echo = null;
        $taskUa = null;
        $options = null;
        $client->prepareTask(self::httpBase() . '/echo')->then(function ($result) use (&$echo, &$options) {
            $echo = json_decode($result->getBody(), true);
            $options = $result->getOptions();
        });
        $client->prepareTask(self::httpBase() . '/echo', 'GET', null, [CURLOPT_USERAGENT => 'task-ua'])->then(function ($result) use (&$taskUa) {
            $taskUa = json_decode($result->getBody(), true)['ua'];
        });
        $client->run();
        $this->runLoop($loop, function () use (&$echo, &$taskUa) {
            return $echo !== null && $taskUa !== null;
        });
        $this->assertSame('client-level-ua', $echo['ua']);
        $this->assertSame('task-ua', $taskUa, 'task options win over client options');
        $this->assertArrayNotHasKey(CURLOPT_USERAGENT, $options);
    }

    public function testEnableHeadersAndStreamResult()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->getCurlClient()->enableHeaders();
        $client->getCurlClient()->setStreamResult('php://temp');
        $result = null;
        $client->prepareTask(self::httpBase() . '/echo')->then(function ($r) use (&$result) {
            $result = $r;
        });
        $client->run();
        $this->runLoop($loop, function () use (&$result) {
            return $result !== null;
        });
        $headers = $result->getHeaders();
        $this->assertSame('HTTP/1.0 200 OK', $headers['result']);
        $this->assertSame('echo', $headers['x-fixture']);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame([CURLOPT_CUSTOMREQUEST, CURLOPT_URL], array_keys($result->getOptions()), 'streams are hidden from getOptions');
        // RETURNTRANSFER (client option, applied after CURLOPT_FILE) wins: the stream stays empty
        $this->assertSame('', $result->getBody());
        $this->assertIsResource($result->getBodyStream());
        $this->assertSame('', stream_get_contents($result->getBodyStream()));
        $this->assertNull($result->getJson());
    }

    public function testResultsAreReleased()
    {
        $loop = $this->createLoop();
        $client = $this->createClient($loop);
        $client->setMaxRequest(20);
        $settled = 0;
        $count = function () use (&$settled) {
            $settled++;
        };
        $wave = function ($n) use ($client, $loop, $count, &$settled) {
            $target = $settled + $n;
            for ($i = 0; $i < $n; $i++) {
                $client->prepareTask(self::httpBase() . '/ok')->then($count);
            }
            $client->run();
            $this->runLoop($loop, function () use (&$settled, $target) {
                return $settled >= $target;
            });
        };
        $wave(30);
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($round = 0; $round < 3; $round++) {
            $wave(100);
        }
        gc_collect_cycles();
        $this->assertSame(330, $settled);
        $this->assertLessThan(200 * 1024, memory_get_usage() - $before);
    }

    public function testJsonHelper()
    {
        $result = $this->runOne(self::httpBase() . '/echo')['fulfilled'];
        $this->assertSame('GET', $result->getJson(true)['m']);
        $this->assertSame('GET', $result->getJson()->m);
        $this->assertNull($this->runOne(self::httpBase() . '/ok')['fulfilled']->getJson());
    }

    public function testReactCurlCompatClass()
    {
        $loop = $this->createLoop();
        $curl = new \KHR\React\Curl\Curl($loop);
        $this->assertSame($loop, $curl->loop);
        $bodies = [];
        $curl->get(self::httpBase() . '/ok')->then(function ($result) use (&$bodies) {
            $bodies[] = $result->getBody();
        });
        $curl->post(self::httpBase() . '/echo', ['a' => 'b'])->then(function ($result) use (&$bodies) {
            // array POSTFIELDS => multipart/form-data
            $bodies[] = strtok(json_decode($result->getBody(), true)['ctype'], ';');
        });
        $curl->add([CURLOPT_URL => self::httpBase() . '/code?c=404'], ['tag' => 't1'])->then(null, function ($exception) use (&$bodies) {
            $bodies[] = get_class($exception) . ':' . $exception->getCode() . ':' . json_encode($exception->result->getParams());
        });
        $curl->run();
        $this->runLoop($loop, function () use (&$bodies) {
            return count($bodies) >= 3;
        });
        sort($bodies);
        $this->assertSame(['KHR\\React\\Curl\\Exception:404:{"tag":"t1"}', 'multipart/form-data', 'ok'], $bodies);
    }
}
