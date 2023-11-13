<?php
namespace mpcmf\system\http;

use Slim\Slim;

class slimDriver extends Slim 
{

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
        set_error_handler(array('\Slim\Slim', 'handleErrors'));

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