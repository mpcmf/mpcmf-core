<?php

namespace mpcmf\system\net\curl\exception;

/**
 * The request was cancelled with $promise->cancel() (opt-in, curlPool::setCancellable()), code 42 (CURLE_ABORTED_BY_CALLBACK), error type "client".
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class cancelledException extends \KHR\React\Curl\Exception
{
}
