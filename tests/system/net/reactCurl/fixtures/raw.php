<?php
// Raw TCP fixture: port+0 closes, port+1 never answers, port+2 truncates the body,
// port+3 answers GET /?s=<seconds> after s seconds with the accept time
$base = (int)($argv[1] ?? 19001);
$servers = [];
foreach ([0, 1, 2, 3] as $shift) {
    $socket = stream_socket_server('tcp://127.0.0.1:' . ($base + $shift), $errno, $error);
    if ($socket === false) {
        fwrite(STDERR, "raw.php: cannot listen on " . ($base + $shift) . ": {$error}\n");
        exit(1);
    }
    stream_set_blocking($socket, false);
    $servers[(int)$socket] = [$socket, $shift];
}
$hold = [];
// port+3 connections: id => [socket, accepted at, buffer, answer at|null]
$timed = [];
while (true) {
    $read = array_map(function ($server) { return $server[0]; }, $servers);
    $timeout = 1.0;
    $now = microtime(true);
    foreach ($timed as $id => $connection) {
        if ($connection[3] === null) {
            $read[] = $connection[0];
        } else {
            $timeout = min($timeout, max(0.0, $connection[3] - $now));
        }
    }
    $write = $except = null;
    $ready = @stream_select($read, $write, $except, (int)$timeout, (int)(($timeout - (int)$timeout) * 1000000));
    $now = microtime(true);
    foreach ($ready > 0 ? $read : [] as $socket) {
        $id = (int)$socket;
        if (isset($timed[$id])) {
            $chunk = fread($socket, 65536);
            if ($chunk === '' || $chunk === false) {
                fclose($socket);
                unset($timed[$id]);
                continue;
            }
            $timed[$id][2] .= $chunk;
            if (strpos($timed[$id][2], "\r\n\r\n") !== false) {
                $target = explode(' ', $timed[$id][2], 3)[1] ?? '/';
                parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
                $timed[$id][3] = $timed[$id][1] + (float)($query['s'] ?? 0);
            }
            continue;
        }
        $shift = $servers[$id][1];
        $client = @stream_socket_accept($socket, 0);
        if (!$client) {
            continue;
        }
        if ($shift === 0) {
            fclose($client);
        } elseif ($shift === 1) {
            $hold[] = $client;
            if (count($hold) > 5000) {
                array_shift($hold);
            }
        } elseif ($shift === 2) {
            usleep(1000);
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 1000\r\n\r\n0123456789");
            fclose($client);
        } else {
            stream_set_blocking($client, false);
            $timed[(int)$client] = [$client, $now, '', null];
        }
    }
    foreach ($timed as $id => $connection) {
        if ($connection[3] !== null && $connection[3] <= $now) {
            $body = sprintf('%.6f', $connection[1]);
            stream_set_blocking($connection[0], true);
            @fwrite($connection[0], "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body)
                . "\r\nConnection: close\r\n\r\n" . $body);
            fclose($connection[0]);
            unset($timed[$id]);
        }
    }
}
