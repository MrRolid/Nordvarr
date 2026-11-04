<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use Nordvarr\Checks\Reason;
use Nordvarr\Checks\Status;

/**
 * Vyhodnocení posledního hlášení agenta podle prahů kontroly typu "agent".
 * Výsledkem je seznam problémů; stav kontroly je nejhorší z nich.
 */
final class HostEvaluator
{
    /** Výchozí prahy (konfigurace kontroly je může změnit). */
    public const DEFAULTS = [
        'grace_s' => 180,
        'cpu_pct' => 95,
        'mem_pct' => 95,
        'disk_degraded_pct' => 85,
        'disk_unhealthy_pct' => 95,
        'temp_degraded_c' => 85,
        'temp_unhealthy_c' => 95,
        'wear_degraded_pct' => 80,
    ];

    /** Pořadí důležitosti kódů při stejné závažnosti. */
    private const PRIORITY = [
        Reason::SERVICE_DOWN, Reason::DISK_FAILING, Reason::TEMPERATURE_HIGH, Reason::DISK_FULL, Reason::DISK_WEAR, Reason::DISK_ERRORS,
        Reason::MEMORY_HIGH, Reason::CPU_HIGH,
    ];

    /**
     * @param array<string, mixed> $snapshot normalizované hlášení (Report::normalize)
     * @param list<array<string, mixed>> $smart rozebrané disky (Smart::parse)
     * @param array<string, mixed> $config konfigurace kontroly
     * @return list<array{severity: string, code: string, text: string}>
     */
    public static function problems(array $snapshot, array $smart, array $config): array
    {
        $c = $config + self::DEFAULTS;
        $problems = [];
        $add = static function (string $severity, string $code, string $text) use (&$problems): void {
            $problems[] = ['severity' => $severity, 'code' => $code, 'text' => $text];
        };

        $cpu = $snapshot['cpu']['usage_pct'];
        if ($cpu !== null && $cpu >= (float) $c['cpu_pct']) {
            $add(Status::DEGRADED, Reason::CPU_HIGH, sprintf('CPU %s %%', self::n($cpu)));
        }
        $sample = Report::sample($snapshot);
        if ($sample['mem_pct'] !== null && $sample['mem_pct'] >= (float) $c['mem_pct']) {
            $add(Status::DEGRADED, Reason::MEMORY_HIGH, sprintf('RAM %s %%', self::n($sample['mem_pct'])));
        }

        foreach ($snapshot['disks'] as $disk) {
            $pct = Report::diskPct($disk);
            $inodes = $disk['inodes_total'] !== null && $disk['inodes_used'] !== null ? Report::pct($disk['inodes_total'], $disk['inodes_used']) : null;
            foreach ([['místo', $pct], ['inody', $inodes]] as [$what, $value]) {
                if ($value === null) {
                    continue;
                }
                if ($value >= (float) $c['disk_unhealthy_pct']) {
                    $add(Status::UNHEALTHY, Reason::DISK_FULL, sprintf('%s zaplněno %s %% (%s)', $disk['mount'], self::n($value), $what));
                } elseif ($value >= (float) $c['disk_degraded_pct']) {
                    $add(Status::DEGRADED, Reason::DISK_FULL, sprintf('%s zaplněno %s %% (%s)', $disk['mount'], self::n($value), $what));
                }
            }
        }

        // Teploty: kritická mez čidla má přednost, jinak prahy kontroly.
        foreach ($snapshot['temperatures'] as $t) {
            $name = trim($t['chip'] . ' ' . $t['label']);
            $unhealthy = $t['crit_c'] !== null && $t['crit_c'] > 0 ? min((float) $t['crit_c'], (float) $c['temp_unhealthy_c']) : (float) $c['temp_unhealthy_c'];
            if ($t['c'] >= $unhealthy) {
                $add(Status::UNHEALTHY, Reason::TEMPERATURE_HIGH, sprintf('%s %s °C', $name, self::n($t['c'])));
            } elseif ($t['c'] >= (float) $c['temp_degraded_c'] || ($t['max_c'] !== null && $t['max_c'] > 0 && $t['c'] >= $t['max_c'])) {
                $add(Status::DEGRADED, Reason::TEMPERATURE_HIGH, sprintf('%s %s °C', $name, self::n($t['c'])));
            }
        }
        foreach ($snapshot['gpus'] as $g) {
            if ($g['temp_c'] === null) {
                continue;
            }
            $name = sprintf('GPU %d %s', (int) $g['index'], $g['name']);
            if ($g['temp_c'] >= (float) $c['temp_unhealthy_c']) {
                $add(Status::UNHEALTHY, Reason::TEMPERATURE_HIGH, sprintf('%s %s °C', trim($name), self::n($g['temp_c'])));
            } elseif ($g['temp_c'] >= (float) $c['temp_degraded_c']) {
                $add(Status::DEGRADED, Reason::TEMPERATURE_HIGH, sprintf('%s %s °C', trim($name), self::n($g['temp_c'])));
            }
        }

        foreach ($smart as $disk) {
            $label = $disk['device'] !== '' ? $disk['device'] : $disk['model'];
            foreach ($disk['problems'] as $p) {
                $code = $p['code'] === Smart::FAILING ? Reason::DISK_FAILING : Reason::DISK_ERRORS;
                $add($p['severity'], $code, $label . ': ' . $p['text']);
            }
            if ($disk['wear_pct'] !== null && $disk['wear_pct'] >= (float) $c['wear_degraded_pct']) {
                $add($disk['wear_pct'] >= 100 ? Status::UNHEALTHY : Status::DEGRADED, Reason::DISK_WEAR,
                    sprintf('%s: opotřebení %s %%', $label, self::n($disk['wear_pct'])));
            }
        }

        // Služby: spadlá (failed) nebo zapnutá, ale neběžící služba je výpadek serveru.
        foreach ($snapshot['services'] ?? [] as $svc) {
            if ($svc['active'] === 'failed') {
                $add(Status::UNHEALTHY, Reason::SERVICE_DOWN, sprintf('služba %s spadla (failed)', $svc['name']));
            } elseif ($svc['active'] === 'inactive' && $svc['enabled'] === 'enabled') {
                $add(Status::UNHEALTHY, Reason::SERVICE_DOWN, sprintf('služba %s neběží', $svc['name']));
            }
        }

        return $problems;
    }

    /**
     * Stav, kód a detail pro výsledek kontroly.
     *
     * @param list<array{severity: string, code: string, text: string}> $problems
     * @return array{status: string, reason: ?string, detail: string}
     */
    public static function summarize(array $problems): array
    {
        if ($problems === []) {
            return ['status' => Status::HEALTHY, 'reason' => null, 'detail' => ''];
        }
        // Nejdřív závažnost, při shodě důležitost druhu problému (selhávající disk je víc než plný disk).
        $priority = array_flip(self::PRIORITY);
        usort($problems, static fn (array $a, array $b): int => [Status::severity($b['severity']), $priority[$a['code']] ?? 99]
            <=> [Status::severity($a['severity']), $priority[$b['code']] ?? 99]);
        $detail = implode('; ', array_column($problems, 'text'));

        return [
            'status' => $problems[0]['severity'],
            'reason' => $problems[0]['code'],
            'detail' => mb_strlen($detail) > 300 ? mb_substr($detail, 0, 297) . '...' : $detail,
        ];
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
