<?php

namespace mpcmf\system\io;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use mpcmf\system\pattern\factory;

/**
 * Monolog wrapper
 *
 * @author Ostrovsky Gregory <greevex@gmail.com>
 */
class log
    extends Logger
{
    use factory {
        __construct as factoryConstruct;
    }

    public function __construct($configSection)
    {
        self::factoryConstruct($configSection);
        $config = $this->getPackageConfig();
        parent::__construct($config['name']);
        $urlPath = parse_url($config['path'], PHP_URL_PATH);
        if (!is_null($urlPath)) {
            $directory = dirname($urlPath);
            if(!file_exists($directory)) {
                $status = @mkdir($directory, 0777, true);
                if($status === false) {
                    $config['path'] = sys_get_temp_dir() . '/mpcmf.' . posix_getpid() .'.log';
                    $this->addCritical("Log directory creation failed, use new path instead of original. New path: {$config['path']}");
                }
            } elseif(!is_writable($directory)) {
                @chmod($directory, 0777);
            }
        }
        
        $this->pushHandler(new StreamHandler($config['path'], $config['level']));
        MPCMF_DEBUG && $this->addDebug("New log created: {$this->configSection}");
    }
}
