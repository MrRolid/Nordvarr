<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(string $action, ?string $userId, ?string $ip, array $details = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (id, user_id, action, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Uuid::v7(),
            $userId,
            $action,
            $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $ip,
            Time::toDb(Time::now()),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY created_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
