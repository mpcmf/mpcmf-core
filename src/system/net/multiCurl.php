<?php

namespace mpcmf\system\net;

use mpcmf\system\net\exception\multiCurlException;

/**
 * Curl driver for HTTP Request class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class multiCurl
{
    /**
     * curl_multi resource instance
     *
     * @var resource
     */
    private $multi_curl;

    /**
     * Callback callable
     *
     * @var callable
     */
    private $callback;

    /**
     * Check period in microseconds
     *
     * @var int
     */
    private $checkPeriod = 50;

    /**
     * Construct new object of multiCurl
     */
    public function __construct()
    {
        $this->multi_curl = curl_multi_init();
        $this->setCallback([$this, 'exampleCallback']);
    }

    /**
     * Set callback on success
     *
     * @param callable $callback
     * @return callable
     */
    public function setCallback($callback)
    {
        return $this->callback = $callback;
    }

    /**
     * @param int $checkPeriod
     */
    public function setCheckPeriod($checkPeriod)
    {
        $this->checkPeriod = (int)$checkPeriod;
    }

    /**
     * Example of callback function
     *
     * @param string $output Curl content result
     * @param array $info Meta info
     * @return bool
     */
    public function exampleCallback($output, $info)
    {
        echo json_encode(
            [
                'output' => $output,
                'info'   => $info
            ]
        );

        return true;
    }

    /**
     * Add curl resource to multiCurl
     *
     * @param resource $curlResource
     * @return int
     */
    public function add($curlResource)
    {
        return curl_multi_add_handle($this->multi_curl, $curlResource);
    }

    /**
     * Execute all curl object added in multiCurl
     *
     * @return bool
     * @throws multiCurlException
     */
    public function execute()
    {
        do {
            while (($multiCurlStatus = curl_multi_exec($this->multi_curl, $running)) == CURLM_CALL_MULTI_PERFORM) {
                usleep($this->checkPeriod);
            }
            if ($multiCurlStatus != CURLM_OK) {
                throw new multiCurlException('Some multiCurl error: ' . curl_error($this->multi_curl), curl_errno($this->multi_curl));
            }
            while ($done = curl_multi_info_read($this->multi_curl)) {

                $output = curl_multi_getcontent($done['handle']);
                $info = curl_getinfo($done['handle']);

                call_user_func_array($this->callback, array($output, $info));
                curl_multi_remove_handle($this->multi_curl, $done['handle']);
            }

            if ($running) {
                curl_multi_select($this->multi_curl, 10);
            }
        } while ($running);

        curl_multi_close($this->multi_curl);

        return true;
    }
}