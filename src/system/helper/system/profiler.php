<?php

namespace mpcmf\system\helper\system;

/**
 * Class profiler
 *
 * @package mpcmf\system\helper\system
 * @author Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date 11/18/15 5:14 PM
 */
class profiler
{
    private static $instance;
    private static $internalCounter = 0;

    private $timing = [
        'pending' => [],
        'results' => [],
    ];

    private static $stack = [];

    public static function addStack($key)
    {
        if(!isset(self::$stack[$key])) {
            self::$stack[$key] = 1;
        } else {
            self::$stack[$key]++;
        }
    }

    public static function getStack()
    {
        return self::$stack;
    }

    public static function resetStack()
    {
        self::$stack = [];
    }

    public static function getStackAsString($implodeBy = ' / ', $filterRegex = null)
    {
        $now = microtime(true);
        $strings = [
            'php::time: ' . number_format($now - $_SERVER['REQUEST_TIME_FLOAT'], 6),
            'app::time: ' . number_format($now - $GLOBALS['MPCMF_START_TIME'], 6),
        ];
        foreach(self::$stack as $key => $value) {
            if($filterRegex === null || preg_match($filterRegex, $key)) {
                $strings[] = "{$key}: {$value}";
            }
        }

        return implode($implodeBy, $strings);
    }

    /**
     * @return profiler
     */
    public static function get()
    {
        if(self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function start($key)
    {
        $this->timing['pending'][$key] = [
            'counter' => self::$internalCounter++,
            'mem' => memory_get_usage(true),
            'time' => microtime(true)
        ];
    }

    public function stop($key)
    {
        if(!isset($this->timing['results'][$key])) {
            $this->timing['results'][$key] = [];
        }
        $record = $this->timing['pending'][$key];
        $this->timing['results'][$key][$record['counter']] = [
            'key' => $key,
            'mem' => memory_get_usage(true) - $record['mem'],
            'time' => microtime(true) - $record['time'],
        ];
        unset($this->timing['pending'][$key]);
    }

    public function popResults()
    {
        return array_splice($this->timing['results'], 0);
    }

    public function getResults()
    {
        return $this->timing['results'];
    }

    public function getAvgResults()
    {
        $results = [];
        foreach($this->timing['results'] as $key => $result) {
            $results[$key] = [
                'calls' => 0,
                'memTotal' => 0,
                'memAvg' => 0,
                'timeAvg' => 0,
                'timeTotal' => 0
            ];
            foreach($result as $keyCounter => $keyData) {
                $results[$key]['calls']++;
                $results[$key]['memTotal'] += $keyData['mem'];
                $results[$key]['timeTotal'] += $keyData['time'];
            }
            $results[$key]['memAvg'] = $results[$key]['memTotal'] / $results[$key]['calls'];
            $results[$key]['timeAvg'] = $results[$key]['timeTotal'] / $results[$key]['calls'];
        }

        return $results;
    }
}
