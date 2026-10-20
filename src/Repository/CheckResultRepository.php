<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\Status;
use PDO;

/**
 * Syrové výsledky kontrol a hodinové agregace.
 */
final class CheckResultRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $checkId, CheckResult $result): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO check_results (check_id, started_at, finished_at, status, reason_code, reason_detail, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$checkId, $result->startedAt, $result->finishedAt, $result->status, $result->reasonCode, $result->detail, $result->latencyMs]);
    }

    /**
     * @return list<array<string, mixed>> nejnovější první
     */
    public function recent(string $checkId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM check_results WHERE check_id = ? ORDER BY started_at DESC, id DESC LIMIT ' . max(1, $limit));
        $stmt->execute([$checkId]);

        return $stmt->fetchAll();
    }

    /**
     * Přepočítá hodinové agregace pro hodiny začínající v intervalu [from, to).
     * Selháním je každý výsledek jiný než healthy.
     */
    public function aggregate(string $fromHour, string $toHour): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO check_hourly (check_id, hour, count, failures, avg_latency_ms, max_latency_ms)
             SELECT check_id, substr(started_at, 1, 13) || ':00:00Z' AS hour, COUNT(*),
                    SUM(CASE WHEN status = ? THEN 0 ELSE 1 END),
                    CAST(ROUND(AVG(latency_ms)) AS INTEGER), MAX(latency_ms)
             FROM check_results
             WHERE started_at >= ? AND started_at < ?
             GROUP BY check_id, hour
             ON CONFLICT (check_id, hour) DO UPDATE SET count = excluded.count, failures = excluded.failures,
                avg_latency_ms = excluded.avg_latency_ms, max_latency_ms = excluded.max_latency_ms"
        );
        $stmt->execute([Status::HEALTHY, $fromHour, $toHour]);
    }

    /**
     * @return list<array<string, mixed>> nejnovější první
     */
    public function hourly(string $checkId, string $sinceHour): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM check_hourly WHERE check_id = ? AND hour >= ? ORDER BY hour DESC');
        $stmt->execute([$checkId, $sinceHour]);

        return $stmt->fetchAll();
    }

    /**
     * Souhrn z agregací za období (počet, selhání, průměrná latence) pro kontrolu.
     *
     * @return array{count: int, failures: int, avg_latency_ms: ?int}
     */
    public function summary(string $checkId, string $sinceHour): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(count), 0) AS count, COALESCE(SUM(failures), 0) AS failures,
                    CAST(ROUND(SUM(avg_latency_ms * count) * 1.0 / NULLIF(SUM(CASE WHEN avg_latency_ms IS NULL THEN 0 ELSE count END), 0)) AS INTEGER) AS avg_latency_ms
             FROM check_hourly WHERE check_id = ? AND hour >= ?'
        );
        $stmt->execute([$checkId, $sinceHour]);
        $row = $stmt->fetch();

        return [
            'count' => (int) $row['count'],
            'failures' => (int) $row['failures'],
            'avg_latency_ms' => $row['avg_latency_ms'] === null ? null : (int) $row['avg_latency_ms'],
        ];
    }

    public function purgeResults(string $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM check_results WHERE started_at < ?');
        $stmt->execute([$before]);

        return $stmt->rowCount();
    }

    public function purgeHourly(string $beforeHour): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM check_hourly WHERE hour < ?');
        $stmt->execute([$beforeHour]);

        return $stmt->rowCount();
    }
}
