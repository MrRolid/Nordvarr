<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use PDO;

/**
 * Data hostového agenta: poslední hlášení serveru, vzorky (48 h) a hodinové agregace (400 dní).
 */
final class AgentRepository
{
    public const SAMPLE_COLUMNS = ['cpu_pct', 'mem_pct', 'swap_pct', 'load1', 'disk_max_pct', 'temp_max_c', 'gpu_util_max_pct',
        'gpu_temp_max_c', 'net_rx_bps', 'net_tx_bps'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null s dekódovaným snapshotem a SMART
     */
    public function host(string $objectId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_hosts WHERE object_id = ?');
        $stmt->execute([$objectId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::decode($row);
    }

    /**
     * @param list<string> $objectIds
     * @return array<string, array<string, mixed>>
     */
    public function hosts(array $objectIds): array
    {
        if ($objectIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($objectIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM agent_hosts WHERE object_id IN ($placeholders)");
        $stmt->execute(array_values($objectIds));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['object_id']] = self::decode($row);
        }

        return $result;
    }

    /**
     * Uloží poslední hlášení. SMART se přepíše jen tehdy, když ho hlášení obsahuje.
     *
     * @param array<string, mixed> $snapshot
     * @param list<array<string, mixed>>|null $smart
     */
    public function saveReport(string $objectId, array $snapshot, ?array $smart, string $smartError, string $at): void
    {
        $json = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_hosts (object_id, hostname, agent_version, last_report_at, snapshot, smart, smart_at, smart_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (object_id) DO UPDATE SET hostname = excluded.hostname, agent_version = excluded.agent_version,
                 last_report_at = excluded.last_report_at, snapshot = excluded.snapshot,
                 smart = CASE WHEN ? THEN excluded.smart ELSE agent_hosts.smart END,
                 smart_at = CASE WHEN ? THEN excluded.smart_at ELSE agent_hosts.smart_at END,
                 smart_error = excluded.smart_error'
        );
        $hasSmart = $smart !== null ? 1 : 0;
        $stmt->execute([
            $objectId, $snapshot['hostname'], $snapshot['agent_version'], $at, $json($snapshot), $json($smart ?? []),
            $smart !== null ? $at : null, $smartError, $hasSmart, $hasSmart,
        ]);
    }

    /**
     * @param array<string, float|null> $sample
     */
    public function insertSample(string $objectId, string $at, array $sample): void
    {
        $columns = implode(', ', self::SAMPLE_COLUMNS);
        $placeholders = implode(', ', array_fill(0, count(self::SAMPLE_COLUMNS) + 2, '?'));
        $values = array_map(static fn (string $c): ?float => $sample[$c] ?? null, self::SAMPLE_COLUMNS);
        $this->pdo->prepare("INSERT OR REPLACE INTO agent_samples (object_id, at, $columns) VALUES ($placeholders)")
            ->execute([$objectId, $at, ...$values]);
    }

    /**
     * Vzorky od daného času, nejstarší první.
     *
     * @return list<array<string, mixed>>
     */
    public function samples(string $objectId, string $since): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_samples WHERE object_id = ? AND at >= ? ORDER BY at');
        $stmt->execute([$objectId, $since]);

        return $stmt->fetchAll();
    }

    /**
     * Přepočítá hodinové agregace pro hodiny od $fromHour (včetně).
     */
    public function aggregate(string $fromHour): void
    {
        $this->pdo->prepare(
            "INSERT OR REPLACE INTO agent_hourly (object_id, hour, count, cpu_avg, cpu_max, mem_avg, mem_max, disk_max, temp_max,
                gpu_util_avg, gpu_util_max, gpu_temp_max, net_rx_avg, net_tx_avg)
             SELECT object_id, substr(at, 1, 13) || ':00:00Z', COUNT(*), AVG(cpu_pct), MAX(cpu_pct), AVG(mem_pct), MAX(mem_pct),
                MAX(disk_max_pct), MAX(temp_max_c), AVG(gpu_util_max_pct), MAX(gpu_util_max_pct), MAX(gpu_temp_max_c),
                AVG(net_rx_bps), AVG(net_tx_bps)
             FROM agent_samples WHERE at >= ? GROUP BY object_id, substr(at, 1, 13)"
        )->execute([$fromHour]);
    }

    /**
     * @return list<array<string, mixed>> nejstarší první
     */
    public function hourly(string $objectId, string $sinceHour): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_hourly WHERE object_id = ? AND hour >= ? ORDER BY hour');
        $stmt->execute([$objectId, $sinceHour]);

        return $stmt->fetchAll();
    }

    public function purgeSamples(string $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM agent_samples WHERE at < ?');
        $stmt->execute([$before]);

        return $stmt->rowCount();
    }

    public function purgeHourly(string $beforeHour): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM agent_hourly WHERE hour < ?');
        $stmt->execute([$beforeHour]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $row['snapshot'] = json_decode((string) $row['snapshot'], true) ?: [];
        $row['smart'] = json_decode((string) $row['smart'], true) ?: [];

        return $row;
    }
}
