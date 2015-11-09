<?php

namespace mpcmf\system\token;

use mpcmf\modules\authex\models\tokenModel;

/**
 * Token manager class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
interface tokenManagerInterface
{
    public function validateToken($tokenString, $checkLimits = true);

    public function generateToken(tokenModel $tokenModel);

    public function decode($tokenString);
}
