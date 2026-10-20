<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use PDO;

/**
 * GET|POST /hb/{token}: signál od cron úlohy nebo zálohy. Bez další autentizace,
 * token v adrese je jediný klíč. Bez session a cookies.
 */
final class HeartbeatEndpoint
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function matches(string $path): bool
    {
        return str_starts_with($path, '/hb/');
    }

    public function handle(Request $request): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'];
        if (!in_array($request->method, ['GET', 'POST', 'HEAD'], true)) {
            return new Response("method not allowed\n", 405, $headers + ['Allow' => 'GET, POST']);
        }
        if (preg_match('#^/hb/([0-9a-f]{32})$#', $request->path, $m) !== 1) {
            return new Response("not found\n", 404, $headers);
        }
        $service = new CheckService($this->pdo);
        $check = $service->checks->findByHeartbeatToken($m[1]);
        if ($check === null || $check['type'] !== 'heartbeat') {
            return new Response("not found\n", 404, $headers);
        }
        $service->heartbeat($check);

        return new Response("OK\n", 200, $headers);
    }
}
