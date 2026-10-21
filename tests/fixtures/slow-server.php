<?php

declare(strict_types=1);

/*
 * Pomalý HTTP server pro test paralelismu: každé spojení obslouží (200 OK) až po
 * zadané prodlevě, všechna spojení současně v jednom procesu (stream_select).
 *   php tests/fixtures/slow-server.php <port> <prodleva_ms>
 */

[$_, $port, $delayMs] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    fwrite(STDERR, "Server nelze spustit: $error\n");
    exit(1);
}
stream_set_blocking($server, false);
$clients = [];
while (true) {
    $read = [$server];
    foreach ($clients as $c) {
        $read[] = $c['socket'];
    }
    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 0, 20000);
    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = ['socket' => $client, 'since' => microtime(true), 'request' => ''];
            }
        } else {
            $chunk = fread($socket, 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($socket)) {
                    fclose($socket);
                    unset($clients[(int) $socket]);
                }
                continue;
            }
            $clients[(int) $socket]['request'] .= $chunk;
        }
    }
    foreach ($clients as $id => $c) {
        if (str_contains($c['request'], "\r\n\r\n") && microtime(true) - $c['since'] >= ((int) $delayMs) / 1000) {
            stream_set_blocking($c['socket'], true);
            fwrite($c['socket'], "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 8\r\nConnection: close\r\n\r\nslow OK\n");
            fclose($c['socket']);
            unset($clients[$id]);
        }
    }
}
