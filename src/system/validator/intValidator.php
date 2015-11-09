<?php

namespace mpcmf\system\validator;

use mpcmf\system\helper\io\log;

/**
 * Integer validator class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @author Oleg Andreev <ustr3ts@gmail.com>
 */
class intValidator
{

    /**
     * Check int by range
     *
     * @param $value
     * @param $data
     *
     * @return bool
     */
    public static function byRange($value, $data)
    {
        return $value >= $data['range']['min'] && $value <= $data['range']['max'];
    }

}
