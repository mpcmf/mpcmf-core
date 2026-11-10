<?php

namespace MCurl;

/**
 * Result of one curl transfer.
 *
 * Compatibility class: keeps the FQCN and the observable behaviour of khr/php-mcurl-client 3.1
 * (\MCurl\Result) for application code. Implemented from scratch in mpcmf-core, the engine is
 * mpcmf\system\net\curl\curlPool.
 *
 * Magic properties (via getters): id, ch, info, options, httpCode, headers, body, bodyStream, json,
 * params, errorType, error, errorCode.
 *
 * @property-read mixed $id
 * @property-read string $body
 * @property-read int $httpCode
 * @property-read array|null $headers
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class Result
{
    /**
     * Request data: id, opts (task options), params, ch; optional failure (engine-level error).
     *
     * @var array
     */
    protected $query;

    /** @var array|null */
    protected $rawHeaders;

    public function __construct($query)
    {
        $this->query = $query;
    }

    /**
     * @return mixed|null request id given to add()
     */
    public function getId()
    {
        return $this->query['id'] ?? null;
    }

    /**
     * @return \CurlHandle|resource|null
     */
    public function getCh()
    {
        return $this->query['ch'] ?? null;
    }

    /**
     * @see curl_getinfo()
     * @return array
     */
    public function getInfo()
    {
        $ch = $this->getCh();

        return $ch === null ? [] : curl_getinfo($ch);
    }

    /**
     * Options given for this request (without client-level options and result streams)
     *
     * @return array
     */
    public function getOptions()
    {
        $options = $this->query['opts'];
        unset($options[CURLOPT_FILE], $options[CURLOPT_WRITEHEADER]);

        return $options;
    }

    /**
     * @return int
     */
    public function getHttpCode()
    {
        $ch = $this->getCh();

        return $ch === null ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }

    /**
     * Response headers, only when headers were enabled for the client:
     * ['result' => 'HTTP/1.1 200 OK', 'content-type' => 'text/html', ...]
     *
     * @return array|null
     */
    public function getHeaders()
    {
        if ($this->rawHeaders === null && isset($this->query['opts'][CURLOPT_WRITEHEADER])) {
            $stream = $this->query['opts'][CURLOPT_WRITEHEADER];
            rewind($stream);
            $lines = explode("\n", rtrim(stream_get_contents($stream)));
            $headers = ['result' => trim(array_shift($lines))];
            foreach ($lines as $line) {
                $parts = explode(':', $line, 2);
                $headers[strtolower(trim($parts[0]))] = isset($parts[1]) ? trim($parts[1]) : null;
            }
            $this->rawHeaders = $headers;
        }

        return $this->rawHeaders;
    }

    /**
     * @return string|null
     */
    public function getBody()
    {
        if (isset($this->query['opts'][CURLOPT_FILE])) {
            rewind($this->query['opts'][CURLOPT_FILE]);

            return stream_get_contents($this->query['opts'][CURLOPT_FILE]);
        }
        $ch = $this->getCh();

        return $ch === null ? null : curl_multi_getcontent($ch);
    }

    /**
     * Body stream, only when a result stream was configured for the client
     *
     * @return resource|null
     */
    public function getBodyStream()
    {
        if (!isset($this->query['opts'][CURLOPT_FILE])) {
            return null;
        }
        rewind($this->query['opts'][CURLOPT_FILE]);

        return $this->query['opts'][CURLOPT_FILE];
    }

    /**
     * json_decode() of the body; arguments after the body are passed to json_decode()
     *
     * @return mixed
     */
    public function getJson(...$args)
    {
        return @json_decode($this->getBody(), ...$args);
    }

    /**
     * @return mixed params given to add()
     */
    public function getParams()
    {
        return $this->query['params'] ?? [];
    }

    /**
     * @param string|null $type network|http|client, null - any
     *
     * @return bool
     */
    public function hasError($type = null)
    {
        $errorType = $this->getErrorType();

        return $errorType !== null && ($type === null || $errorType === $type);
    }

    /**
     * network - curl error, http - HTTP code >= 400, client - the request was not executed
     * (rejected curl option, cancel, queue limit), null - no error
     *
     * @return string|null
     */
    public function getErrorType()
    {
        if (isset($this->query['failure'])) {
            return $this->query['failure']['type'];
        }
        $ch = $this->getCh();
        if ($ch !== null && curl_error($ch) !== '') {
            return 'network';
        }
        if ($this->getHttpCode() >= 400) {
            return 'http';
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getError()
    {
        switch ($this->getErrorType()) {
            case null:
                return null;
            case 'http':
                return 'http error ' . $this->getHttpCode();
            case 'network':
                if (!isset($this->query['failure'])) {
                    return curl_error($this->getCh());
                }
            // no break: engine-level failure
            default:
                return $this->query['failure']['message'];
        }
    }

    /**
     * @return int|null curl errno for network errors, HTTP code for http errors
     */
    public function getErrorCode()
    {
        switch ($this->getErrorType()) {
            case null:
                return null;
            case 'http':
                return $this->getHttpCode();
            case 'network':
                if (!isset($this->query['failure'])) {
                    return curl_errno($this->getCh());
                }
            // no break: engine-level failure
            default:
                return $this->query['failure']['code'];
        }
    }

    public function __toString()
    {
        return (string)$this->getBody();
    }

    public function __get($key)
    {
        $method = 'get' . $key;

        return method_exists($this, $method) ? $this->$method() : null;
    }

    public function __isset($key)
    {
        return method_exists($this, 'get' . $key);
    }

    public function __destruct()
    {
        foreach ([CURLOPT_FILE, CURLOPT_WRITEHEADER] as $option) {
            if (isset($this->query['opts'][$option]) && is_resource($this->query['opts'][$option])) {
                fclose($this->query['opts'][$option]);
            }
        }
    }
}
