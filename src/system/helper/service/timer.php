<?php

namespace mpcmf\system\helper\service;


/**
 * Description of timer
 *
 * @author GreeveX <greevex@gmail.com>
 */
class timer
{
    /**
     * Start time
     *
     * @var int
     */
    private $time_start;

    /**
     * End time
     *
     * @var int
     */
    private $time_end;

    /**
     * Run timer
     *
     * @return timer
     */
    public function start()
    {
        $this->time_start = microtime(true);
        return $this;
    }

    /**
     * Stops timer
     *
     * @return timer
     */
    public function stop()
    {
        $this->time_end = microtime(true);
        return $this;
    }

    /**
     * Get period between time start and time end
     *
     * @param int $precision
     * @return float
     */
    public function getPeriod($precision = 4)
    {
        $this->time_end = microtime(true);
        return round($this->time_end - $this->time_start, $precision);
    }

    /**
     * Reset timer time start and time end variables
     *
     * @return timer
     */
    public function reset()
    {
        $this->time_start = 0;
        $this->time_end = 0;

        return $this;
    }
}