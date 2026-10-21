<?php

declare(strict_types=1);

/*
 * Router pro lokální fixture HTTP server (php -S) v testech kontrol:
 *   /ok              200 "Nordvarr fixture OK"
 *   /status/<kód>    odpověď s daným kódem
 *   /slow?ms=<n>     200 po n ms
 *   /redirect/<n>    řetěz n přesměrování, pak /ok
 *   /big             2 MB textu, na začátku "ZACATEK"
 *   /files/<jméno>   soubor z tests/fixtures/files (RDAP JSON apod.)
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: text/plain; charset=utf-8');

if ($path === '/ok') {
    echo "Nordvarr fixture OK\n";
} elseif (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    echo "status {$m[1]}\n";
} elseif ($path === '/slow') {
    usleep(max(0, (int) ($_GET['ms'] ?? 1000)) * 1000);
    echo "slow OK\n";
} elseif (preg_match('#^/redirect/(\d+)$#', $path, $m) === 1) {
    $n = (int) $m[1];
    header('Location: ' . ($n <= 1 ? '/ok' : '/redirect/' . ($n - 1)), true, 302);
} elseif ($path === '/big') {
    echo 'ZACATEK';
    echo str_repeat('x', 2 * 1024 * 1024);
} elseif (preg_match('#^/files/([a-z0-9_.-]+)$#', $path, $m) === 1 && is_file(__DIR__ . '/files/' . $m[1])) {
    header('Content-Type: application/json');
    readfile(__DIR__ . '/files/' . $m[1]);
} else {
    http_response_code(404);
    echo "not found\n";
}
