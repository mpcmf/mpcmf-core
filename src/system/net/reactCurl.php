<?php

namespace mpcmf\system\net;

use KHR\React\Curl\Curl as khrCurl;
use mpcmf\system\helper\convert\url;
use mpcmf\system\net\curl\curlPool;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Curl wrapper
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

    /** @var callable|null */
    private $guardedDoneCallback;

    /** @var callable|null */
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
     * @param int $next Sleep after * requests
     * @param float $second
     * @param bool $blocking async mode
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
     * @return PromiseInterface
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
     * Adaptive poll interval
     *
     * @param float $min
     * @param float|null $max
     */
    public function setPollInterval($min, $max = null)
    {
        $this->curl->client->setPollInterval($min, $max);
    }

    /**
     * Poll mode, see curlPool::POLL_*
     *
     * @param string $mode
     * @param float $selectTimeout
     */
    public function setPollMode($mode, $selectTimeout = 0.005)
    {
        $this->curl->client->setPollMode($mode, $selectTimeout);
    }

    /**
     * Opt-in: queue limit of tasks
     *
     * @param int $max
     */
    public function setMaxQueue($max)
    {
        $this->curl->client->setMaxQueue($max);
    }

    /**
     * Opt-in: time limit per task including the queue wait
     *
     * @param float|null $seconds
     */
    public function setDeadline($seconds)
    {
        $this->curl->client->setDeadline($seconds);
    }

    /**
     * Opt-in: $promise->cancel() aborts the task
     *
     * @param bool $enable
     */
    public function setCancellable($enable = true)
    {
        $this->curl->client->setCancellable($enable);
    }

    /**
     * Opt-in: prepareTask() starts processing without run()
     *
     * @param bool $enable
     */
    public function setAutoRun($enable = true)
    {
        $this->curl->client->setAutoRun($enable);
    }

    /**
     * Opt-in: in-memory cookies instead of the cookie file
     *
     * @param bool $enable
     */
    public function setCookieShare($enable = true)
    {
        $this->curl->client->setCookieShare($enable);
    }

    /**
     * Opt-in: reject tasks whose curl options libcurl rejects
     *
     * @param bool $enable
     */
    public function setStrictOptions($enable = true)
    {
        $this->curl->client->setStrictOptions($enable);
    }

    /**
     * Logger of callback exceptions and skipped options, null - mpcmf log
     *
     * @param callable|null $logger
     */
    public function setErrorLogger($logger)
    {
        $this->curl->client->setErrorLogger($logger);
    }

    /**
     * Log exceptions of a done/error callback and rethrow them
     *
     * @param callable|null $callback
     * @param string $kind
     *
     * @return callable|null
     */
    protected function guardCallback($callback, $kind)
    {
        if(!is_callable($callback)) {
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
