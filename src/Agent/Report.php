<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use Nordvarr\Inventory\ValidationException;
use Nordvarr\Repository\EventRepository;

/**
 * Ověření a normalizace hlášení hostového agenta (POST /api/v1/agent/report).
 * Neznámé klíče se zahodí, čísla se omezí na rozumné meze, texty na délku.
 */
final class Report
{
    public const MAX_DISKS = 64;
    public const MAX_TEMPERATURES = 128;
    public const MAX_GPUS = 16;
    public const MAX_INTERFACES = 32;
    public const MAX_SMART = 32;

    /**
     * @param array<string, mixed> $input
     * @return array{snapshot: array<string, mixed>, smart: list<array<string, mixed>>|null, smart_error: string, external_id: string, object_id: ?string}
     */
    public static function normalize(array $input, string $nowDb): array
    {
        $errors = [];
        $hostname = self::str($input['hostname'] ?? null, 253);
        if ($hostname === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-_]*$/', $hostname) !== 1) {
            $errors['hostname'] = 'invalid_hostname';
        }
        $externalId = self::str($input['external_id'] ?? null, 200);
        if ($externalId === '') {
            $externalId = 'host:' . strtolower($hostname);
        }
        $objectId = isset($input['object_id']) ? self::str($input['object_id'], 36) : null;
        if ($objectId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $objectId) !== 1) {
            $errors['object_id'] = 'invalid_object_id';
        }
        $cpu = is_array($input['cpu'] ?? null) ? $input['cpu'] : [];
        $memory = is_array($input['memory'] ?? null) ? $input['memory'] : [];
        if (!is_array($input['cpu'] ?? null) || !is_array($input['memory'] ?? null)) {
            $errors['metrics'] = 'invalid_metrics';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Čas měření od agenta jen když je rozumný, jinak čas příjmu (hodiny serveru mohou ujet).
        $collected = is_string($input['collected_at'] ?? null) ? EventRepository::normalizeTime($input['collected_at']) : null;
        $at = $collected !== null && abs(strtotime($collected) - strtotime($nowDb)) <= 300 ? $collected : $nowDb;

        $load = is_array($cpu['load'] ?? null) ? array_values($cpu['load']) : [];
        $snapshot = [
            'hostname' => $hostname,
            'agent_version' => self::str($input['agent_version'] ?? null, 20),
            'collected_at' => $at,
            'os' => self::str($input['os'] ?? null, 120),
            'kernel' => self::str($input['kernel'] ?? null, 120),
            'uptime_s' => self::int($input['uptime_s'] ?? null, 0, 100 * 365 * 86400),
            'cpu' => [
                'usage_pct' => self::num($cpu['usage_pct'] ?? null, 0, 100),
                'cores' => self::int($cpu['cores'] ?? null, 1, 4096),
                'model' => self::str($cpu['model'] ?? null, 120),
                'load' => [self::num($load[0] ?? null, 0, 100000), self::num($load[1] ?? null, 0, 100000), self::num($load[2] ?? null, 0, 100000)],
            ],
            'memory' => [
                'total_b' => self::int($memory['total_b'] ?? null, 0, PHP_INT_MAX),
                'available_b' => self::int($memory['available_b'] ?? null, 0, PHP_INT_MAX),
                'swap_total_b' => self::int($memory['swap_total_b'] ?? null, 0, PHP_INT_MAX),
                'swap_free_b' => self::int($memory['swap_free_b'] ?? null, 0, PHP_INT_MAX),
            ],
            'disks' => self::list($input['disks'] ?? null, self::MAX_DISKS, static fn (array $d): ?array => ($mount = self::str($d['mount'] ?? null, 200)) === '' ? null : [
                'mount' => $mount,
                'device' => self::str($d['device'] ?? null, 200),
                'fs' => self::str($d['fs'] ?? null, 30),
                'size_b' => self::int($d['size_b'] ?? null, 0, PHP_INT_MAX),
                'used_b' => self::int($d['used_b'] ?? null, 0, PHP_INT_MAX),
                'avail_b' => self::int($d['avail_b'] ?? null, 0, PHP_INT_MAX),
                'inodes_total' => self::int($d['inodes_total'] ?? null, 0, PHP_INT_MAX),
                'inodes_used' => self::int($d['inodes_used'] ?? null, 0, PHP_INT_MAX),
            ]),
            'temperatures' => self::list($input['temperatures'] ?? null, self::MAX_TEMPERATURES, static fn (array $t): ?array => ($c = self::num($t['c'] ?? null, -60, 200)) === null ? null : [
                'chip' => self::str($t['chip'] ?? null, 60),
                'label' => self::str($t['label'] ?? null, 60),
                'c' => $c,
                'max_c' => self::num($t['max_c'] ?? null, 0, 200),
                'crit_c' => self::num($t['crit_c'] ?? null, 0, 200),
            ]),
            'gpus' => self::list($input['gpus'] ?? null, self::MAX_GPUS, static fn (array $g): array => [
                'index' => self::int($g['index'] ?? null, 0, 64),
                'name' => self::str($g['name'] ?? null, 120),
                'util_pct' => self::num($g['util_pct'] ?? null, 0, 100),
                'mem_used_mb' => self::num($g['mem_used_mb'] ?? null, 0, 10000000),
                'mem_total_mb' => self::num($g['mem_total_mb'] ?? null, 0, 10000000),
                'temp_c' => self::num($g['temp_c'] ?? null, -60, 200),
                'power_w' => self::num($g['power_w'] ?? null, 0, 100000),
                'fan_pct' => self::num($g['fan_pct'] ?? null, 0, 100),
            ]),
            'network' => self::list($input['network'] ?? null, self::MAX_INTERFACES, static fn (array $n): ?array => ($iface = self::str($n['iface'] ?? null, 40)) === '' ? null : [
                'iface' => $iface,
                'rx_bps' => self::num($n['rx_bps'] ?? null, 0, 1.0e12),
                'tx_bps' => self::num($n['tx_bps'] ?? null, 0, 1.0e12),
            ]),
        ];

        // SMART posílá agent jen občas (výstup smartctl -j); null = beze změny.
        $smart = null;
        if (is_array($input['smart'] ?? null) && array_is_list($input['smart'])) {
            $smart = [];
            foreach (array_slice($input['smart'], 0, self::MAX_SMART) as $raw) {
                if (is_array($raw)) {
                    $smart[] = Smart::parse($raw);
                }
            }
        }

        return [
            'snapshot' => $snapshot,
            'smart' => $smart,
            'smart_error' => self::str($input['smart_error'] ?? null, 60),
            'external_id' => $externalId,
            'object_id' => $objectId,
        ];
    }

    /**
     * Souhrnné hodnoty jednoho vzorku pro graf a agregace.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, float|null>
     */
    public static function sample(array $snapshot): array
    {
        $mem = $snapshot['memory'];
        $max = static fn (array $values): ?float => ($values = array_values(array_filter($values, static fn ($v): bool => $v !== null))) === [] ? null : (float) max($values);
        $sum = static fn (array $values): ?float => $values === [] ? null : (float) array_sum($values);

        return [
            'cpu_pct' => $snapshot['cpu']['usage_pct'],
            'mem_pct' => self::pct($mem['total_b'], $mem['total_b'] !== null && $mem['available_b'] !== null ? $mem['total_b'] - $mem['available_b'] : null),
            'swap_pct' => self::pct($mem['swap_total_b'], $mem['swap_total_b'] !== null && $mem['swap_free_b'] !== null ? $mem['swap_total_b'] - $mem['swap_free_b'] : null),
            'load1' => $snapshot['cpu']['load'][0],
            'disk_max_pct' => $max(array_map(static fn (array $d): ?float => self::diskPct($d), $snapshot['disks'])),
            'temp_max_c' => $max(array_column($snapshot['temperatures'], 'c')),
            'gpu_util_max_pct' => $max(array_column($snapshot['gpus'], 'util_pct')),
            'gpu_temp_max_c' => $max(array_column($snapshot['gpus'], 'temp_c')),
            'net_rx_bps' => $sum(array_values(array_filter(array_column($snapshot['network'], 'rx_bps'), static fn ($v): bool => $v !== null))),
            'net_tx_bps' => $sum(array_values(array_filter(array_column($snapshot['network'], 'tx_bps'), static fn ($v): bool => $v !== null))),
        ];
    }

    /**
     * Zaplnění disku jako df: použité / (použité + volné pro běžné uživatele).
     *
     * @param array<string, mixed> $disk
     */
    public static function diskPct(array $disk): ?float
    {
        if ($disk['used_b'] === null || $disk['avail_b'] === null || $disk['used_b'] + $disk['avail_b'] <= 0) {
            return null;
        }

        return round(100 * $disk['used_b'] / ($disk['used_b'] + $disk['avail_b']), 1);
    }

    public static function pct(?int $total, ?int $used): ?float
    {
        return $total === null || $used === null || $total <= 0 ? null : round(100 * max(0, $used) / $total, 1);
    }

    private static function str(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $value) ?? '';
        $value = trim(mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8'));

        return mb_substr($value, 0, $max);
    }

    private static function num(mixed $value, float $min, float $max): ?float
    {
        if (is_string($value) && is_numeric(trim($value))) {
            $value = (float) trim($value);
        }
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;
        if (!is_finite($value) || $value < $min || $value > $max) {
            return null;
        }

        return round($value, 2);
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9.0e18) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    /**
     * @param callable(array<string, mixed>): ?array<string, mixed> $map
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value, int $max, callable $map): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach (array_slice($value, 0, $max) as $item) {
            if (is_array($item) && ($row = $map($item)) !== null) {
                $result[] = $row;
            }
        }

        return $result;
    }
}
