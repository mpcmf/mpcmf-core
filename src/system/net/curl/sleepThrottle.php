<?php

namespace mpcmf\system\net\curl;

/**
 * "Sleep after N requests" throttling of curlPool, as setSleep() of khr/php-mcurl-client
 *
 * @author Dmitry Emelyanov <gilberg.vrn@gmail.com>
 */
class sleepThrottle
{
    /**
     * Seconds as given, integer 0 disables the throttle
     *
     * @var int|float
     */
    protected $sleep = 0;

    protected $next = 1;

    protected $blocking = true;

    /**
     * @var float|null
     */
    protected $mark;

    /**
     * Sleep $second seconds after every $next requests
     *
     * @param int $next
     * @param float $second
     * @param bool $blocking usleep() instead of postponing
     */
    public function configure($next, $second = 1.0, $blocking = true)
    {
        $next = abs((int)$next);
        $this->next = $next < 1 ? 1 : $next;
        $this->sleep = $second;
        $this->blocking = (bool)$blocking;
    }

    /**
     * @return bool
     */
    public function isBlocking()
    {
        return $this->blocking && $this->sleep > 0;
    }

    /**
     * How many queued requests may start now, call it on every round (it moves the mark)
     *
     * @param int $free free transfer slots, may be <= 0
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
