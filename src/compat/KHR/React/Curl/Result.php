<?php

namespace KHR\React\Curl;

/**
 * Result passed to done callbacks of mpcmf\system\net\reactCurl.
 *
 * Compatibility class: keeps the FQCN of khr/react-curl, implemented in mpcmf-core.
 */
class Result extends \MCurl\Result
{
    /**
     * Kept for compatibility: the engine keeps its deferreds internally, so this returns the
     * deferred only when one was put into params under '__deferred' by the caller.
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
