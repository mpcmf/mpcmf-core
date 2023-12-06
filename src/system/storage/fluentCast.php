<?php

namespace mpcmf\system\storage;

use mpcmf\system\storage\exception\storageException;

class fluentCast
{

    public static function castTypes($map, $object)
    {
        if (!is_iterable($object)) {
            throw new storageException('object is not iterable: ' . json_encode($object));
        }
        foreach ($object as $field => &$value) {
            if ($field === '_id') {
                continue;
            }
            $fieldMap = $map[$field];
            $value = self::castType($fieldMap['type'], $value);
        }
        unset($value);

        return $object;
    }

    public static function castType($mapperType, $value)
    {
        switch ($mapperType) {
            case 'string':
                return (string)$value;
            case 'int':
                return (int)$value;
            case 'boolean':
                //@NOTE tinyint
                return (int)$value;
            case 'string[]':
                return json_encode($value);
            default:
                throw new storageException("Unsupported field type `{$mapperType}` for conversion to sql");
        }
    }

    public static function unCastTypes($map, $object)
    {
        if (!is_iterable($object)) {
            throw new storageException('object is not iterable: ' . json_encode($object));
        }
        foreach ($object as $field => &$value) {
            if ($field === '_id') {
                continue;
            }
            $fieldMap = $map[$field];
            $value = self::unCastType($fieldMap['type'], $value);
        }
        unset($value);

        return $object;
    }

    public static function unCastType($mapperType, $value)
    {
        switch ($mapperType) {
            case 'string':
                return (string)$value;
            case 'int':
                return (int)$value;
            case 'boolean':
                //@NOTE tinyint
                return (int)$value;
            case 'string[]':
                return (array)json_decode($value, true);
            default:
                throw new storageException("Unsupported field type `{$mapperType}` for conversion to sql");
        }
    }

}