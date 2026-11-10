<?php

namespace KHR\React\Curl;

use mpcmf\system\net\curl\curlPool;

/**
 * Curl of khr/react-curl on top of curlPool (compatibility class)
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class Curl
{
    /**
     * @var \React\EventLoop\LoopInterface
     */
    public $loop;

    /**
     * Not used: the pool manages its own timer
     *
     * @var null
     */
    public $loop_timer;

    /**
     * @var curlPool
     */
    public $client;

    /**
     * Not used: see curlPool::setPollInterval()
     *
     * @var float
     */
    public $timeout = 0.01;

    /**
     * @param \React\EventLoop\LoopInterface $loop
     */
    public function __construct($loop)
    {
        $this->loop = $loop;
        $this->client = new curlPool($loop);
    }

    /**
     * @param string $url
     * @param array $opts
     *
     * @return \React\Promise\PromiseInterface
     */
    public function get($url, $opts = [])
    {
        $opts[CURLOPT_URL] = $url;
        $promise = $this->add($opts);
        $this->run();

        return $promise;
    }

    /**
     * @param string $url
     * @param array|string $data
     * @param array $opts
     *
     * @return \React\Promise\PromiseInterface
     */
    public function post($url, $data = [], $opts = [])
    {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $data;

        return $this->get($url, $opts);
    }

    /**
     * @param array $opts
     * @param array|string $params
     *
     * @return \React\Promise\PromiseInterface
     */
    public function add($opts, $params = [])
    {
        return $this->client->add($opts, $params);
    }

    public function run()
    {
        $this->client->run();
    }
}
