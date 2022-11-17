<?php

namespace mpcmf\modules\moduleBase\exceptions;


use mpcmf\system\helper\io\codes;

class itemNotFoundException
    extends mapperException
{

    public function __construct($message = '', $code = codes::RESPONSE_CODE_NOT_FOUND, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
