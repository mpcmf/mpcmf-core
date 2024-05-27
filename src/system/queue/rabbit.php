<?php

namespace mpcmf\system\queue;

use mpcmf\system\exceptions\curlException;
use mpcmf\system\helper\io\log;
use mpcmf\system\net\curl;
use mpcmf\system\pattern\factory;

/**
 * Class rabbitMQ
 *
 * @package rabbitMq\lib
 * @author Borovikov Maxim <maxim.mahi@gmail.com>
 * @author Ostrovsky Gregory <greevex@gmail.com>
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class rabbit extends lavin
{}