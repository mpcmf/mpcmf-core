<?php

namespace mpcmf\system\net;

use KHR\React\Curl\Curl as khrCurl;
use mpcmf\system\helper\convert\url;
use mpcmf\system\net\curl\curlPool;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Asynchronous HTTP client on a ReactPHP event loop.
 *
 * Engine: mpcmf\system\net\curl\curlPool (own implementation, replaces khr/react-curl and
 * khr/php-mcurl-client; KHR\React\Curl\{Curl,Result,Exception} and MCurl\Result are kept as
 * compatibility classes). Done callbacks get \KHR\React\Curl\Result, error callbacks get
 * \KHR\React\Curl\Exception (network error: code = curl errno; HTTP >= 400: "http error N").
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class reactCurl
{

    /** @var  callable */
    protected $doneCallback;

    /** @var  callable */
    protected $errorCallback;

    /** @var  callable */
    protected $progressCallback;

    /** @var callable|null done callback that logs exceptions it throws */
    private $guardedDoneCallback;

    /** @var callable|null error callback that logs exceptions it throws */
    private $guardedErrorCallback;

    protected $baseConfig = [
        'max_requests' => 10,
        'sleep_after' => [
            'requests' => 1,
            'sleep' => 0.9,
            'blocking' => false,
        ]
    ];

    /**
     * initialized curl resource
     *
     * @var khrCurl
     */
    private $curl;

    /**
     * Options
     *
     * @var array
     */
    protected $options = [];

    /**
     * Default options array
     *
     * @var array
     */
    private $_defaultOptions = [
        CURLOPT_VERBOSE             => 0,
        CURLOPT_RETURNTRANSFER      => true,
        CURLOPT_FOLLOWLOCATION      => true,
        CURLOPT_MAXREDIRS           => 20,
        CURLOPT_SSL_VERIFYPEER      => false,
        CURLOPT_USERAGENT           => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.155 Safari/537.36',
        CURLOPT_CONNECTTIMEOUT      => 30,
        CURLOPT_TIMEOUT             => 90,
        CURLOPT_CUSTOMREQUEST       => 'GET',
        CURLOPT_HTTP_VERSION        => CURL_HTTP_VERSION_1_0,
        CURLOPT_COOKIEJAR           => '/tmp/mpcmf.cookie',
        CURLOPT_COOKIEFILE          => '/tmp/mpcmf.cookie',
        CURLOPT_AUTOREFERER         => true,
    ];

    /**
     * @param LoopInterface $loop
     */
    public function __construct($loop)
    {
        $this->curl = new khrCurl($loop);
        $this->options = $this->_defaultOptions;

        $sleepConf = $this->baseConfig['sleep_after'];
        $this->setSleep($sleepConf['requests'], $sleepConf['sleep'], $sleepConf['blocking']);
        $this->setMaxRequest($this->baseConfig['max_requests']);
        $this->curl->client->setCurlOption($this->options);
    }

    /**
     * Throttling: after every $next started requests wait $second seconds before the next batch
     * (default: 1 request, 0.9 s, non-blocking => up to max_requests every 0.9 s).
     *
     * @param int $next Sleep after * requests (< 1 is treated as 1)
     * @param float $second 0 - no throttling
     * @param bool $blocking usleep() inside the event loop instead of postponing requests
     */
    public function setSleep($next, $second = 1.0, $blocking = false)
    {
        $this->curl->client->setSleep($next, $second, $blocking);
    }

    /**
     * Max request in Asynchronous query
     *
     * @param $maxRequest
     */
    public function setMaxRequest($maxRequest)
    {
        $this->curl->client->setMaxRequest($maxRequest);
    }

    /**
     * @param array $proxyData Proxy structured array
     *
     * @return array
     *
     */
    public function getProxyOptions($proxyData)
    {
        $options = [];

        switch($proxyData['proxy_type']) {
            case 'http':
            case 'https':
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
                break;
            case 'socks4':
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
                break;
            case 'socks5':
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                break;
        }
        $options[CURLOPT_PROXY] = $proxyData['initial'];
        if(strpos($proxyData['initial'], '@') !== false) {
            $options[CURLOPT_PROXYAUTH] = $proxyData['auth_type'] === 'ntlm' ? CURLAUTH_NTLM : CURLAUTH_BASIC;
        }

        return $options;
    }

    /**
     * @param              $url
     * @param string       $method
     * @param array|string $params
     * @param array        $options
     *
     * @return PromiseInterface resolves with \KHR\React\Curl\Result, rejects with \KHR\React\Curl\Exception
     */
    public function prepareTask($url, $method = 'GET', $params = null, array $options = [])
    {
        $url = trim($url);
        $method = strtoupper($method);

        $queryString = (string)(is_array($params) ? http_build_query($params) : $params);

        switch($method) {
            case 'GET':
                $options[CURLOPT_CUSTOMREQUEST] = 'GET';
                if($queryString !== '') {
                    $parsedUrl = parse_url($url);
                    if(!isset($parsedUrl['query'])) {
                        $parsedUrl['query'] = '';
                    }
                    $parsedUrl['query'] .= empty($parsedUrl['query']) ? $queryString : "&{$queryString}";
                    $url = url::unParseUrl($parsedUrl);
                }
                break;
            case 'POST':
                $options[CURLOPT_CUSTOMREQUEST] = 'POST';
                $options[CURLOPT_POSTFIELDS] = $queryString;
                $options[CURLOPT_POST] = 1;
                break;
            default:
                $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
                $options[CURLOPT_POSTFIELDS] = $queryString;
                break;
        }

        $options[CURLOPT_URL] = $url;

        $promise = $this->curl->add($options);
        $promise->then($this->guardedDoneCallback, $this->guardedErrorCallback, $this->progressCallback);

        return $promise;
    }

    /**
     * Client: setCurlOption() (options for every request), setSleep(), setMaxRequest(),
     * enableHeaders(), opt-in features (setPollInterval(), setPollMode(), setMaxQueue(),
     * setDeadline(), setCancellable(), setAutoRun(), setCookieShare()).
     *
     * @return curlPool
     */
    public function getCurlClient()
    {
        return $this->curl->client;
    }


    public function run()
    {
        $this->curl->run();
    }

    /**
     * @return khrCurl
     */
    public function getReactCurl()
    {
        return $this->curl;
    }

    /**
     * @param callable $doneCallback
     */
    public function setDoneCallback($doneCallback)
    {
        $this->doneCallback = $doneCallback;
        $this->guardedDoneCallback = $this->guardCallback($doneCallback, 'done');
    }

    /**
     * @param callable $errorCallback
     */
    public function setErrorCallback($errorCallback)
    {
        $this->errorCallback = $errorCallback;
        $this->guardedErrorCallback = $this->guardCallback($errorCallback, 'error');
    }

    /**
     * @param callable $progressCallback
     */
    public function setProgressCallback($progressCallback)
    {
        $this->progressCallback = $progressCallback;
    }

    /**
     * Poll interval of the adaptive timer, setPollInterval(0.01, 0.01) - old fixed 10 ms
     *
     * @see curlPool::setPollInterval()
     */
    public function setPollInterval($min, $max = null)
    {
        $this->curl->client->setPollInterval($min, $max);
    }

    /**
     * @see curlPool::setPollMode()
     */
    public function setPollMode($mode, $selectTimeout = 0.005)
    {
        $this->curl->client->setPollMode($mode, $selectTimeout);
    }

    /**
     * Opt-in: reject new tasks with curl\exception\queueFullException when $max tasks are queued
     *
     * @see curlPool::setMaxQueue()
     */
    public function setMaxQueue($max)
    {
        $this->curl->client->setMaxQueue($max);
    }

    /**
     * Opt-in: time limit per task including the queue wait
     *
     * @see curlPool::setDeadline()
     */
    public function setDeadline($seconds)
    {
        $this->curl->client->setDeadline($seconds);
    }

    /**
     * Opt-in: $promise->cancel() aborts the task
     *
     * @see curlPool::setCancellable()
     */
    public function setCancellable($enable = true)
    {
        $this->curl->client->setCancellable($enable);
    }

    /**
     * Opt-in: prepareTask() starts processing without run()
     *
     * @see curlPool::setAutoRun()
     */
    public function setAutoRun($enable = true)
    {
        $this->curl->client->setAutoRun($enable);
    }

    /**
     * Opt-in: in-memory cookies shared by the requests of this client instead of the cookie file
     *
     * @see curlPool::setCookieShare()
     */
    public function setCookieShare($enable = true)
    {
        $this->curl->client->setCookieShare($enable);
    }

    /**
     * Opt-in: reject tasks whose curl options libcurl rejects (default: skip the option, warn)
     *
     * @see curlPool::setStrictOptions()
     */
    public function setStrictOptions($enable = true)
    {
        $this->curl->client->setStrictOptions($enable);
    }

    /**
     * Logger for exceptions thrown by done/error callbacks and skipped options (rate-limited,
     * see curlPool::setLogRateLimit()): function (string $message, array $context)
     *
     * @param callable|null $logger null - mpcmf log
     */
    public function setErrorLogger($logger)
    {
        $this->curl->client->setErrorLogger($logger);
    }

    /**
     * Exceptions thrown by done/error callbacks were swallowed silently by the promise: log them,
     * then rethrow so the promise chain behaves exactly as before.
     */
    protected function guardCallback($callback, $kind)
    {
        if (!is_callable($callback)) {
            return $callback;
        }
        $pool = $this->curl->client;

        return static function ($value) use ($callback, $kind, $pool) {
            try {
                return $callback($value);
            } catch (\Throwable $exception) {
                $result = $value instanceof \MCurl\Result ? $value : ($value->result ?? null);
                $url = $result instanceof \MCurl\Result ? ($result->getOptions()[CURLOPT_URL] ?? '') : '';
                $key = "callback:{$kind}:" . get_class($exception) . ':' . $exception->getMessage();
                $pool->logError("reactCurl: uncaught exception in {$kind} callback for {$url}", $exception, $key);

                throw $exception;
            }
        };
    }
}
