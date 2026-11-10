<?php

namespace KHR\React\Curl;

/**
 * Rejection reason of reactCurl tasks (khr/react-curl compatibility class)
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
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
