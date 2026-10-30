<?php
namespace mpcmf\system\http;

use Slim\Slim;

class slimDriver extends Slim 
{

    /**
     * Same as Slim::run(), but with PHP 8 aware error handler
     *
     * @see handleErrors()
     */
    public function run()
    {
        set_error_handler([static::class, 'handleErrors']);

        //Apply final outer middleware layers
        if ($this->config('debug')) {
            //Apply pretty exceptions only in debug to avoid accidental information leakage in production
            $this->add(new \Slim\Middleware\PrettyExceptions());
        }

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
        if ($this->config('debug')) {
            //Apply pretty exceptions only in debug to avoid accidental information leakage in production
            $this->add(new \Slim\Middleware\PrettyExceptions());
        }

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
     * Slim turns every reported error into ErrorException (HTTP 500), so deprecations are only logged
     * and notices promoted to warnings in PHP 8.0 are handled as notices again.
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
            if ($errno & error_reporting()) {
                error_log("Deprecated: {$errstr} in {$errfile}:{$errline}");
            }

            return true;
        }

        if ($errno === E_WARNING && preg_match('/^(Undefined (array key|variable|global variable|property)|Trying to access array offset on|Attempt to read property)/', $errstr)) {
            $errno = E_NOTICE;
        }

        return parent::handleErrors($errno, $errstr, $errfile, $errline);
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