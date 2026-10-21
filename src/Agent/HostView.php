<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use DateTimeImmutable;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Time;
use Nordvarr\View\Svg;
use PDO;

/**
 * Data pro zobrazení serveru s agentem (karta objektu, panel v topologii):
 * hodnoty, úrovně pro barvy pruhů podle prahů kontroly a graf za 24 h.
 */
final class HostView
{
    /** SMART starší než 2 hodiny se označí jako neaktuální. */
    private const SMART_STALE_S = 7200;

    /**
     * @param array<string, mixed> $host řádek z AgentRepository::host()
     * @param array<string, mixed> $config konfigurace kontroly agent (prahy)
     * @param list<array<string, mixed>> $samples vzorky za posledních 24 h
     * @return array<string, mixed>
     */
    public static function build(array $host, array $config, array $samples, DateTimeImmutable $now, string $chartLabel = ''): array
    {
        $c = $config + HostEvaluator::DEFAULTS;
        $s = $host['snapshot'];
        $sample = Report::sample($s);
        $mem = $s['memory'];
        $pctLevel = static fn (?float $v, float $bad): string => Svg::level($v, $bad * 0.85, $bad);
        $tempLevel = static fn (?float $v, ?float $crit = null): string => Svg::level(
            $v,
            (float) $c['temp_degraded_c'],
            $crit !== null && $crit > 0 ? min($crit, (float) $c['temp_unhealthy_c']) : (float) $c['temp_unhealthy_c']
        );
        $lastReport = Time::fromDb((string) $host['last_report_at']);

        $temps = [];
        foreach ($s['temperatures'] as $t) {
            $temps[] = [
                'name' => trim($t['chip'] . ' ' . $t['label']),
                'c' => $t['c'],
                'max_c' => $t['max_c'],
                'crit_c' => $t['crit_c'],
                'level' => $tempLevel($t['c'], $t['crit_c']),
            ];
        }
        usort($temps, static fn (array $a, array $b): int => $b['c'] <=> $a['c']);

        $smart = [];
        foreach ($host['smart'] as $d) {
            $severities = array_column($d['problems'], 'severity');
            $worn = $d['wear_pct'] !== null && $d['wear_pct'] >= (float) $c['wear_degraded_pct'];
            $smart[] = $d + [
                'level' => in_array('unhealthy', $severities, true) ? 'bad' : ($severities !== [] || $worn ? 'warn' : 'ok'),
                'wear_level' => Svg::level($d['wear_pct'], (float) $c['wear_degraded_pct'], 100),
            ];
        }
        $smartAt = $host['smart_at'] !== null ? (string) $host['smart_at'] : null;

        return [
            'hostname' => (string) $host['hostname'],
            'agent_version' => (string) $host['agent_version'],
            'os' => $s['os'],
            'kernel' => $s['kernel'],
            'uptime_s' => $s['uptime_s'],
            'last_report_at' => (string) $host['last_report_at'],
            'stale' => $now->getTimestamp() - $lastReport->getTimestamp() > (int) $c['grace_s'] + 60,
            'cpu' => [
                'pct' => $sample['cpu_pct'],
                'level' => $pctLevel($sample['cpu_pct'], (float) $c['cpu_pct']),
                'cores' => $s['cpu']['cores'],
                'model' => $s['cpu']['model'],
                'load' => $s['cpu']['load'],
            ],
            'mem' => [
                'pct' => $sample['mem_pct'],
                'level' => $pctLevel($sample['mem_pct'], (float) $c['mem_pct']),
                'used_b' => $mem['total_b'] !== null && $mem['available_b'] !== null ? $mem['total_b'] - $mem['available_b'] : null,
                'total_b' => $mem['total_b'],
            ],
            'swap' => [
                'pct' => $sample['swap_pct'],
                'level' => $pctLevel($sample['swap_pct'], 90),
                'used_b' => $mem['swap_total_b'] !== null && $mem['swap_free_b'] !== null ? $mem['swap_total_b'] - $mem['swap_free_b'] : null,
                'total_b' => $mem['swap_total_b'],
            ],
            'disks' => array_map(static function (array $d) use ($c): array {
                $pct = Report::diskPct($d);
                $inodes = $d['inodes_total'] !== null && $d['inodes_used'] !== null ? Report::pct($d['inodes_total'], $d['inodes_used']) : null;

                return $d + [
                    'pct' => $pct,
                    'level' => Svg::level($pct, (float) $c['disk_degraded_pct'], (float) $c['disk_unhealthy_pct']),
                    'inodes_pct' => $inodes,
                    'inodes_level' => Svg::level($inodes, (float) $c['disk_degraded_pct'], (float) $c['disk_unhealthy_pct']),
                ];
            }, $s['disks']),
            'disk_max' => $sample['disk_max_pct'],
            'disk_max_level' => Svg::level($sample['disk_max_pct'], (float) $c['disk_degraded_pct'], (float) $c['disk_unhealthy_pct']),
            'temps' => $temps,
            'temp_max' => $temps[0]['c'] ?? null,
            'temp_max_level' => $temps[0]['level'] ?? 'none',
            'gpus' => array_map(static fn (array $g): array => $g + [
                'util_level' => Svg::level($g['util_pct'], 101, 101),
                'mem_pct' => $g['mem_used_mb'] !== null && $g['mem_total_mb'] ? round(100 * $g['mem_used_mb'] / $g['mem_total_mb'], 1) : null,
                'mem_level' => Svg::level($g['mem_used_mb'] !== null && $g['mem_total_mb'] ? 100 * $g['mem_used_mb'] / $g['mem_total_mb'] : null, 90, 98),
                'temp_level' => $tempLevel($g['temp_c']),
            ], $s['gpus']),
            'network' => $s['network'],
            'smart' => $smart,
            'smart_at' => $smartAt,
            'smart_stale' => $smartAt !== null && $now->getTimestamp() - Time::fromDb($smartAt)->getTimestamp() > self::SMART_STALE_S,
            'smart_error' => (string) $host['smart_error'],
            'problems' => HostEvaluator::problems($s, $host['smart'], $config),
            'chart' => $samples === [] ? null : self::chart($samples, $now, $chartLabel),
            'has_gpu_series' => $s['gpus'] !== [],
        ];
    }

    /**
     * Data serveru pro objekt, nebo null, když objekt nemá agenta.
     *
     * @param list<array<string, mixed>> $checks kontroly objektu (prahy bere z kontroly agent)
     * @return array<string, mixed>|null
     */
    public static function forObject(PDO $pdo, string $objectId, array $checks, bool $withChart, string $chartLabel = ''): ?array
    {
        $repo = new AgentRepository($pdo);
        $host = $repo->host($objectId);
        if ($host === null) {
            return null;
        }
        $config = [];
        foreach ($checks as $check) {
            if ($check['type'] === 'agent') {
                $config = $check['config'];
            }
        }
        $now = Time::now();
        $samples = $withChart ? $repo->samples($objectId, Time::toDb($now->modify('-24 hours'))) : [];

        return self::build($host, $config, $samples, $now, $chartLabel);
    }

    /**
     * Graf za 24 h v 5minutových úsecích: CPU, RAM, nejvyšší teplota, vytížení GPU.
     *
     * @param list<array<string, mixed>> $samples
     */
    private static function chart(array $samples, DateTimeImmutable $now, string $label): string
    {
        $to = $now->getTimestamp();
        $from = $to - 86400;
        $bucket = 300;
        $buckets = [];
        foreach ($samples as $row) {
            $t = Time::fromDb((string) $row['at'])->getTimestamp();
            if ($t < $from) {
                continue;
            }
            $buckets[intdiv($t - $from, $bucket)][] = $row;
        }
        $series = ['cpu' => [], 'mem' => [], 'temp' => [], 'gpu' => []];
        for ($i = 0; $i <= intdiv(86400, $bucket); $i++) {
            $time = $from + $i * $bucket + intdiv($bucket, 2);
            $rows = $buckets[$i] ?? [];
            $avg = static function (string $col) use ($rows): ?float {
                $values = array_values(array_filter(array_column($rows, $col), static fn ($v): bool => $v !== null));

                return $values === [] ? null : array_sum(array_map('floatval', $values)) / count($values);
            };
            $max = static function (string $col) use ($rows): ?float {
                $values = array_values(array_filter(array_column($rows, $col), static fn ($v): bool => $v !== null));

                return $values === [] ? null : (float) max(array_map('floatval', $values));
            };
            $series['cpu'][] = [$time, $avg('cpu_pct')];
            $series['mem'][] = [$time, $avg('mem_pct')];
            $series['temp'][] = [$time, $max('temp_max_c')];
            $series['gpu'][] = [$time, $avg('gpu_util_max_pct')];
        }
        $hasGpu = array_filter($series['gpu'], static fn (array $p): bool => $p[1] !== null) !== [];

        return Svg::lines(array_filter([
            'cpu' => ['points' => $series['cpu'], 'max' => 100.0],
            'mem' => ['points' => $series['mem'], 'max' => 100.0],
            'temp' => ['points' => $series['temp'], 'max' => 110.0],
            'gpu' => $hasGpu ? ['points' => $series['gpu'], 'max' => 100.0] : null,
        ]), $from, $to, $label);
    }
}
