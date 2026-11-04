<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Agent\HostResources;
use Nordvarr\Agent\Report;
use Nordvarr\Tests\AgentFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Stav prostředků serveru pro mapu topologie (barvy pruhů CPU, RAM, disk).
 */
final class HostResourcesTest extends TestCase
{
    private const NOW = '2026-10-21T10:00:00Z';

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function host(array $override = [], string $reportAt = self::NOW): array
    {
        $snapshot = Report::normalize(AgentFixtures::report($override + ['collected_at' => $reportAt]), $reportAt)['snapshot'];

        return ['snapshot' => $snapshot, 'last_report_at' => $reportAt];
    }

    /**
     * @param array{stale: bool, items: list<array<string, mixed>>} $resources
     * @return array<string, string>
     */
    private static function levels(array $resources): array
    {
        return array_column($resources['items'], 'level', 'key');
    }

    public function testLevelsFromDefaultThresholds(): void
    {
        $r = HostResources::evaluate(self::host(), [], self::NOW);
        self::assertFalse($r['stale']);
        self::assertSame(['cpu', 'mem', 'disk', 'gpu'], array_column($r['items'], 'key'));
        self::assertSame(['cpu' => 'ok', 'mem' => 'ok', 'disk' => 'warn', 'gpu' => 'none'], self::levels($r), 'Disk /data je zaplněný na 87,5 %, hodnoty se zaokrouhlují jako na kartě.');
        self::assertSame(['cpu' => 38, 'mem' => 63, 'disk' => 88, 'gpu' => 92], array_column($r['items'], 'pct', 'key'));
        self::assertSame('/data', $r['items'][2]['detail']);
    }

    public function testWarnBelowThresholdAndCritAtThreshold(): void
    {
        $levels = static fn (float $cpu): string => self::levels(HostResources::evaluate(self::host(['cpu' => ['usage_pct' => $cpu, 'cores' => 4, 'load' => [1, 1, 1]]]), [], self::NOW))['cpu'];
        self::assertSame('ok', $levels(79.9));
        self::assertSame('warn', $levels(80));
        self::assertSame('warn', $levels(94.9));
        self::assertSame('crit', $levels(95));

        // Prahy z kontroly agent.
        $r = HostResources::evaluate(self::host(), ['cpu_pct' => 40, 'disk_degraded_pct' => 90, 'disk_unhealthy_pct' => 95], self::NOW);
        self::assertSame(['cpu' => 'warn', 'mem' => 'ok', 'disk' => 'ok', 'gpu' => 'none'], self::levels($r));
    }

    public function testFullestDiskIncludesInodes(): void
    {
        $r = HostResources::evaluate(self::host(['disks' => [
            ['mount' => '/', 'used_b' => 10, 'avail_b' => 90, 'inodes_total' => 100, 'inodes_used' => 97],
            ['mount' => '/srv', 'used_b' => 60, 'avail_b' => 40, 'inodes_total' => null, 'inodes_used' => null],
        ]]), [], self::NOW);
        $disk = $r['items'][2];
        self::assertSame(['disk', 97, 'crit', '/ (inody)'], [$disk['key'], $disk['pct'], $disk['level'], $disk['detail']]);
    }

    public function testStaleReportAndMissingValues(): void
    {
        $r = HostResources::evaluate(self::host([], '2026-10-21T09:56:00Z'), [], self::NOW);
        self::assertTrue($r['stale'], 'Hlášení starší než grace_s (180 s).');
        self::assertFalse(HostResources::evaluate(self::host([], '2026-10-21T09:56:00Z'), ['grace_s' => 600], self::NOW)['stale']);

        $r = HostResources::evaluate(self::host(['disks' => [], 'gpus' => [], 'cpu' => ['usage_pct' => null, 'cores' => 4, 'load' => [1, 1, 1]]]), [], self::NOW);
        self::assertSame(['mem'], array_column($r['items'], 'key'), 'Chybějící hodnoty se vynechají.');
        self::assertSame([], HostResources::evaluate(['snapshot' => [], 'last_report_at' => self::NOW], [], self::NOW)['items']);
    }
}
