<?php

namespace mpcmf\modules\moduleBase\mappers\helper;

use mpcmf\modules\geoBox\mappers\areaMapper;
use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\modules\moduleBase\models\modelCursor;

/**
 * Model map abstraction class
 *
 * @package mpcmf\modules\moduleBase\mappers\helper\geoMapperHelper
 * @subpackage mpcmf\modules\moduleBase\mappers\mapperBase
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 *
 * @method string getGeoPointField()
 * @method remove()
 * @method modelCursor getAllBy()
 */
trait geoMapperHelper
{

    /**
     * @param modelBase $area
     *
     * @throws \mpcmf\modules\moduleBase\exceptions\modelException
     * @return \mpcmf\modules\moduleBase\models\modelCursor
     */
    public function getGeoByArea(modelBase $area)
    {
        return $this->getGeoByAreaId($area->getIdValue());
    }

    /**
     * @param mixed $areaId
     *
     * @return \mpcmf\modules\moduleBase\models\modelCursor
     */
    public function getGeoByAreaId($areaId)
    {
        return $this->getGeoByGeometry(areaMapper::getInstance()->getById($areaId)->getGeoAreaValue());
    }

    /**
     * @param modelBase $area
     *
     * @throws mapperException
     */
    public function removeGeoByArea(modelBase $area)
    {
        foreach ($this->getGeoByArea($area)->getCursor() as $point) {
            $this->remove($point);
        }
    }

    /**
     * @param $geometry
     * @param null|array $addCriteria
     *
     * @return modelCursor
     */
    public function getGeoByGeometry($geometry, $addCriteria = null)
    {
        $criteria = [
            $this->getGeoPointField() => [
                '$geoWithin' => [
                    '$geometry' => $geometry,
                ]
            ]
        ];
        if(isset($addCriteria)) {
            $criteria = array_replace_recursive($criteria, $addCriteria);
        }

        return $this->getAllBy($criteria);
    }
}