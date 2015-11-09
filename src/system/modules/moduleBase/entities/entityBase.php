<?php

namespace mpcmf\modules\moduleBase\entities;

use mpcmf\system\helper\module\modulePartsHelper;
use mpcmf\system\pattern\singletonInterface;

/**
 * Base entity abstraction class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
abstract class entityBase
    implements singletonInterface
{
    use modulePartsHelper;
}