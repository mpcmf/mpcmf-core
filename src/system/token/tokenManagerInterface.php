<?php

namespace mpcmf\system\token;

/**
 * Token manager class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
interface tokenManagerInterface
{
    public function validateToken($tokenString, $checkLimits = true);

    public function generateToken($tokenModel);

    public function decode($tokenString);

    public function encode($something);
}
