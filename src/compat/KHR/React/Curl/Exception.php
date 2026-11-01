<?php

namespace KHR\React\Curl;

/**
 * Rejection reason of mpcmf\system\net\reactCurl tasks: network error (code = curl errno),
 * HTTP code >= 400 (message "http error N", code = N) or a request the engine could not execute
 * (subclasses in mpcmf\system\net\curl\exception).
 *
 * Compatibility class: keeps the FQCN of khr/react-curl, implemented in mpcmf-core.
 */
class Exception extends \RuntimeException
{
    /**
     * @var \MCurl\Result
     */
    public $result;

    public function __construct(\MCurl\Result $result, $previous = null)
    {
        $this->result = $result;
        parent::__construct((string)$result->getError(), (int)$result->getErrorCode(), $previous);
    }
}
