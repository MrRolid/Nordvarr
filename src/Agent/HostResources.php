<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use Nordvarr\Time;

/**
 * Stav prostředků serveru s agentem pro mapu topologie: CPU, RAM, nejplnější disk
 * (a GPU) s úrovní podle prahů kontroly agenta.
 *
 * Úrovně: ok, warn (blíží se prahu), crit (překročen práh kontroly), none (bez hodnocení, GPU).
 * U CPU a RAM začíná "warn" 15 bodů pod prahem, u disku je "warn" práh pro omezeně
 * a "crit" práh pro nefunguje.
 */
final class HostResources
{
    public const WARN_MARGIN = 15;

    /**
     * @param array<string, mixed> $host řádek agent_hosts (AgentRepository::hosts)
     * @param array<string, mixed> $config konfigurace kontroly agent (prázdná = výchozí prahy)
     * @return array{stale: bool, items: list<array{key: string, pct: int, level: string, detail: string}>}
     */
    public static function evaluate(array $host, array $config, ?string $now = null): array
    {
        $c = $config + HostEvaluator::DEFAULTS;
        $snapshot = (array) $host['snapshot'];
        $items = [];
        if ($snapshot !== []) {
            $sample = Report::sample($snapshot);
            foreach (['cpu' => ['cpu_pct', 'cpu_pct'], 'mem' => ['mem_pct', 'mem_pct']] as $key => [$field, $limit]) {
                if ($sample[$field] !== null) {
                    $items[] = self::item($key, (float) $sample[$field], (float) $c[$limit] - self::WARN_MARGIN, (float) $c[$limit], '');
                }
            }
            $disk = self::fullestDisk((array) ($snapshot['disks'] ?? []));
            if ($disk !== null) {
                $items[] = self::item('disk', $disk['pct'], (float) $c['disk_degraded_pct'], (float) $c['disk_unhealthy_pct'], $disk['detail']);
            }
            if ($sample['gpu_util_max_pct'] !== null) {
                $items[] = ['key' => 'gpu', 'pct' => (int) round((float) $sample['gpu_util_max_pct']), 'level' => 'none', 'detail' => ''];
            }
        }
        $now ??= Time::toDb(Time::now());
        $age = Time::fromDb($now)->getTimestamp() - Time::fromDb((string) $host['last_report_at'])->getTimestamp();

        return ['stale' => $age > (int) $c['grace_s'], 'items' => $items];
    }

    /** Kolik posledních vzorků (minut) má graf na stěně kostky. */
    public const HISTORY = 30;

    /**
     * Podrobnosti pro kostku serveru ve 3D: disky (nejplnější první), síť, load a průběh
     * CPU, RAM a sítě z posledních vzorků.
     *
     * @param array<string, mixed> $host řádek agent_hosts
     * @param array<string, mixed> $config konfigurace kontroly agent
     * @param list<array<string, mixed>> $samples vzorky agent_samples, nejstarší první
     * @return array<string, mixed>
     */
    public static function details(array $host, array $config, array $samples): array
    {
        $c = $config + HostEvaluator::DEFAULTS;
        $snapshot = (array) $host['snapshot'];
        $disks = [];
        foreach ((array) ($snapshot['disks'] ?? []) as $disk) {
            $pct = Report::diskPct($disk);
            if ($pct !== null) {
                $disks[] = self::item('disk', $pct, (float) $c['disk_degraded_pct'], (float) $c['disk_unhealthy_pct'], (string) ($disk['mount'] ?? ''));
            }
        }
        usort($disks, static fn (array $a, array $b): int => $b['pct'] <=> $a['pct'] ?: strcmp($a['detail'], $b['detail']));
        $sample = $snapshot === [] ? null : Report::sample($snapshot);
        $samples = array_slice($samples, -self::HISTORY);
        $series = static fn (string $key): array => array_map(
            static fn (array $s): ?float => $s[$key] === null ? null : round((float) $s[$key], 1),
            $samples
        );

        return [
            'hostname' => (string) ($host['hostname'] ?? ''),
            'disks' => array_slice($disks, 0, 4),
            'load' => $sample['load1'] ?? null,
            'cores' => $snapshot['cpu']['cores'] ?? null,
            'rx_bps' => $sample['net_rx_bps'] ?? null,
            'tx_bps' => $sample['net_tx_bps'] ?? null,
            'history' => [
                'cpu' => $series('cpu_pct'),
                'mem' => $series('mem_pct'),
                'rx' => $series('net_rx_bps'),
                'tx' => $series('net_tx_bps'),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $disks
     * @return array{pct: float, detail: string}|null
     */
    private static function fullestDisk(array $disks): ?array
    {
        $best = null;
        foreach ($disks as $disk) {
            $inodes = ($disk['inodes_total'] ?? null) !== null && ($disk['inodes_used'] ?? null) !== null
                ? Report::pct($disk['inodes_total'], $disk['inodes_used']) : null;
            foreach ([[Report::diskPct($disk), ''], [$inodes, ' (inody)']] as [$pct, $suffix]) {
                if ($pct !== null && ($best === null || $pct > $best['pct'])) {
                    $best = ['pct' => (float) $pct, 'detail' => (string) ($disk['mount'] ?? '') . $suffix];
                }
            }
        }

        return $best;
    }

    /**
     * @return array{key: string, pct: int, level: string, detail: string}
     */
    private static function item(string $key, float $pct, float $warn, float $crit, string $detail): array
    {
        $level = $pct >= $crit ? 'crit' : ($pct >= $warn ? 'warn' : 'ok');

        return ['key' => $key, 'pct' => (int) round($pct), 'level' => $level, 'detail' => $detail];
    }
}
