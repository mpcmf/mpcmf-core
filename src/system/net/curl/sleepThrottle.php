<?php

namespace mpcmf\system\net\curl;

/**
 * "Sleep after N requests" throttling of curlPool.
 *
 * Same semantics as setSleep() of khr/php-mcurl-client 3.1 (scrapers depend on it for ban
 * avoidance), written from scratch:
 *  - a mark (time) is kept; while less than `sleep` seconds passed since the mark, a new batch may
 *    not start when the number of finished requests is a multiple of `next`;
 *  - inside a batch (finished count not a multiple of `next`) only the requests up to the next
 *    multiple may start;
 *  - the mark moves to "now" whenever the sleep is over (or slept off in blocking mode) and the
 *    check happens on a batch boundary - also while all slots are busy, so the mark advances in
 *    steps of `sleep` until slots are free;
 *  - blocking mode usleep()s inside the check instead of postponing the batch.
 *
 * Fixed: `next` < 1 no longer divides by zero (treated as 1).
 */
class sleepThrottle
{
    /** @var int|float|mixed seconds, as given: integer 0 disables the throttle (as before) */
    protected $sleep = 0;

    /** @var int */
    protected $next = 1;

    /** @var bool */
    protected $blocking = true;

    /** @var float|null */
    protected $mark;

    /**
     * @param int $next sleep after every $next requests
     * @param float $second
     * @param bool $blocking usleep() instead of postponing (blocks the whole event loop)
     */
    public function configure($next, $second = 1.0, $blocking = true)
    {
        $next = abs((int)$next);
        $this->next = $next < 1 ? 1 : $next;
        $this->sleep = $second;
        $this->blocking = (bool)$blocking;
    }

    public function isBlocking()
    {
        return $this->blocking && $this->sleep > 0;
    }

    /**
     * How many queued requests may start now. Must be called on every scheduling round while
     * requests are queued (also when $free <= 0): the call moves the mark.
     *
     * @param int $free free transfer slots (max requests - running), may be <= 0
     * @param int $finished requests finished so far
     *
     * @return int
     */
    public function allow($free, $finished)
    {
        if ($this->sleep === 0) {
            return $free;
        }
        $batchPosition = $finished % $this->next;
        $positionAfter = ($finished + $free) % $this->next;
        $now = microtime(true);
        if ($this->mark === null) {
            $this->mark = $now - $this->sleep;
        }
        $waitUs = (int)(($this->sleep - ($now - $this->mark)) * 1000000);
        if ($waitUs > 0) {
            if ($batchPosition === 0) {
                if ($this->blocking) {
                    usleep($waitUs);
                    $waitUs = 0;
                    $now = microtime(true);
                } else {
                    $free = 0;
                }
            } elseif ($batchPosition >= $positionAfter) {
                $free -= $positionAfter;
            }
        }
        if ($waitUs <= 0 && ($batchPosition === 0 || $batchPosition >= $positionAfter)) {
            $this->mark = $now;
        }

        return $free;
    }
}
