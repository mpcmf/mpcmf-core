<?php

namespace mpcmf\system\net;

use mpcmf\system\helper\io\log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * socketServer class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class socketServer
{
    use log;

    const DEBUG_ENABLED = false;

    protected $bind_host = '0.0.0.0';
    protected $bind_port = 9000;
    protected $onEveryCycle;
    protected $everyCycleUsleep;
    protected $timeoutWrite = 5.0;
    protected $timeoutRead = 5.0;
    protected $onClientRequest;
    protected $forgetClient;

    /**
     * @var OutputInterface
     */
    private $output;
    private $clients = [];
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
     * @param null $onEveryCycle
     */
    public function setOnEveryCycle($onEveryCycle)
    {
        $this->onEveryCycle = $onEveryCycle;
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

    public function addAnswer($key, $answer)
    {
        if(!isset($this->queue[$key])) {
            $this->queue[$key] = [
                $answer
            ];
        } else {
            $this->queue[$key][] = $answer;
        }
    }

    public function hasUnsentChunks()
    {
        $has = count($this->clients) > 0;
        if(!$has) {
            foreach ($this->queue as $client) {
                if (count($client) > 0) {
                    $has = true;
                    break;
                }
            }
        }
        if(!$has) {
            foreach($this->recoverQueue as $client) {
                if(count($client) > 0) {
                    $has = true;
                    break;
                }
            }
        }

        return $has;
    }

    /**
     * Bind socket server
     *
     * @return bool|resource
     */
    protected function bind()
    {
        self::DEBUG_ENABLED && $this->_log('Binding socket...', __FUNCTION__);
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
        self::DEBUG_ENABLED && $this->_log('Start listening...', __FUNCTION__);
        socket_listen($this->socketServerHandle);
    }

    private function printStatus()
    {
        $bytesProcessed = $this->bytesProcessed / 1024 / 1024;
        $this->bytesProcessed = 0;

        $bytesSent = $this->bytesSent;
        $this->bytesSent = [];

        $string = "<bg=green;options=bold>\n- - {$this->bind_host}:{$this->bind_port} - -\n";
        $string .= sprintf("Clients: %d / ProcessSpeed: %s MB/s [RAM: %s MB]</bg=green;options=bold>\n<bg=green>",
            count($this->clients),
            number_format($bytesProcessed, 4),
            number_format(memory_get_usage(true) / 1024 / 1024, 2)
        );
        foreach($this->clients as $key => $client) {
            $ip = @socket_getpeername($this->clients[$key], $ip, $port) ? "{$ip}:{$port}" : false;
            $string .= sprintf(" %-21s / %s: %s kB/s / %s: %s(%s) / %s: %s / %s: %s / %s: %s\n",
                $ip,
                'Speed',
                number_format(isset($bytesSent[$key]) ? ($bytesSent[$key] / 1024) : 0, 2),
                'Queue',
                number_format(isset($this->queue[$key]) ? count($this->queue[$key]) : 0),
                number_format(0),
                'Recovery',
                number_format(isset($this->recoverQueue[$key]) ? count($this->recoverQueue[$key]) : 0),
                'Counters',
                number_format(isset($this->counters[$key]) ? $this->counters[$key] : 0),
                'Errors',
                number_format(isset($this->errors[$key]) ? $this->errors[$key] : 0)
            );
        }
        $string .= '</bg=green>';
        if($this->output !== null) {
            $this->output->writeln($string);
        } else {
            error_log(strip_tags($string));
        }
    }

    public function mainIteration()
    {
        static $cycleCounter = 0, $ongoin = false, $nextPrint = 0;

        if($ongoin) {
            return;
        }
        $ongoin = true;

        if($nextPrint < time()) {
            $nextPrint = time();
            $this->printStatus();
        }

        $hasClients = (bool)($write = $read = $this->clients);
        if(!$hasClients || ($hasClients && ++$cycleCounter % 30 == 0)) {
            $read[] = $this->socketServerHandle;
        }
        if($read && $updates = socket_select($read, $write, $except = null, 0, 1) > 0) {

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
                        $this->clients[] = $newClient;
                        continue;
                    }
                } else {
                    // CHECK DEAD
                    socket_set_nonblock($client);
                    self::DEBUG_ENABLED && $this->_log("Client[{$key}], are you alive?", 'read/check dead');
                    $buffer = @socket_read($client, 2);

                    // GOOD BYE, DISCONNECTED USER
                    if(empty($buffer)) {
                        self::DEBUG_ENABLED && $this->_log("Client[{$key}], good bye, see you later :)", 'check dead');
                        socket_close($client);
                        unset($this->clients[$key], $this->queue[$key], $this->counters[$key], $this->errors[$key]);
                        continue;
                    }

                    self::DEBUG_ENABLED && $this->_log("Client[{$key}] said something, reading...", 'read');
                    while($miniBuffer = @socket_read($client, 4096)) {
                        self::DEBUG_ENABLED && $this->_log("Client[{$key}] chunk read: " . json_encode($miniBuffer), 'read');
                        $buffer .= $miniBuffer;
                    }
                    socket_set_block($client);
                    self::DEBUG_ENABLED && $this->_log("Client[{$key}] said something, processing...", 'read');
                    if($this->forgetClient) {
                        $forgetClientCallable = $this->forgetClient;
                        $forgetClientCallable($client, $key, $buffer);
                        unset($this->clients[$key], $this->queue[$key], $this->counters[$key], $this->errors[$key]);
                    } else {
                        $onClientRequest = $this->onClientRequest;
                        $onClientRequest($key, $buffer);
                    }
                }
            }

            if($write) {
                // SEND DATA
                self::DEBUG_ENABLED && $this->_log('Writing to clients', 'streaming');
                foreach($write as $client) {
                    try {
                        $key = array_search($client, $this->clients, true);
                        if(!isset($this->recoverQueue[$key]) && !isset($this->queue[$key])) {
                            continue;
                        }
                        self::DEBUG_ENABLED && $this->_log("Write to client {$key}", 'streaming');
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
                        while(isset($this->queue[$key]) && ($item = array_pop($this->queue[$key])) !== null) {
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
                                isset($this->counters[$key]) && $this->counters[$key]--;
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
            $this->everyCycleUsleep && usleep($this->everyCycleUsleep);
        }
    }

    /**
     * @param mixed $output
     */
    public function setOutput($output)
    {
        $this->output = $output;
    }

    /**
     * @param mixed $onClientRequest
     */
    public function setOnClientRequest($onClientRequest)
    {
        $this->onClientRequest = $onClientRequest;
    }

    /**
     * @param mixed $everyCycleUsleep
     */
    public function setEveryCycleUsleep($everyCycleUsleep)
    {
        $this->everyCycleUsleep = (int)$everyCycleUsleep;
    }

    /**
     * @param callable $forgetClient
     */
    public function setForgetClient($forgetClient)
    {
        $this->forgetClient = $forgetClient;
    }

    /**
     * @return array
     */
    public function getClients()
    {
        return $this->clients;
    }
}