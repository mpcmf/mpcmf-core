<?php

namespace mpcmf\system\validator;

use mpcmf\system\helper\io\log;

/**
 * Email validator class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @author Oleg Andreev <ustr3ts@gmail.com>
 */
class emailValidator
{

    /**
     * Check email's base by using filter_var method with FILTER_VALIDATE_EMAIL param
     *
     * @param $value
     *
     * @return bool
     */
    public static function byFilter($value)
    {
        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);

        return $filtered === $value;
    }

    /**
     * Check email's domain
     *
     * @param $value
     *
     * @return bool
     */
    public static function checkDomain($value)
    {
        static $regexData = [
            'pattern' => '/^(?:[0-9a-zA-Z](?:[\-\+\_]*[0-9a-zA-Z])*\.)+[0-9a-zA-Z](?:[\-\+\_]*[0-9a-zA-Z])*$/u'
        ];

        $explodedEmail = explode('@', $value);
        if (!isset($explodedEmail[1])) {

            return false;
        }
        $preparedDomain = idn_to_utf8($explodedEmail[1]);

        return stringValidator::byRegex($preparedDomain, $regexData);
    }

}
