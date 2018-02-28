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

    protected $config;

    /** @var Client[] $es */
    protected $es = [];

    protected $timeout;

    public function setTimeout($timeout = 300)
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @return Client
     * @throws \mpcmf\system\configuration\exception\configurationException
     * @throws \Exception
     */
    public function getElastic()
    {
        if ($this->config === null) {
            $this->config = $this->getPackageConfig();
            $this->elasticCurrent = $this->config['elastic.current'];
        }

        $this->elasticCurrent++;
        if (!isset($this->config['elastic'][$this->elasticCurrent])) {
            $this->elasticCurrent = 0;
        }

        if (!isset($es[$this->elasticCurrent])) {
            $esConfig = $this->config['elastic'][$this->elasticCurrent];
            if ($this->timeout !== null) {
                $esConfig['timeout'] = $this->timeout;
            }

            $this->es[$this->elasticCurrent] = Client::connection($esConfig);
        }
        return $this->es[$this->elasticCurrent];
    }
}