<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

/**
 * Rozbor výstupu `smartctl -j` (ATA/SATA i NVMe): zdraví, opotřebení, teplota,
 * provozní hodiny a nalezené problémy. Agent posílá syrový JSON, rozbor dělá server.
 */
final class Smart
{
    public const FAILING = 'disk_failing';
    public const ERRORS = 'disk_errors';

    /** ATA atributy, jejichž normalizovaná hodnota je zbývající životnost v %. */
    private const WEAR_ATTRIBUTES = [177, 202, 231, 233];

    /** ATA atributy, jejichž nenulová syrová hodnota znamená poškozené sektory nebo chyby čtení. */
    private const ERROR_ATTRIBUTES = [
        5 => 'přemapované sektory',
        187 => 'neopravitelné chyby',
        197 => 'čekající sektory',
        198 => 'neopravitelné sektory offline',
    ];

    /**
     * @param array<string, mixed> $json výstup smartctl -j pro jeden disk
     * @return array{device: string, model: string, serial: string, protocol: string, health_passed: ?bool, temp_c: ?float,
     *     power_on_hours: ?int, wear_pct: ?float, problems: list<array{severity: string, code: string, text: string}>}
     */
    public static function parse(array $json): array
    {
        $device = is_array($json['device'] ?? null) ? $json['device'] : [];
        $protocol = strtolower(self::str($device['protocol'] ?? ''));
        $passed = is_array($json['smart_status'] ?? null) && is_bool($json['smart_status']['passed'] ?? null) ? $json['smart_status']['passed'] : null;
        $problems = [];
        if ($passed === false) {
            $problems[] = ['severity' => 'unhealthy', 'code' => self::FAILING, 'text' => 'SMART hlásí selhávající disk'];
        }

        $temp = self::num($json['temperature']['current'] ?? null);
        $hours = self::num($json['power_on_time']['hours'] ?? null);
        $wear = null;

        $nvme = is_array($json['nvme_smart_health_information_log'] ?? null) ? $json['nvme_smart_health_information_log'] : null;
        if ($nvme !== null) {
            $wear = self::num($nvme['percentage_used'] ?? null);
            $temp ??= self::num($nvme['temperature'] ?? null);
            $hours ??= self::num($nvme['power_on_hours'] ?? null);
            $warning = (int) (self::num($nvme['critical_warning'] ?? null) ?? 0);
            if ($warning !== 0) {
                $problems[] = ['severity' => 'unhealthy', 'code' => self::FAILING, 'text' => 'NVMe kritické varování ' . self::warningText($warning)];
            }
            $spare = self::num($nvme['available_spare'] ?? null);
            $spareThreshold = self::num($nvme['available_spare_threshold'] ?? null);
            if ($spare !== null && $spareThreshold !== null && $spare <= $spareThreshold && ($warning & 1) === 0) {
                $problems[] = ['severity' => 'unhealthy', 'code' => self::FAILING, 'text' => 'NVMe rezerva bloků ' . (int) $spare . ' %'];
            }
            $media = (int) (self::num($nvme['media_errors'] ?? null) ?? 0);
            if ($media > 0) {
                $problems[] = ['severity' => 'degraded', 'code' => self::ERRORS, 'text' => 'NVMe chyby média: ' . $media];
            }
        }

        $table = $json['ata_smart_attributes']['table'] ?? null;
        if (is_array($table)) {
            foreach ($table as $attr) {
                if (!is_array($attr) || !is_int($attr['id'] ?? null)) {
                    continue;
                }
                $id = $attr['id'];
                $value = self::num($attr['value'] ?? null);
                $raw = self::num($attr['raw']['value'] ?? null);
                if (in_array($id, self::WEAR_ATTRIBUTES, true) && $value !== null && $value <= 100 && $wear === null) {
                    $wear = 100 - $value;
                }
                if (isset(self::ERROR_ATTRIBUTES[$id]) && $raw !== null && $raw > 0) {
                    $problems[] = ['severity' => 'degraded', 'code' => self::ERRORS, 'text' => self::ERROR_ATTRIBUTES[$id] . ': ' . (int) $raw];
                }
                $failed = self::str($attr['when_failed'] ?? '');
                if ($failed === 'now') {
                    $problems[] = ['severity' => 'unhealthy', 'code' => self::FAILING,
                        'text' => 'atribut ' . self::str($attr['name'] ?? (string) $id) . ' pod prahem výrobce'];
                }
            }
        }
        $logged = self::num($json['ata_smart_error_log']['summary']['count'] ?? null);
        if ($logged !== null && $logged > 0) {
            $problems[] = ['severity' => 'degraded', 'code' => self::ERRORS, 'text' => 'chyby v logu disku: ' . (int) $logged];
        }

        return [
            'device' => self::str($device['name'] ?? ''),
            'model' => self::str($json['model_name'] ?? ($json['model_family'] ?? '')),
            'serial' => self::str($json['serial_number'] ?? ''),
            'protocol' => $protocol,
            'health_passed' => $passed,
            'temp_c' => $temp,
            'power_on_hours' => $hours === null ? null : (int) $hours,
            'wear_pct' => $wear === null ? null : max(0.0, min(100.0, $wear)),
            'problems' => $problems,
        ];
    }

    private static function warningText(int $bits): string
    {
        $names = [1 => 'málo rezervních bloků', 2 => 'teplota', 4 => 'snížená spolehlivost', 8 => 'jen pro čtení', 16 => 'záloha paměti'];
        $parts = [];
        foreach ($names as $bit => $name) {
            if (($bits & $bit) !== 0) {
                $parts[] = $name;
            }
        }

        return '(' . ($parts === [] ? (string) $bits : implode(', ', $parts)) . ')';
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? ''), 0, 120) : '';
    }

    private static function num(mixed $value): ?float
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? (float) $value : null;
    }
}
