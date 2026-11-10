<?php

namespace mpcmf\system\net\curl;

use React\Promise\Deferred;

/**
 * Request of curlPool
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class curlTask
{
    const QUEUED = 0;
    const ACTIVE = 1;
    const DONE = 2;

    public $id;

    public $opts;

    public $params;

    /**
     * @var Deferred|null
     */
    public $deferred;

    /**
     * @var \CurlHandle|null
     */
    public $ch;

    public $state = self::QUEUED;

    /**
     * Absolute microtime() deadline
     *
     * @var float|null
     */
    public $expiresAt;

    /**
     * Deadline length, for messages
     *
     * @var float|null
     */
    public $deadline;

    public function __construct($id, array $opts, $params)
    {
        $this->id = $id;
        $this->opts = $opts;
        $this->params = $params;
    }

    /**
     * Query for \MCurl\Result
     *
     * @return array
     */
    public function toQuery()
    {
        return ['id' => $this->id, 'opts' => $this->opts, 'params' => $this->params, 'ch' => $this->ch];
    }
}
