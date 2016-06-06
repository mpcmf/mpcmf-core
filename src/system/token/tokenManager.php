<?php

namespace mpcmf\system\token;

use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\system\configuration\config;
use mpcmf\system\helper\io\response;
use mpcmf\system\pattern\singleton;
use mpcmf\system\token\exception\tokenManagerException;

/**
 * Token manager class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class tokenManager
    implements tokenManagerInterface
{
    use singleton, response;

    /** @var tokenManagerInterface */
    protected $token_class;

    public function __construct()
    {
        $tokenClass = config::getConfig(__CLASS__)['token_class'];

        $this->token_class = new $tokenClass();

        if (!($this->token_class instanceof tokenManagerInterface)) {
            throw new tokenManagerException("{$tokenClass} is not implements tokenManagerInterface");
        }
    }

    public function validateToken($tokenString, $checkLimits = true)
    {
        return $this->token_class->validateToken($tokenString, $checkLimits);
    }

    public function generateToken(modelBase $tokenModel)
    {
        return $this->token_class->generateToken($tokenModel);
    }

    public function decode($tokenString)
    {
        return $this->token_class->decode($tokenString);
    }

    public function encode($something)
    {
        return $this->token_class->encode($something);
    }
}
