<?php

namespace mpcmf\system\token;

use mpcmf\modules\moduleBase\models\modelBase;

/**
 * Token manager class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
interface tokenManagerInterface
{
    public function validateToken($tokenString, $checkLimits = true);

    public function generateToken(modelBase $tokenModel);

    public function decode($tokenString);
}
