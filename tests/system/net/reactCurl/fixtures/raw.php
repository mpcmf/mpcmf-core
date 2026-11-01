<?php
// Raw TCP fixture: port+0 accepts and closes (empty reply), port+1 accepts and never answers,
// port+2 sends a truncated body and closes.
$base = (int)($argv[1] ?? 19001);
$servers = [];
foreach ([0, 1, 2] as $shift) {
    $socket = stream_socket_server('tcp://127.0.0.1:' . ($base + $shift), $errno, $error);
    if ($socket === false) {
        fwrite(STDERR, "raw.php: cannot listen on " . ($base + $shift) . ": {$error}\n");
        exit(1);
    }
    stream_set_blocking($socket, false);
    $servers[(int)$socket] = [$socket, $shift];
}
$hold = [];
while (true) {
    $read = array_map(function ($server) { return $server[0]; }, $servers);
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) < 1) {
        continue;
    }
    foreach ($read as $socket) {
        $shift = $servers[(int)$socket][1];
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
        } else {
            usleep(1000);
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 1000\r\n\r\n0123456789");
            fclose($client);
        }
    }
}
