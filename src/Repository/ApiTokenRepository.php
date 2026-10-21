<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class ApiTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $scopes
     */
    public function create(string $name, string $prefix, string $hash, string $source, array $scopes, ?string $createdBy): string
    {
        $id = Uuid::v7();
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (id, name, prefix, hash, source, scopes, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $name, $prefix, $hash, $source, implode(' ', $scopes), Time::toDb(Time::now()), $createdBy]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPrefix(string $prefix): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_tokens WHERE prefix = ?');
        $stmt->execute([$prefix]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, name, prefix, source, scopes, created_at, last_used_at, revoked_at FROM api_tokens
             ORDER BY revoked_at IS NOT NULL, created_at DESC'
        )->fetchAll();
    }

    public function revoke(string $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([Time::toDb(Time::now()), $id]);

        return $stmt->rowCount() > 0;
    }

    public function touch(string $id): void
    {
        $this->pdo->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([Time::toDb(Time::now()), $id]);
    }

    /**
     * Započítá požadavek do minutového okna a vrátí počet požadavků v okně.
     */
    public function hit(string $tokenId, string $window): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 1)
             ON CONFLICT (token_id, window_start) DO UPDATE SET count = count + 1'
        );
        $stmt->execute([$tokenId, $window]);
        $this->pdo->prepare('DELETE FROM api_rate_limits WHERE token_id = ? AND window_start < ?')->execute([$tokenId, $window]);
        $count = $this->pdo->prepare('SELECT count FROM api_rate_limits WHERE token_id = ? AND window_start = ?');
        $count->execute([$tokenId, $window]);

        return (int) $count->fetchColumn();
    }
}
