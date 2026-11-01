<?php

namespace mpcmf\system\net\curl\exception;

/**
 * The queue limit was reached (opt-in, curlPool::setMaxQueue()), code 0, error type "client".
 */
class queueFullException extends \KHR\React\Curl\Exception
{
}
