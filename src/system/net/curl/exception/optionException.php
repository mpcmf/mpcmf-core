<?php

namespace mpcmf\system\net\curl\exception;

/**
 * A curl option was rejected by curl_setopt_array() (TypeError, ValueError or false): only this request is rejected, code 43 (CURLE_BAD_FUNCTION_ARGUMENT), error type "client".
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class optionException extends \KHR\React\Curl\Exception
{
}
