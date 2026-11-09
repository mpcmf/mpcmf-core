<?php

namespace mpcmf\system\net\curl;

use React\Promise\Deferred;

/**
 * One request of curlPool (internal).
 */
class curlTask
{
    const QUEUED = 0;
    const ACTIVE = 1;
    const DONE = 2;

    /** @var mixed|null */
    public $id;

    /** @var array task options (Result::getOptions()) */
    public $opts;

    /** @var mixed */
    public $params;

    /** @var Deferred|null */
    public $deferred;

    /** @var \CurlHandle|null */
    public $ch;

    /** @var int */
    public $state = self::QUEUED;

    /** @var float|null absolute microtime() deadline */
    public $expiresAt;

    /** @var float|null deadline length, for messages */
    public $deadline;

    public function __construct($id, array $opts, $params)
    {
        $this->id = $id;
        $this->opts = $opts;
        $this->params = $params;
    }

    /**
     * @return array query for \MCurl\Result
     */
    public function toQuery()
    {
        return ['id' => $this->id, 'opts' => $this->opts, 'params' => $this->params, 'ch' => $this->ch];
    }
}
