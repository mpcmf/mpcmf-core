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
        if (!is_string($value)) {
            // it enables us to add different validators for different types (e.g. string|int)
            return true;
        }
        $strLen = strlen($value);

        return $strLen >= $data['length']['min'] && $strLen <= $data['length']['max'];
    }
}
