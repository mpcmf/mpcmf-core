<?php

namespace mpcmf\system\net\curl\exception;

/**
 * The deadline including queue wait was exceeded before the request started (opt-in, curlPool::setDeadline()), code 28 (CURLE_OPERATION_TIMEDOUT), error type "network".
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class deadlineException extends \KHR\React\Curl\Exception
{
}
