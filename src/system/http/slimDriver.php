<?php
namespace mpcmf\system\http;

use Slim\Slim;

class slimDriver extends Slim 
{
    /**
     * Max distinct deprecations remembered
     */
    const LOGGED_DEPRECATIONS_LIMIT = 10000;

    /**
     * PHP 7 notices raised as warnings since PHP 8
     */
    const PHP7_NOTICES_PATTERN = '/^(Undefined (array key|variable|global variable|property)|Trying to access array offset on'
        . '|Attempt to read property|Array to string conversion|Uninitialized string offset|String offset cast occurred'
        . '|Resource ID#\d+ used as offset|Illegal string offset|Object of class .+ could not be converted to (int|float)$'
        . '|compact\(\): Undefined variable|unserialize\(\): Error at offset)/s';

    /**
     * Deprecations logged in this process
     *
     * @var bool[]
     */
    private static $loggedDeprecations = [];

    /**
     * Same as Slim::run(), but with PHP 8 aware error handler
     *
     * @see handleErrors()
     */
    public function run()
    {
        set_error_handler([static::class, 'handleErrors']);

        //Apply final outer middleware layers
        $this->addPrettyExceptions();

        //Invoke middleware and application stack
        $this->middleware[0]->call();

        //Fetch status, header, and body
        [$status, $headers, $body] = $this->response->finalize();

        // Serialize cookies (with optional encryption)
        \Slim\Http\Util::serializeCookies($headers, $this->response->cookies, $this->settings);

        //Send headers
        if (headers_sent() === false) {
            //Send status
            if (strpos(PHP_SAPI, 'cgi') === 0) {
                header(sprintf('Status: %s', \Slim\Http\Response::getMessageForCode($status)));
            } else {
                header(sprintf('HTTP/%s %s', $this->config('http.version'), \Slim\Http\Response::getMessageForCode($status)));
            }

            //Send headers
            foreach ($headers as $name => $value) {
                $hValues = explode("\n", $value);
                foreach ($hValues as $hVal) {
                    header("$name: $hVal", false);
                }
            }
        }

        //Send body, but only if it isn't a HEAD request
        if (!$this->request->isHead()) {
            echo $body;
        }

        $this->applyHook('slim.after');

        restore_error_handler();
    }

    /**
     * @return array{
     *     0: string,
     *     1: \Slim\Http\Headers,
     *     2: string
     * }
     * @throws \Exception
     */
    public function runRaw()
    {
        set_error_handler([static::class, 'handleErrors']);

        //Apply final outer middleware layers
        $this->addPrettyExceptions();

        //Invoke middleware and application stack
        $this->middleware[0]->call();

        //Fetch status, header, and body
        [$status, $headers, $body] = $this->response->finalize();

        // Serialize cookies (with optional encryption)
        \Slim\Http\Util::serializeCookies($headers, $this->response->cookies, $this->settings);

        $this->applyHook('slim.after');

        restore_error_handler();

        return [$status, $headers, $body];
    }

    /**
     * Slim error handler with PHP 7 semantics on PHP 8
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     *
     * @return bool|void
     * @throws \ErrorException
     */
    public static function handleErrors($errno, $errstr = '', $errfile = '', $errline = '')
    {
        if ($errno & (E_DEPRECATED | E_USER_DEPRECATED)) {
            $key = "{$errfile}:{$errline} {$errstr}";
            if (($errno & error_reporting()) && !isset(self::$loggedDeprecations[$key])) {
                if (count(self::$loggedDeprecations) >= self::LOGGED_DEPRECATIONS_LIMIT) {
                    self::$loggedDeprecations = [];
                }
                self::$loggedDeprecations[$key] = true;
                error_log("Deprecated: {$errstr} in {$errfile}:{$errline}");
            }

            return true;
        }

        if ($errno === E_WARNING && preg_match(self::PHP7_NOTICES_PATTERN, $errstr)) {
            $errno = E_NOTICE;
        }

        return parent::handleErrors($errno, $errstr, $errfile, $errline);
    }

    /**
     * Add pretty exceptions once, only in debug
     */
    protected function addPrettyExceptions()
    {
        if (!$this->config('debug')) {

            return;
        }
        foreach ($this->middleware as $middleware) {
            if ($middleware instanceof \Slim\Middleware\PrettyExceptions) {

                return;
            }
        }
        $this->add(new \Slim\Middleware\PrettyExceptions());
    }

    public function getRequestData()
    {
        $body = $this->request()->getBody();
        $params = $this->request()->params();
        if(is_array($body)) {

            return array_merge($params, $body);
        }

        return $params;
    }
}