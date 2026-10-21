<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use DateTimeImmutable;
use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([self::normalizeEmail($email)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function create(string $email, string $passwordHash, string $role, ?DateTimeImmutable $now = null): string
    {
        $id = Uuid::v7();
        $time = Time::toDb($now ?? Time::now());
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, email, password_hash, role, session_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$id, self::normalizeEmail($email), $passwordHash, $role, $time, $time]);

        return $id;
    }

    /**
     * Změna hesla zvýší session_version, čímž zneplatní všechny existující session.
     */
    public function updatePassword(string $id, string $passwordHash, ?DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$passwordHash, Time::toDb($now ?? Time::now()), $id]);
    }

    public function touchLogin(string $id, ?DateTimeImmutable $now = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([Time::toDb($now ?? Time::now()), $id]);
    }
}
