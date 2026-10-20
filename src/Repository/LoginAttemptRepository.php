<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use DateTimeImmutable;
use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $email, string $ip, bool $success, DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (id, email, ip, success, attempted_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([Uuid::v7(), $email, $ip, $success ? 1 : 0, Time::toDb($now)]);
    }

    /**
     * Neúspěšné pokusy od posledního úspěchu, nejnovější nejdřív.
     *
     * @param 'email'|'ip' $column
     * @return list<string> časy pokusů (UTC, ISO 8601)
     */
    public function failuresSinceLastSuccess(string $column, string $value, DateTimeImmutable $since): array
    {
        $col = $column === 'ip' ? 'ip' : 'email';
        $sinceDb = Time::toDb($since);
        $stmt = $this->pdo->prepare(
            "SELECT attempted_at FROM login_attempts
             WHERE $col = ? AND success = 0 AND attempted_at >= ?
               AND attempted_at > COALESCE(
                   (SELECT MAX(attempted_at) FROM login_attempts WHERE $col = ? AND success = 1), '')
             ORDER BY attempted_at DESC"
        );
        $stmt->execute([$value, $sinceDb, $value]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function purgeOlderThan(DateTimeImmutable $before): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $stmt->execute([Time::toDb($before)]);
    }
}
