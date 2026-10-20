<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use PDO;

final class HttpCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $url, string $notBefore): ?string
    {
        $stmt = $this->pdo->prepare('SELECT body FROM http_cache WHERE url = ? AND fetched_at >= ?');
        $stmt->execute([$url, $notBefore]);
        $body = $stmt->fetchColumn();

        return $body === false ? null : (string) $body;
    }

    public function put(string $url, string $body, string $fetchedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO http_cache (url, fetched_at, body) VALUES (?, ?, ?)
             ON CONFLICT (url) DO UPDATE SET fetched_at = excluded.fetched_at, body = excluded.body'
        );
        $stmt->execute([$url, $fetchedAt, $body]);
    }
}
