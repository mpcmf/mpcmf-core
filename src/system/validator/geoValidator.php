<?php

namespace mpcmf\system\validator;

/**
 * Geo validator class
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class geoValidator
{

    const TYPE_POINT = 'Point';
    const TYPE_POLYGON = 'Polygon';
    const TYPE_MULTIPOLYGON = 'MultiPolygon';

    /**
     * Validate geoJson array
     *
     * @param $value
     * @param $data
     *
     * @return bool
     */
    public static function geoJson($value, $data)
    {
        return true;

        if(!isset($value['type'])) {
            return false;
        }
        if(isset($data['type']) && $value['type'] != $data['type']) {
            return false;
        }

        switch ($value['type']) {
            case self::TYPE_POINT:
            case self::TYPE_MULTIPOLYGON:
            case self::TYPE_POLYGON:
                if(!isset($value['coordinates'])) {
                    return false;
                }
                break;
            default:
                return false;
        }

        return true;
    }

}
