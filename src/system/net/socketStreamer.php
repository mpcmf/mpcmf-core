<?php

namespace mpcmf\system\net;

use mpcmf\system\helper\io\log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * socketStreamer class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class socketStreamer
{
    use log;

    const DEBUG_ENABLED = false;
    const NEED_AUTH = true;

    protected $bind_host = '0.0.0.0';
    protected $bind_port = 9987;
    protected $onEveryCycle = null;
    protected $getDataArrayFunction = null;
    protected $json = true;
    protected $gzip = true;
    protected $delimiter = "\0";
    protected $base64 = true;
    protected $queueLimit = 500000;
    protected $timeoutWrite = 0.5;
    protected $timeoutRead = 0.1;
    protected $authHash = '';

    /**
     * @var OutputInterface
     */
    private $output;
    private $clients = [];
    private $newbies = [];
    private $queue = [];
    private $recoverQueue = [];
    private $counters = [];
    private $errors = [];
    private $socketServerHandle;
    private $bytesSent = [];
    private $bytesProcessed = 0;

    public function getMaxQueueSize()
    {
        arsort($this->counters, SORT_DESC);
        return reset($this->counters);
    }

    public function getClientsCount()
    {
        return count($this->clients);
    }

    /**
     * @param string $authHash
     */
    public function setAuthHash($authHash)
    {
        $this->authHash = $authHash;
    }

    /**
     * @param boolean $base64
     */
    public function setBase64($base64)
    {
        $this->base64 = $base64;
    }

    /**
     * @param string $bind_host
     */
    public function setBindHost($bind_host)
    {
        $this->bind_host = $bind_host;
    }

    /**
     * @param int $bind_port
     */
    public function setBindPort($bind_port)
    {
        $this->bind_port = $bind_port;
    }

    /**
     * @param string $delimiter
     */
    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
    }

    /**
     * @param null $getDataArrayFunction
     */
    public function setGetDataArrayFunction($getDataArrayFunction)
    {
        $this->getDataArrayFunction = $getDataArrayFunction;
    }

    /**
     * @param boolean $gzip
     */
    public function setGzip($gzip)
    {
        $this->gzip = $gzip;
    }

    /**
     * @param null $onEveryCycle
     */
    public function setOnEveryCycle($onEveryCycle)
    {
        $this->onEveryCycle = $onEveryCycle;
    }

    /**
     * @param int $queueLimit
     */
    public function setQueueLimit($queueLimit)
    {
        $this->queueLimit = $queueLimit;
    }

    /**
     * @param float $timeoutRead
     */
    public function setTimeoutRead($timeoutRead)
    {
        $this->timeoutRead = $timeoutRead;
    }

    /**
     * @param float $timeoutWrite
     */
    public function setTimeoutWrite($timeoutWrite)
    {
        $this->timeoutWrite = $timeoutWrite;
    }

    private function _log($string, $tag)
    {
        MPCMF_DEBUG && self::log()->addDebug("<{$tag}> {$string}", [__CLASS__]);
    }

    /**
     * Bind socket server
     *
     * @return bool|resource
     */
    protected function bind()
    {
        self::DEBUG_ENABLED && $this->_log("Binding socket...", __FUNCTION__);
        $this->socketServerHandle = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socketServerHandle, SOL_SOCKET, SO_REUSEADDR, 1);
        $timeoutWrite = explode('.', $this->timeoutWrite);
        $timeoutWrite[1] = isset($timeoutWrite[1]) ? (int)$timeoutWrite[1] : 0;
        $timeoutRead = explode('.', $this->timeoutRead);
        $timeoutRead[1] = isset($timeoutRead[1]) ? (int)$timeoutRead[1] : 0;
        socket_set_option($this->socketServerHandle, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => (int)$timeoutWrite[0],
            'usec' => $timeoutWrite[1]
        ]);
        socket_set_option($this->socketServerHandle, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => (int)$timeoutRead,
            'usec' => $timeoutRead[1]
        ]);
        socket_bind($this->socketServerHandle, $this->bind_host, $this->bind_port);
        self::DEBUG_ENABLED && $this->_log("Start listening...", __FUNCTION__);
        socket_listen($this->socketServerHandle);
    }

    private function printStatus()
    {
        static $nextPrint = 0;
        if($nextPrint < time()) {
            $nextPrint = time();
            $bytesProcessed = $this->bytesProcessed / 1024 / 1024;
            $this->bytesProcessed = 0;

            $bytesSent = $this->bytesSent;
            $this->bytesSent = [];

            $string = "<bg=green;options=bold>\n- - - - - - - -\n";
            $string .= sprintf("Clients: %d / Newbies: %d / ProcessSpeed: %s MB/s</bg=green;options=bold>\n<bg=green>",
                count($this->clients),
                count($this->newbies),
                number_format($bytesProcessed, 4)
            );
            foreach($this->clients as $key => $client) {
                $ip = @socket_getpeername($this->clients[$key], $ip, $port) ? "{$ip}:{$port}" : false;
                $string .= sprintf(' %-21s / %s: %s kB/s / %s: %s(%s) / %s: %s / %s: %s / %s: %s',
                    $ip,
                    'Speed',
                    number_format(isset($bytesSent[$key]) ? ($bytesSent[$key] / 1024) : 0, 2),
                    'Queue',
                    number_format(isset($this->queue[$key]) ? count($this->queue[$key]) : 0),
                    number_format($this->queueLimit),
                    'Recovery',
                    number_format(isset($this->recoverQueue[$key]) ? count($this->recoverQueue[$key]) : 0),
                    'Counters',
                    number_format(isset($this->counters[$key]) ? $this->counters[$key] : 0),
                    'Errors',
                    number_format(isset($this->errors[$key]) ? $this->errors[$key] : 0)
                );
            }
            $string .= '</bg=green>';
            if(isset($this->output)) {
                $this->output->writeln($string);
            } else {
                error_log(strip_tags($string));
            }
        }
    }

    public function mainIteration()
    {
        static $cycleCounter = 0, $ongoin = false;
        if($ongoin) {
            return;
        }
        $ongoin = true;
        $this->printStatus();
        $clientsCount = count($this->clients);
        $read = $this->clients;
        if(($clientsCount > 0 && ++$cycleCounter % 30 == 0) || $clientsCount == 0) {
            $cycleCounter = 0;
            $read[] = $this->socketServerHandle;
            $clientsCount++;
            if(self::NEED_AUTH) {
                foreach($this->newbies as $newbie) {
                    $read[] = $newbie;
                    $clientsCount++;
                }
            }
        }
        $write  = $this->clients;
        $except = null;
        if($clientsCount > 0 && $updates = socket_select($read, $write, $except, 0, 0) > 0) {

            $writesCount = count($write);

            self::DEBUG_ENABLED && $this->_log("Received {$updates} updates from sockets", 'main');

            // CHECK CLIENTS
            foreach($read as $client) {
                self::DEBUG_ENABLED && $this->_log('Processing client...', 'check clients');
                $key = array_search($client, $this->clients, true);
                if($key === false) {

                    // NEW CLIENT
                    if($client === $this->socketServerHandle) {
                        self::DEBUG_ENABLED && $this->_log('Hi, newbie!', 'new client');
                        $newClient = socket_accept($this->socketServerHandle);
                        if($newClient === false) {
                            $code = socket_last_error($this->socketServerHandle);
                            socket_clear_error($this->socketServerHandle);
                            self::log()->addDebug("[ERROR] On new client connection: {$code}");
                            continue;
                        }
                        //socket_set_nonblock($newClient);
                        self::NEED_AUTH ? $this->newbies[] = $newClient : $this->clients[] = $newClient;
                        continue;
                    } elseif(false !== ($key = array_search($client, $this->newbies, true))) {

                        self::DEBUG_ENABLED && $this->_log("Newbie[{$key}], you are need to be authorized", 'new client');

                        // REMOVE FROM NEWBIES
                        unset($this->newbies[$key]);

                        // CHECK AUTH
                        $buffer = '';
                        for(;;) {
                            $read = socket_read($client, 4096);
                            if(empty($read)) {
                                break;
                            }
                            $buffer .= $read;
                            if(strlen($read) < 4096) {
                                break;
                            }
                        }
                        $buffer = trim($buffer);

                        // GOOD BYE, UNAUTHORIZED USER
                        if($buffer !== $this->authHash) {
                            self::DEBUG_ENABLED && $this->_log("Newbie[{$key}], auth failed, bye :(", 'new client');
                            socket_write($client, "Auth failed!\n", 13);
                            socket_close($client);
                            unset($this->clients[$key], $this->queue[$key], $this->counters[$key], $this->errors[$key]);
                            continue;
                        }

                        // NOW YOU ARE CONSUMER
                        self::DEBUG_ENABLED && $this->_log("Newbie[{$key}] => Client[], you are in consumers now", 'new client');
                        $this->clients[] = $client;
                    }
                } else {

                    // CHECK DEAD
                    self::DEBUG_ENABLED && $this->_log("Client[{$key}], are you disconnecting?", 'check dead');
                    $buffer = @socket_read($client, 4096);

                    // GOOD BYE, DISCONNECTED USER
                    if(empty($buffer)) {
                        self::DEBUG_ENABLED && $this->_log("Client[{$key}], good bye, see you later :)", 'check dead');
                        socket_close($client);
                        unset($this->clients[$key], $this->queue[$key], $this->counters[$key], $this->errors[$key]);
                        continue;
                    }
                    self::DEBUG_ENABLED && $this->_log("Client[{$key}], are you fucking kidding me? You are said: {$buffer}", 'check dead');
                }
            }

            if($writesCount > 0) {

                // GET DATA
                try {
                    self::DEBUG_ENABLED && $this->_log('Loading data', 'get data');
                    $data = call_user_func($this->getDataArrayFunction);
                    if(!is_array($data)) {
                        self::log()->addDebug('[ERROR] data is not array: ' . var_export($data, true));
                        throw new \Exception('Loaded data is not array!');
                    }
                    self::DEBUG_ENABLED && $this->_log('Loaded ' . count($data) . ' objects', 'get data');
                } catch(\Exception $e) {
                    self::log()->addDebug("Error: {$e->getMessage()}", [$e->getTraceAsString()]);
                    $data = [];
                }

                // CHECK & MAKE QUEUES
                try {

                    // MAKE QUEUES
                    self::DEBUG_ENABLED && $this->_log('Making queues', 'prepare data');
                    while(null !== ($item = array_pop($data))) {
                        $this->json && $item = json_encode($item);
                        $this->gzip && $item = gzcompress($item, 1);
                        $this->base64 && $item = base64_encode($item);
                        $item .= $this->delimiter;
                        foreach($this->clients as $key => $client) {
                            $this->queue[$key][] = $item;
                            isset($this->counters[$key]) ? $this->counters[$key]++ : $this->counters[$key] = 1;
                        }
                    }

                    // CHECK QUEUES
                    self::DEBUG_ENABLED && $this->_log('Checking queues size', 'prepare data');
                    arsort($this->counters, SORT_DESC);
                    foreach($this->counters as $key => $count) {
                        if($count < $this->queueLimit) {
                            break;
                        }
                        if($count > $this->queueLimit) {
                            $this->queue[$key] = array_slice($this->queue[$key], -$this->queueLimit);
                            $this->counters[$key] = $this->queueLimit;
                        }
                    }
                } catch(\Exception $e) {
                    self::log()->addDebug("Error: {$e->getMessage()}", [$e->getTraceAsString()]);
                }

                // SEND DATA
                self::DEBUG_ENABLED && $this->_log('Streaming to clients', 'streaming');
                foreach($write as $client) {
                    try {
                        $key = array_search($client, $this->clients, true);
                        self::DEBUG_ENABLED && $this->_log("Streaming to client {$key}", 'streaming');
                        if(!isset($this->queue[$key])) {
                            continue;
                        }
                        if(!isset($this->bytesSent[$key])) {
                            $this->bytesSent[$key] = 0;
                        }
                        while(isset($this->recoverQueue[$key]) && null !== ($item = array_pop($this->recoverQueue[$key]))) {
                            $itemLen = strlen($item);
                            $bytesWritten = 0;
                            $attempts = 0;
                            do {
                                $this->bytesProcessed += (strlen($item) - $bytesWritten);
                                $writeResult = @socket_write($client, substr($item, $bytesWritten));
                                $this->_log("RECOVER E len:{$itemLen} written:{$bytesWritten} attempts:{$attempts} write_result:" . var_export($writeResult, true), 'streaming:writing');
                                if(!$writeResult) {
                                    if(false === $writeResult) {
                                        $errorCode = socket_last_error($client);
                                        $errorStr = socket_strerror($errorCode);
                                        $this->_log("RECOVER ERROR ({$errorCode}) {$errorStr}", 'socket_write');
                                    }
                                    if(!isset($this->recoverQueue[$key])) {
                                        $this->recoverQueue[$key] = [];
                                    }
                                    $this->recoverQueue[$key][] = substr($item, $bytesWritten);
                                    $this->_log('RECOVER Returning to queue and breaking client', 'socket_write');
                                    isset($this->errors[$key]) ? $this->errors[$key]++ : $this->errors[$key] = 1;
                                    break 2;
                                }
                                $bytesWritten += $writeResult;
                                $attempts++;
                                $this->_log("RECOVER E len:{$itemLen} written:{$bytesWritten} attempts:{$attempts} write_result:" . var_export($writeResult, true), 'streaming:writing');
                            } while($bytesWritten < $itemLen && $attempts <= 30);
                            $this->bytesSent[$key] += $bytesWritten;

                            if($bytesWritten < $itemLen) {
                                $this->_log("RECOVER Return, cuz {$bytesWritten} < {$itemLen}", 'streaming:writing');
                                if(!isset($this->recoverQueue[$key])) {
                                    $this->recoverQueue[$key] = [];
                                }
                                isset($this->errors[$key]) ? $this->errors[$key]++ : $this->errors[$key] = 1;
                                $this->recoverQueue[$key][] = substr($item, $bytesWritten);
                            } else {
                                $this->_log('RECOVER OK', 'streaming:writing');
                                $this->counters[$key]--;
                            }
                        }
                        while(null !== ($item = array_pop($this->queue[$key]))) {
                            $itemLen = strlen($item);
                            $bytesWritten = 0;
                            $attempts = 0;
                            do {
                                $this->bytesProcessed += (strlen($item) - $bytesWritten);
                                $writeResult = @socket_write($client, substr($item, $bytesWritten));
                                self::DEBUG_ENABLED && $this->_log("S len:{$itemLen} written:{$bytesWritten} attempts:{$attempts} write_result:" . var_export($writeResult, true), 'streaming:writing');
                                if(!$writeResult) {
                                    if(false === $writeResult) {
                                        $errorCode = socket_last_error($client);
                                        $errorStr = socket_strerror($errorCode);
                                        $this->_log("ERROR ({$errorCode}) {$errorStr}", 'socket_write');
                                    }
                                    if(!isset($this->recoverQueue[$key])) {
                                        $this->recoverQueue[$key] = [];
                                    }
                                    $this->recoverQueue[$key][] = substr($item, $bytesWritten);
                                    $this->_log('Returning to queue and breaking client', 'socket_write');
                                    isset($this->errors[$key]) ? $this->errors[$key]++ : $this->errors[$key] = 1;
                                    break 2;
                                }
                                $bytesWritten += $writeResult;
                                $attempts++;
                                self::DEBUG_ENABLED && $this->_log("E len:{$itemLen} written:{$bytesWritten} attempts:{$attempts} write_result:" . var_export($writeResult, true), 'streaming:writing');
                            } while($bytesWritten < $itemLen && $attempts <= 30);
                            $this->bytesSent[$key] += $bytesWritten;

                            if($bytesWritten < $itemLen) {
                                $this->_log("Return, cuz {$bytesWritten} < {$itemLen}", 'streaming:writing');
                                if(!isset($this->recoverQueue[$key])) {
                                    $this->recoverQueue[$key] = [];
                                }
                                isset($this->errors[$key]) ? $this->errors[$key]++ : $this->errors[$key] = 1;
                                $this->recoverQueue[$key][] = substr($item, $bytesWritten);
                            } else {
                                self::DEBUG_ENABLED && $this->_log('OK', 'streaming:writing');
                                $this->counters[$key]--;
                            }
                        }
                    } catch(\Exception $e) {
                        self::log()->addDebug("Error: {$e->getMessage()}", [$e->getTraceAsString()]);
                        continue;
                    }
                }
            }
        }
        $ongoin = false;
        $this->onEveryCycle !== null && call_user_func($this->onEveryCycle);
    }

    public function start($startCycle = true)
    {
        self::DEBUG_ENABLED && $this->_log('Starting main cycle', __FUNCTION__);
        $this->bind();
        if(!$startCycle) {
            return;
        }
        for(;;) {
            $this->mainIteration();
        }
    }

    /**
     * @param mixed $output
     */
    public function setOutput($output)
    {
        $this->output = $output;
    }
}