<?php

namespace mpcmf\system\validator;

/**
 * String validator class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @author Oleg Andreev <ustr3ts@gmail.com>
 */
class stringValidator
{

    /**
     * Check string by regex
     *
     * @param $value
     * @param $data
     *
     * @return bool
     */
    public static function byRegex($value, $data)
    {
        return (bool)preg_match($data['pattern'], $value);
    }

    /**
     * Check string by length
     *
     * @param $value
     * @param $data
     *
     * @return bool
     */
    public static function byLength($value, $data)
    {
        $strLen = mb_strlen($value);

        return $strLen >= $data['length']['min'] && $strLen <= $data['length']['max'];
    }


    /**
     *
     * @param $value
     * @param $data
     *
     * @return bool
     */
    public static function byBytes($value, $data)
    {
        $strLen = is_string($value) ? strlen($value) : 0;

        return $strLen >= $data['length']['min'] && $strLen <= $data['length']['max'];
    }
}
