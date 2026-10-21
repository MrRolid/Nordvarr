<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Notify\Notification;
use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Odchozí fronta notifikací a historie pokusů o doručení.
 */
final class DeliveryRepository
{
    private const SELECT = 'SELECT d.*, c.name AS channel_name, c.type AS channel_type FROM deliveries d JOIN channels c ON c.id = d.channel_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(string $channelId, ?string $alertId, Notification $notification, string $now): string
    {
        $id = Uuid::v7();
        $stmt = $this->pdo->prepare(
            "INSERT INTO deliveries (id, channel_id, alert_id, kind, subject, body, payload, status, attempts, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?)"
        );
        $stmt->execute([
            $id, $channelId, $alertId, $notification->kind, $notification->subject, $notification->text,
            json_encode($notification->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $now, $now,
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function due(string $now, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE d.status = 'pending' AND d.next_attempt_at <= ? ORDER BY d.next_attempt_at, d.id LIMIT " . max(1, $limit));
        $stmt->execute([$now]);

        return $stmt->fetchAll();
    }

    public function recordAttempt(string $id, bool $ok, string $error, string $now, ?string $nextAttemptAt): void
    {
        $this->pdo->prepare('INSERT INTO delivery_attempts (delivery_id, attempted_at, ok, error) VALUES (?, ?, ?, ?)')
            ->execute([$id, $now, $ok ? 1 : 0, mb_substr($error, 0, 1000)]);
        if ($ok) {
            $this->pdo->prepare("UPDATE deliveries SET status = 'sent', attempts = attempts + 1, sent_at = ?, next_attempt_at = NULL, last_error = '' WHERE id = ?")
                ->execute([$now, $id]);

            return;
        }
        $this->pdo->prepare('UPDATE deliveries SET status = ?, attempts = attempts + 1, next_attempt_at = ?, last_error = ? WHERE id = ?')
            ->execute([$nextAttemptAt === null ? 'failed' : 'pending', $nextAttemptAt, mb_substr($error, 0, 1000), $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attempts(string $deliveryId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM delivery_attempts WHERE delivery_id = ? ORDER BY attempted_at, id');
        $stmt->execute([$deliveryId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAlert(string $alertId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.alert_id = ? ORDER BY d.created_at, d.id');
        $stmt->execute([$alertId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>> nejnovější první
     */
    public function recent(?string $channelId = null, int $limit = 100): array
    {
        $sql = self::SELECT . ($channelId === null ? '' : ' WHERE d.channel_id = ?') . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($channelId === null ? [] : [$channelId]);

        return $stmt->fetchAll();
    }

    public function purgeOlderThan(string $before): void
    {
        $this->pdo->prepare("DELETE FROM deliveries WHERE status <> 'pending' AND created_at < ?")->execute([$before]);
    }

    public static function now(): string
    {
        return Time::toDb(Time::now());
    }
}
