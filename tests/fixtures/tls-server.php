<?php

declare(strict_types=1);

/*
 * Lokální TLS server pro testy kontroly tls_expiry:
 *   php tests/fixtures/tls-server.php <port> <cert.pem> <key.pem>
 * Přijme spojení, dokončí TLS handshake a spojení zavře.
 */

[$_, $port, $cert, $key] = $argv;
$context = stream_context_create(['ssl' => [
    'local_cert' => $cert,
    'local_pk' => $key,
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = stream_socket_server('tls://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "TLS server nelze spustit: $error\n");
    exit(1);
}
fwrite(STDOUT, "ready\n");
while (true) {
    $client = @stream_socket_accept($server, 60);
    if ($client !== false) {
        fclose($client);
    }
}
