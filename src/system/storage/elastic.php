<?php

namespace mpcmf\system\storage;

use ElasticSearch\Client;
use mpcmf\system\pattern\factory;

/**
 * Class elastic
 *
 * @package mpcmf\system\storage
 * @author Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date 4/21/16 7:00 PM
 */
class elastic
{
    use factory;

    protected $elasticCurrent = 0;

    public function getElastic()
    {
        /** @var Client[] $es */
        static $config, $es = [];

        if ($config === null) {
            $config = $this->getPackageConfig();
            $this->elasticCurrent = $config['elastic.current'];
        }

        $this->elasticCurrent++;
        if (!isset($config['elastic'][$this->elasticCurrent])) {
            $this->elasticCurrent = 0;
        }

        if (!isset($es[$this->elasticCurrent])) {
            $esConfig = $config['elastic'][$this->elasticCurrent];

            $es[$this->elasticCurrent] = Client::connection($esConfig);
        }
        return $es[$this->elasticCurrent];
    }
}