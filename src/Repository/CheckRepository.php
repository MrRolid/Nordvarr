<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Kontroly a jejich aktuální stav (check_state).
 */
final class CheckRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{name: string, interval_s: int, timeout_s: int, failure_threshold: int, enabled: bool, notify_on_degraded: bool} $common
     * @param array<string, mixed> $config
     */
    public function create(string $objectId, string $type, array $common, array $config, ?string $id = null, ?string $heartbeatToken = null): string
    {
        $id ??= Uuid::v7();
        $now = Time::toDb(Time::now());
        if ($type === 'heartbeat') {
            $heartbeatToken ??= bin2hex(random_bytes(16));
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO checks (id, object_id, type, name, config, interval_s, timeout_s, failure_threshold, enabled,
                notify_on_degraded, heartbeat_token, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $objectId, $type, $common['name'], self::encode($config), $common['interval_s'], $common['timeout_s'],
            $common['failure_threshold'], $common['enabled'] ? 1 : 0, $common['notify_on_degraded'] ? 1 : 0,
            $type === 'heartbeat' ? $heartbeatToken : null, $now, $now,
        ]);
        $this->pdo->prepare(
            "INSERT INTO check_state (check_id, status, since, next_run_at) VALUES (?, 'unknown', ?, ?)"
        )->execute([$id, $now, $now]);

        return $id;
    }

    /**
     * @param array{name: string, interval_s: int, timeout_s: int, failure_threshold: int, enabled: bool, notify_on_degraded: bool} $common
     * @param array<string, mixed> $config
     */
    public function update(string $id, array $common, array $config): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE checks SET name = ?, config = ?, interval_s = ?, timeout_s = ?, failure_threshold = ?, enabled = ?,
                notify_on_degraded = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $common['name'], self::encode($config), $common['interval_s'], $common['timeout_s'], $common['failure_threshold'],
            $common['enabled'] ? 1 : 0, $common['notify_on_degraded'] ? 1 : 0, Time::toDb(Time::now()), $id,
        ]);
        // Po změně konfigurace se kontrola spustí co nejdřív.
        $this->scheduleNow($id);
    }

    /**
     * Obnovení kontroly z exportu (s původním id a časy). Heartbeat dostane nový token.
     *
     * @param array<string, mixed> $row
     */
    public function restore(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO checks (id, object_id, type, name, config, interval_s, timeout_s, failure_threshold, enabled,
                notify_on_degraded, heartbeat_token, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['id'], $row['object_id'], $row['type'], (string) ($row['name'] ?? ''), self::encode(is_array($row['config'] ?? null) ? $row['config'] : []),
            (int) $row['interval_s'], (int) $row['timeout_s'], (int) $row['failure_threshold'], !empty($row['enabled']) ? 1 : 0,
            !empty($row['notify_on_degraded']) ? 1 : 0, $row['type'] === 'heartbeat' ? bin2hex(random_bytes(16)) : null,
            $row['created_at'], $row['updated_at'],
        ]);
        $now = Time::toDb(Time::now());
        $this->pdo->prepare("INSERT INTO check_state (check_id, status, since, next_run_at) VALUES (?, 'unknown', ?, ?)")
            ->execute([$row['id'], $now, $now]);
    }

    public function scheduleNow(string $id): void
    {
        $this->pdo->prepare('UPDATE check_state SET next_run_at = ? WHERE check_id = ?')->execute([Time::toDb(Time::now()), $id]);
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->pdo->prepare('UPDATE checks SET enabled = ?, updated_at = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, Time::toDb(Time::now()), $id]);
        if ($enabled) {
            $this->scheduleNow($id);
        }
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM checks WHERE id = ?')->execute([$id]);
    }

    /**
     * Kontrola spolu se stavem a údaji o objektu.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::decode($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHeartbeatToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.heartbeat_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : self::decode($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forObject(string $objectId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.object_id = ? ORDER BY c.created_at, c.id');
        $stmt->execute([$objectId]);

        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    /**
     * Všechny kontroly se stavem (pro přehled a výpočet stavu objektů).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map([self::class, 'decode'], $this->pdo->query(self::SELECT . ' ORDER BY c.created_at, c.id')->fetchAll());
    }

    /**
     * Kontroly, které jsou na řadě: zapnuté, u aktivního objektu, next_run_at <= now.
     *
     * @return list<array<string, mixed>>
     */
    public function due(string $now, int $limit = 1000): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE c.enabled = 1 AND o.lifecycle = 'active' AND (s.next_run_at IS NULL OR s.next_run_at <= ?)
             ORDER BY s.next_run_at, c.id LIMIT " . max(1, $limit)
        );
        $stmt->execute([$now]);

        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    public function setNextRun(string $id, string $nextRunAt): void
    {
        $this->pdo->prepare('UPDATE check_state SET next_run_at = ? WHERE check_id = ?')->execute([$nextRunAt, $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM check_state WHERE check_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? ['check_id' => $id, 'status' => 'unknown', 'since' => Time::toDb(Time::now()), 'consecutive_failures' => 0] : $row;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(string $id, array $state): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE check_state SET status = ?, reason_code = ?, reason_detail = ?, since = ?, last_run_at = ?,
                consecutive_failures = ?, last_result_at = ?, last_result_status = ?, last_latency_ms = ?, last_ping_at = ?,
                expires_at = ? WHERE check_id = ?'
        );
        $stmt->execute([
            $state['status'], $state['reason_code'] ?? null, (string) ($state['reason_detail'] ?? ''), $state['since'],
            $state['last_run_at'] ?? null, (int) ($state['consecutive_failures'] ?? 0), $state['last_result_at'] ?? null,
            $state['last_result_status'] ?? null, $state['last_latency_ms'] ?? null, $state['last_ping_at'] ?? null,
            $state['expires_at'] ?? null, $id,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function encode(array $config): string
    {
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $config = json_decode((string) $row['config'], true);
        $row['config'] = is_array($config) ? $config : [];
        $row['enabled'] = (bool) $row['enabled'];
        $row['notify_on_degraded'] = (bool) $row['notify_on_degraded'];

        return $row;
    }

    private const SELECT = 'SELECT c.*, s.status, s.reason_code, s.reason_detail, s.since, s.last_run_at, s.next_run_at,
            s.consecutive_failures, s.last_result_at, s.last_result_status, s.last_latency_ms, s.last_ping_at, s.expires_at,
            o.name AS object_name, o.type AS object_type, o.lifecycle AS object_lifecycle
        FROM checks c JOIN check_state s ON s.check_id = c.id JOIN objects o ON o.id = c.object_id';
}
