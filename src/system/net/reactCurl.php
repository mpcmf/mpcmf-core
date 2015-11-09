<?php

namespace mpcmf\system\net;

use KHR\React\Curl\Curl;
use mpcmf\system\helper\convert\url;
use React\EventLoop\ExtEventLoop;
use React\EventLoop\LibEventLoop;
use React\EventLoop\LibEvLoop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Promise;

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
     * @var Curl
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
        CURLOPT_FOLLOWLOCATION      => 20,
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
     * @param ExtEventLoop|LibEventLoop|LibEvLoop|StreamSelectLoop $loop
     */
    public function __construct($loop)
    {
        $this->curl = new Curl($loop);
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
     * @return Promise
     */
    public function prepareTask($url, $method = 'GET', array $params = [], array $options = [])
    {
        $url = trim($url);
        $method = strtoupper($method);

        $queryString = http_build_query($params);

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
        $this->curl->run();
        $promise->then($this->doneCallback, $this->errorCallback, $this->progressCallback);

        return $promise;
    }

    /**
     * @return \MCurl\Client
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
     * @return Curl
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
    }

    /**
     * @param callable $errorCallback
     */
    public function setErrorCallback($errorCallback)
    {
        $this->errorCallback = $errorCallback;
    }

    /**
     * @param callable $progressCallback
     */
    public function setProgressCallback($progressCallback)
    {
        $this->progressCallback = $progressCallback;
    }
}