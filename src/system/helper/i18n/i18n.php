<?php

namespace mpcmf\system\helper\i18n;

use mpcmf\system\configuration\config;
use mpcmf\system\helper\i18n\exception\i18nException;

/**
 * i18n helper class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class i18n
{
    private $langCode;
    private $dictionary;

    /**
     * @param string $langCode
     *
     * @return static
     */
    public static function lang($langCode = 'default')
    {
        static $instances = [];
        if(!isset($instances[$langCode])) {
            $instances[$langCode] = new self($langCode);
        }

        return $instances[$langCode];
    }

    public function get()
    {
        $args = func_get_args();
        $code = reset($args);
        if(!isset($this->dictionary[$code])) {
            if($this->langCode !== 'default') {
                return call_user_func_array([self::lang('default'), 'get'], $args);
            }
            return $code;
        }
        array_shift($args);
        if(count($args)) {

            return call_user_func_array('sprintf', array_merge([$this->dictionary[$code]], $args));
        }

        return $this->dictionary[$code];
    }

    /**
     * @return mixed
     */
    private function getConfig()
    {
        static $config;
        if($config === null) {
            $config = config::getConfig(__CLASS__);
        }

        return $config;
    }

    /**
     * @param $langCode
     *
     * @throws i18nException
     */
    protected function __construct($langCode)
    {
        if(!isset($this->getConfig()[$langCode])) {
            throw new i18nException("Unable to find language by code: {$langCode}");
        }
        $this->langCode = $langCode;
        $this->dictionary = $this->getConfig()[$this->langCode];
    }
}
