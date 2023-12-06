<?php

namespace mpcmf\system\io;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use mpcmf\system\io\monolog\customProcessors\mpcmfPidProcessor;
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

    protected const MPCMF_DEFAULT_LOG_FORMAT = "[%datetime%] [%pid%] %channel%.%level_name%: %message% %context% %extra%\n";

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
        
        $handler = new StreamHandler($config['path'], $config['level']);
        $handler->pushProcessor(new mpcmfPidProcessor());

        if (isset($config['colorOutput']) && $config['colorOutput'] === true)
            $handler->setFormatter(new ColoredLineFormatter(null, static::MPCMF_DEFAULT_LOG_FORMAT));

        $this->pushHandler($handler);

        MPCMF_DEBUG && $this->addDebug("New log created: {$this->configSection}");
    }

    public function addDebug($message, array $context = [])
    {
        $this->debug($message, $context);
    }

    public function addInfo($message, array $context = [])
    {
        $this->info($message, $context);
    }

    public function addNotice($message, array $context = [])
    {
        $this->notice($message, $context);
    }

    public function addWarning($message, array $context = [])
    {
        $this->warning($message, $context);
    }

    public function addError($message, array $context = [])
    {
        $this->error($message, $context);
    }

    public function addCritical($message, array $context = [])
    {
        $this->critical($message, $context);
    }

    public function addAlert($message, array $context = [])
    {
        $this->alert($message, $context);
    }

    public function addEmergency($message, array $context = [])
    {
        $this->emergency($message, $context);
    }
}
