<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Uuid;
use PDO;

final class AlertRepository
{
    private const SELECT = 'SELECT a.*, o.name AS object_name, o.type AS object_type, p.name AS cause_name
        FROM alerts a JOIN objects o ON o.id = a.object_id LEFT JOIN objects p ON p.id = a.probable_cause_object_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $check řádek kontroly se stavem
     */
    public function open(array $check, string $level, string $now): string
    {
        $id = Uuid::v7();
        $stmt = $this->pdo->prepare(
            "INSERT INTO alerts (id, check_id, object_id, status, level, check_type, check_name, reason_code, reason_detail, opened_at)
             VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $check['id'], $check['object_id'], $level, $check['type'], (string) $check['name'],
            $check['reason_code'] ?? null, (string) ($check['reason_detail'] ?? ''), $now]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE a.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Otevřené alerty seřazené podle otevření.
     *
     * @return list<array<string, mixed>>
     */
    public function openAlerts(): array
    {
        return $this->pdo->query(self::SELECT . " WHERE a.status = 'open' ORDER BY a.opened_at, a.id")->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openForObject(string $objectId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . " WHERE a.status = 'open' AND a.object_id = ? ORDER BY a.opened_at, a.id");
        $stmt->execute([$objectId]);

        return $stmt->fetchAll();
    }

    public function countForObject(string $objectId, string $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM alerts WHERE object_id = ? AND opened_at >= ?');
        $stmt->execute([$objectId, $since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param 'open'|'resolved'|'' $status
     * @return list<array<string, mixed>>
     */
    public function search(string $status, string $q = '', int $limit = 200): array
    {
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = "(lower(o.name) LIKE ? ESCAPE '\\' OR a.reason_code LIKE ? ESCAPE '\\')";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
            array_push($params, $like, $like);
        }
        $order = $status === 'open' ? 'a.opened_at DESC' : 'COALESCE(a.resolved_at, a.opened_at) DESC';
        $stmt = $this->pdo->prepare(self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . " ORDER BY $order, a.id DESC LIMIT " . max(1, $limit));
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function updateSnapshot(string $id, string $level, ?string $reasonCode, string $reasonDetail): void
    {
        $this->pdo->prepare('UPDATE alerts SET level = ?, reason_code = ?, reason_detail = ? WHERE id = ?')
            ->execute([$level, $reasonCode, $reasonDetail, $id]);
    }

    public function resolve(string $id, string $now, string $reason): void
    {
        $this->pdo->prepare("UPDATE alerts SET status = 'resolved', resolved_at = ?, resolved_reason = ? WHERE id = ? AND status = 'open'")
            ->execute([$now, $reason, $id]);
    }

    public function setNotified(string $id, string $now): void
    {
        $this->pdo->prepare('UPDATE alerts SET last_notified_at = ? WHERE id = ?')->execute([$now, $id]);
    }

    public function setSuppression(string $id, ?string $byAlertId, ?string $causeObjectId): void
    {
        $this->pdo->prepare('UPDATE alerts SET suppressed_by_alert_id = ?, probable_cause_object_id = ? WHERE id = ?')
            ->execute([$byAlertId, $causeObjectId, $id]);
    }

    public function clearSuppressedBy(string $id): void
    {
        $this->pdo->prepare('UPDATE alerts SET suppressed_by_alert_id = NULL WHERE id = ?')->execute([$id]);
    }

    public function setMaintenanceSuppressed(string $id, bool $value): void
    {
        $this->pdo->prepare('UPDATE alerts SET maintenance_suppressed = ? WHERE id = ?')->execute([$value ? 1 : 0, $id]);
    }
}
