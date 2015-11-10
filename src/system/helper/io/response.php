<?php

namespace mpcmf\system\helper\io;

/**
 * System response helper
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait response
{
    protected static function success($data, $code = null)
    {
        $now = microtime(true);

        return [
            'status' => true,
            'data' => $data,
            'response_code' => $code,
            'request_time' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? ($now - $_SERVER['REQUEST_TIME_FLOAT']) : null,
            'time' => $now,
        ];
    }

    protected static function error($data, $errorCode = null, $responseCode = null)
    {
        $now = microtime(true);

        return [
            'status' => false,
            'data' => $data,
            'error_code' => $errorCode,
            'response_code' => $responseCode,
            'request_time' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? ($now - $_SERVER['REQUEST_TIME_FLOAT']) : null,
            'time' => $now,
        ];
    }

    protected static function nothing($data, $code = null)
    {
        $now = microtime(true);

        return [
            'status' => null,
            'data' => $data,
            'response_code' => $code,
            'request_time' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? ($now - $_SERVER['REQUEST_TIME_FLOAT']) : null,
            'time' => $now,
        ];
    }

    protected static function errorByException(\Exception $exception, $responseCode = null)
    {
        $now = microtime(true);

        return [
            'status' => false,
            'data' => [
                'errors' => [
                    $exception->getMessage()
                ]
            ],
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'error_code' => $exception->getCode(),
            'response_code' => $responseCode,
            'request_time' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? ($now - $_SERVER['REQUEST_TIME_FLOAT']) : null,
            'time' => $now,
        ];
    }
}