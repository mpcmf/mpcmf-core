<?php

namespace mpcmf\modules\moduleBase;

use mpcmf\system\helper\module\moduleHelper;
use mpcmf\system\pattern\singletonInterface;

/**
 * moduleBase abstraction class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
abstract class moduleBase
    implements singletonInterface
{
    use moduleHelper;

    public function __construct()
    {
        $this->bindAclGroups();
    }

    abstract protected function bindAclGroups();

    public function getName()
    {
        return $this->getModuleName();
    }
}