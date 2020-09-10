<?php

namespace mpcmf\system\view;

use mpcmf\system\configuration\config;
use mpcmf\system\helper\system\profiler;

/**
 * Smarty view driver for slim mfr
 *
 * @author  Ostrovsky Gregory <greevex@gmail.com>
 */
class smartyDriver
    extends \Smarty
{

    /**
     * Constructor
     */
    public function __construct()
    {
        profiler::addStack('view::init');

        if(!defined('SMARTY_MBSTRING')) {
            define(SMARTY_MBSTRING, true);
        }
        $options = config::getConfig(get_called_class());

        parent::__construct();

        $this->force_compile = $options['force_compile'];
        $this->debugging = $options['debugging'];
        $this->caching = $options['caching'];
        $this->cache_lifetime   = $options['cache_lifetime'];
        $this->setCompileDir($options['compile_dir']);
        $this->setConfigDir($options['config_dir']);
        $this->setCacheDir($options['cache_dir']);
    }

    /**
     * Does view data have value with key?
     * @param  string  $key
     * @return boolean
     */
    public function has($key)
    {
        return (bool)$this->getTemplateVars($key);
    }

    /**
     * Return view data value with key
     * @param  string $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->getTemplateVars($key);
    }

    /**
     * Set view data value with key
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value)
    {
        $this->assign($key, $value);
    }

    /**
     * Return view data
     * @return array
     */
    public function all()
    {
        return $this->getTemplateVars();
    }

    /**
     * Clear view data
     */
    public function clear()
    {
        $this->clearAllAssign();
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * Get data from view
     *
     * @param null $key
     *
     * @return string
     */
    public function getData($key = null)
    {
        return $this->getTemplateVars($key);
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * Set data for view
     */
    public function setData()
    {
        $args = func_get_args();
        if (count($args) === 1 && is_array($args[0])) {
            throw new \Exception('Unsupported functional!');
        } elseif (count($args) === 2) {
            $this->assign($args[0], $args[1]);
        } else {
            throw new \InvalidArgumentException('Cannot set View data with provided arguments. Usage: `View::setData( $key, $value );` or `View::setData([ key => value, ... ]);`');
        }
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release
     *
     * Append data to view
     *
     * @param array|object $data
     *
     * @throws \InvalidArgumentException
     */
    public function appendData($data)
    {
        if (!is_array($data) && !$data instanceof \Traversable) {
            throw new \InvalidArgumentException('Cannot append view data. Expected array or traversable argument.');
        }

        foreach ($data as $key => $value) {
            $this->assign($key, $value);
        }
    }

    /********************************************************************************
     * Resolve template paths
     *******************************************************************************/

    /**
     * Set the base directory that contains view templates
     * @param   string $directory
     * @throws  \InvalidArgumentException If directory is not a directory
     */
    public function setTemplatesDirectory($directory)
    {
        $this->setTemplateDir($directory);
    }

    /**
     * Add the base directory that contains view templates
     *
     * @param string $directory
     * @param string     $key
     */
    public function addTemplatesDirectory($directory, $key = null)
    {
        $this->addTemplateDir($directory, $key);
    }

    /**
     * Get templates base directory
     * @return string
     */
    public function getTemplatesDirectory()
    {
        return $this->getTemplateDir();
    }

    /**
     * Get fully qualified path to template file using templates base directory
     * @param  string $file The template file pathname relative to templates base directory
     * @return string
     */
    public function getTemplatePathname($file)
    {
        $templateDir = rtrim($this->getTemplateDir(), '/\\');

        return $templateDir . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);
    }

    /**
     * Render page by template file path
     *
     * @param string $template Path to template
     * @param bool $asString Return result as string
     * @param null|string $cache_id ID of template cache
     *
     * @return bool|string
     * @throws \Exception
     * @throws \SmartyException
     */
    public function render($template, $asString = true, $cache_id = null)
    {
        profiler::addStack('view::render');

        if($asString) {

            return $this->fetch($template, $cache_id);
        } else {
            $this->display($template, $cache_id);
            return true;
        }
    }

    /**
     * displays a Smarty template
     *
     * @param string $template   the resource handle of the template file or template object
     * @param mixed  $cache_id   cache id to be used with this template
     * @param mixed  $compile_id compile id to be used with this template
     * @param object $parent     next higher level of Smarty variables
     */
    public function display($template = null, $cache_id = null, $compile_id = null, $parent = null)
    {
        if ($cache_id === null) {
            $cache_id = md5(json_encode($this->getData()));
        }

        parent::display($template, $cache_id, $compile_id, $parent);
    }
}
