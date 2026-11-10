<?php

namespace mpcmf\system\net\curl\exception;

/**
 * The queue limit was reached (opt-in, curlPool::setMaxQueue()), code 0, error type "client".
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class queueFullException extends \KHR\React\Curl\Exception
{
}
