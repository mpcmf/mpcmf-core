<?php

namespace mpcmf\system\helper\console;

/**
 * consoleTimer: Timer and progressbar for console applications
 *
 * @package system
 * @subpackage service
 * @version 0.7
 * @author GreeveX <greevex@gmail.com>
 */
class progressBar
{
    /**
     * Total amount of data
     *
     * @var int
     */
    protected $data_counter = 0;

    /**
     * The length of the progress bar to draw
     *
     * @var int
     */
    protected $pb_width = 60;

    /**
     * Time when progressbar starts
     *
     * @var float
     */
    protected $time_start = 0;

    /**
     * Last progressbar update time
     *
     * @var float
     */
    protected $last_update = 0;

    /**
     * Elapsed time when progressbar starts
     *
     * @var float
     */
    protected $time_elapsed = 0;

    /**
     * What percentage of processing is completed
     *
     * @var float
     */
    protected $stat_percent = 0;

    /**
     * How many seconds are left to wait
     *
     * @var float
     */
    protected $stat_estimatedTime = 0;

    /**
     * Output progressbar format
     *
     * @var string
     */
    protected $output_format = '[{progress}] | {done}% | {speed} p/s | te {time} | r {ram}';

    /**
     * Construct method
     * Send how mush data in progressbar and progressbar width
     *
     * @param int $data_counter - the amount of data
     * @param int $pb_width - progressbar width
     */
    public function __construct($data_counter, $pb_width = 60) {
        $this->time_start = microtime(true);
        $this->last_update = $this->time_start;
        $this->data_counter = $data_counter;
        $this->speed = 0;
        $this->pb_width = $pb_width;
    }

    /**
     * Update progressbar for calculating elapsed time, statistics percent, speed, esimated time and last update time
     *
     * @param int $curr_element - how many items already passed
     */
    public function update($curr_element) {
        if($this->data_counter < 1) {
            $this->data_counter = 1;
        }
        $this->time_elapsed = microtime(true) - $this->time_start;
        $this->stat_percent = round((($curr_element * 100) / $this->data_counter), 3);
        $this->speed = $curr_element / $this->time_elapsed;
        $this->stat_estimatedTime = ($this->data_counter - $curr_element) / $this->speed;
        $this->last_update = microtime(true);
    }

    /**
     * Progressbar data
     * Ключи массива:
     *   done => (float) percentage of completion
     *   time_elapsed => (float unix_timestamp) elapsed time
     *   time_estimated => (float unix_timestamp) estimated time for completion
     *   speed => (float) how much elements completed in one second
     *
     * @return array
     */
    public function getData() {
        return array(
            'done' => number_format($this->stat_percent, 2),
            'time_elapsed' => $this->time_elapsed,
            'time_estimated' => $this->stat_estimatedTime,
            'speed' => number_format($this->speed, 2),
        );
    }

    /**
     * Draw a progressbar in the console, there should be no line breaks between "frames"!
     * Before each new "frame" call an update to the latest information
     */
    public function draw() {
        $data = $this->getData();
        if ($data['done'] > 100) {
            $data['done'] = 100;
        }
        $done = ceil($this->pb_width / 100 * $data['done']);
        if ($done < 1) {
            $done = 1;
        }
        $search = array(
            '{progress}',
            '{done}',
            '{speed}',
            '{time}',
            '{ram}'
        );
        $replace = array(
            (str_repeat('=', $done - 1) . '>' . str_repeat(' ', $this->pb_width - ($done - 1))),
            $data['done'],
            $data['speed'],
            date('i:s', $data['time_estimated']),
            round(memory_get_usage(true)/1024/1024, 4)
        );
        $string = str_replace($search, $replace, $this->output_format);
        print "\r{$string}";
    }

    /**
     * Set output format for progressbar
     *
     * @param string $string
     */
    public function setOutputFormat($string)
    {
        $this->output_format = $string;
    }

}