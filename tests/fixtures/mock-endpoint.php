<?php

declare(strict_types=1);

/*
 * Router pro lokální mock endpoint (php -S) Telegramu a webhooků. Každý požadavek
 * uloží jako JSON do adresáře z proměnné prostředí NORDVARR_MOCK_DIR.
 *   /bot<token>/sendMessage   token "000000:BAD..." vrací chybu 401, jinak {"ok":true}
 *   /hook/ok                  200
 *   /hook/fail                500
 */

$dir = (string) getenv('NORDVARR_MOCK_DIR');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
    }
}
$headers['content-type'] = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
if ($dir !== '') {
    file_put_contents($dir . '/req-' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.json', json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'path' => $path,
        'headers' => $headers,
        'body' => $body,
    ]));
}
header('Content-Type: application/json');
if (preg_match('#^/bot([^/]+)/sendMessage$#', $path, $m) === 1) {
    if (str_contains($m[1], 'BAD')) {
        http_response_code(401);
        echo '{"ok":false,"error_code":401,"description":"Unauthorized"}';
    } else {
        echo '{"ok":true,"result":{"message_id":1}}';
    }
} elseif ($path === '/hook/ok') {
    echo '{"received":true}';
} elseif ($path === '/hook/fail') {
    http_response_code(500);
    echo '{"error":"fail"}';
} else {
    http_response_code(404);
    echo '{}';
}
