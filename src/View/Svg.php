<?php

declare(strict_types=1);

namespace Nordvarr\View;

/**
 * Malé grafy jako SVG generované na serveru (CSP nedovoluje inline styly, šířky
 * a souřadnice jsou proto atributy SVG). Výstup obsahuje jen čísla a pevné třídy.
 */
final class Svg
{
    /**
     * Vodorovný pruh 0-100 %. Úroveň (ok, warn, bad, none) určuje barvu přes CSS.
     */
    public static function bar(?float $pct, string $level): string
    {
        $level = in_array($level, ['ok', 'warn', 'bad', 'none'], true) ? $level : 'none';
        $width = $pct === null ? 0 : max(0.0, min(100.0, $pct));

        return '<svg class="bar bar-' . $level . '" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<rect class="bar-bg" width="100" height="8" rx="4"/>'
            . ($width > 0 ? '<rect class="bar-fg" width="' . self::n($width) . '" height="8" rx="4"/>' : '')
            . '</svg>';
    }

    /**
     * Úroveň pruhu podle prahů (procenta nebo stupně).
     */
    public static function level(?float $value, float $warn, float $bad): string
    {
        return match (true) {
            $value === null => 'none',
            $value >= $bad => 'bad',
            $value >= $warn => 'warn',
            default => 'ok',
        };
    }

    /**
     * Čárový graf více řad v časovém okně. Hodnoty se přepočtou na 0-100 podle $max
     * (procenta mají max 100, teploty se škálují na 0-110 °C).
     *
     * @param array<string, array{points: list<array{0: int, 1: ?float}>, max: float}> $series klíč = třída řady, body (unix čas, hodnota)
     */
    public static function lines(array $series, int $from, int $to, string $label): string
    {
        $w = 600;
        $h = 120;
        $span = max(1, $to - $from);
        $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . View::escape($label) . '">';
        foreach ([25, 50, 75] as $grid) {
            $y = self::n($h - $grid / 100 * $h);
            $out .= '<line class="chart-grid" x1="0" y1="' . $y . '" x2="' . $w . '" y2="' . $y . '"/>';
        }
        foreach ($series as $class => $s) {
            $segments = [];
            $current = [];
            foreach ($s['points'] as [$time, $value]) {
                if ($value === null) {
                    // Mezera v datech (agent neběžel) přeruší čáru.
                    if ($current !== []) {
                        $segments[] = $current;
                    }
                    $current = [];
                    continue;
                }
                $x = ($time - $from) / $span * $w;
                $y = $h - max(0.0, min(1.0, $value / $s['max'])) * $h;
                $current[] = self::n($x) . ',' . self::n($y);
            }
            if ($current !== []) {
                $segments[] = $current;
            }
            foreach ($segments as $points) {
                $out .= '<polyline class="chart-line line-' . preg_replace('/[^a-z0-9-]/', '', (string) $class) . '" points="' . implode(' ', $points) . '"/>';
            }
        }

        return $out . '</svg>';
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
