<?php

namespace KHR\React\Curl;

/**
 * Result of reactCurl tasks (khr/react-curl compatibility class)
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class Result extends \MCurl\Result
{
    /**
     * Deferred the caller put into params under '__deferred'
     *
     * @return \React\Promise\Deferred|null
     */
    public function shiftDeferred()
    {
        if (!isset($this->query['params']['__deferred'])) {
            return null;
        }
        $deferred = $this->query['params']['__deferred'];
        unset($this->query['params']['__deferred']);

        return $deferred;
    }
}
