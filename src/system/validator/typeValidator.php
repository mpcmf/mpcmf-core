<?php

namespace mpcmf\system\validator;

use mpcmf\system\validator\exception\validatorException;

/**
 * Type validator class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @author Oleg Andreev <ustr3ts@gmail.com>
 */
class typeValidator
{

    /**
     * Check variable type
     *
     * @param $value
     * @param $data
     *
     * @return bool
     *
     * @throws validatorException
     */
    public static function check($value, $data)
    {
        $explodedTypes = explode('|', $data['type']);
        $countChecks = count($explodedTypes);
        foreach ($explodedTypes as $type) {
            if (!self::checker($value, $type)) {
                $countChecks--;
            }
        }

        return $countChecks ? true : false;
    }

    protected static function checker($value, $type)
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'int':
            case 'integer':
                return is_int($value);
            case 'numeric':
                return is_numeric($value);
            case 'array':
                return is_array($value);
            case 'bool':
            case 'boolean':
                return is_bool($value);
            case 'float':
            case 'double':
                return is_float($value);
            case 'object':
                return is_object($value);
            case 'resource':
                return is_resource($value);
            case 'callable':
                return is_callable($value);
            case 'null':
                return $value === null;
            default:
                throw new validatorException("Unknown variable type `{$type}`");
        }
    }

}
