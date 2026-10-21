<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Agent\HostEvaluator;
use Nordvarr\Agent\Report;
use Nordvarr\Agent\Smart;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Tests\AgentFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Hostový agent: normalizace hlášení, rozbor SMART a vyhodnocení prahů.
 */
final class AgentEvaluationTest extends TestCase
{
    private const NOW = '2026-10-21T10:00:00Z';

    /**
     * @return array<string, mixed>
     */
    private static function smart(string $name): array
    {
        return AgentFixtures::smart($name);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function input(array $override = []): array
    {
        return AgentFixtures::report($override + ['collected_at' => self::NOW]);
    }

    public function testNormalizeKeepsKnownFieldsAndClampsValues(): void
    {
        $r = Report::normalize(self::input([
            'cpu' => ['usage_pct' => 250, 'cores' => '16', 'load' => ['1.5', 'x', 2], 'model' => "CPU\x00\nX"],
            'neznamy_klic' => 'pryč',
            'disks' => [['mount' => ''], 'nesmysl', ['mount' => '/srv', 'used_b' => -5, 'avail_b' => 10]],
        ]), self::NOW);
        $s = $r['snapshot'];
        self::assertNull($s['cpu']['usage_pct'], 'Mimo rozsah -> null.');
        self::assertSame(16, $s['cpu']['cores']);
        self::assertSame([1.5, null, 2.0], $s['cpu']['load']);
        self::assertSame('CPU X', $s['cpu']['model']);
        self::assertArrayNotHasKey('neznamy_klic', $s);
        self::assertCount(1, $s['disks']);
        self::assertNull($s['disks'][0]['used_b']);
        self::assertSame('host:gpu1.example.com', $r['external_id']);
        self::assertNull($r['smart'], 'Bez SMART v hlášení se uložený stav nemění.');
    }

    public function testNormalizeRejectsInvalidReport(): void
    {
        foreach ([['hostname' => ''], ['hostname' => 'a b'], ['cpu' => 'x'], ['object_id' => 'nesmysl']] as $override) {
            try {
                Report::normalize(self::input($override), self::NOW);
                self::fail('Očekávaná chyba: ' . json_encode($override));
            } catch (ValidationException $e) {
                self::assertNotSame([], $e->errors);
            }
        }
    }

    public function testCollectedAtFarFromServerTimeIsReplaced(): void
    {
        self::assertSame('2026-10-21T09:58:00Z', Report::normalize(self::input(['collected_at' => '2026-10-21T09:58:00Z']), self::NOW)['snapshot']['collected_at']);
        self::assertSame(self::NOW, Report::normalize(self::input(['collected_at' => '2020-01-01T00:00:00Z']), self::NOW)['snapshot']['collected_at']);
    }

    public function testSample(): void
    {
        $sample = Report::sample(Report::normalize(self::input(), self::NOW)['snapshot']);
        self::assertSame(37.5, $sample['cpu_pct']);
        self::assertSame(62.5, $sample['mem_pct']);
        self::assertSame(0.0, $sample['swap_pct']);
        self::assertSame(87.5, $sample['disk_max_pct'], 'Zaplnění /data jako df: 3500 / (3500 + 500).');
        self::assertSame(61.0, $sample['temp_max_c']);
        self::assertSame(92.0, $sample['gpu_util_max_pct']);
        self::assertSame(71.0, $sample['gpu_temp_max_c']);
        self::assertSame(1300000.0, $sample['net_rx_bps']);
    }

    public function testSmartAtaHealthy(): void
    {
        $d = Smart::parse(self::smart('smart-ata-ok'));
        self::assertSame('/dev/sda', $d['device']);
        self::assertSame('EXAMPLE SSD 1TB', $d['model']);
        self::assertTrue($d['health_passed']);
        self::assertSame(12.0, $d['wear_pct'], 'Wear_Leveling_Count 88 -> opotřebení 12 %.');
        self::assertSame(34.0, $d['temp_c']);
        self::assertSame(21500, $d['power_on_hours']);
        self::assertSame([], $d['problems']);
    }

    public function testSmartAtaFailing(): void
    {
        $d = Smart::parse(self::smart('smart-ata-failing'));
        self::assertFalse($d['health_passed']);
        $texts = array_column($d['problems'], 'text');
        self::assertContains('SMART hlásí selhávající disk', $texts);
        self::assertContains('přemapované sektory: 1872', $texts);
        self::assertContains('čekající sektory: 24', $texts);
        self::assertContains('neopravitelné sektory offline: 8', $texts);
        self::assertContains('atribut Reallocated_Sector_Ct pod prahem výrobce', $texts);
        self::assertContains('chyby v logu disku: 17', $texts);
    }

    public function testSmartNvmeWear(): void
    {
        $d = Smart::parse(self::smart('smart-nvme-worn'));
        self::assertSame('nvme', $d['protocol']);
        self::assertSame(87.0, $d['wear_pct']);
        self::assertSame(52.0, $d['temp_c']);
        self::assertSame([['severity' => 'degraded', 'code' => Smart::ERRORS, 'text' => 'NVMe chyby média: 3']], $d['problems']);

        $critical = self::smart('smart-nvme-worn');
        $critical['nvme_smart_health_information_log']['critical_warning'] = 4 | 8;
        $critical['nvme_smart_health_information_log']['media_errors'] = 0;
        self::assertSame('NVMe kritické varování (snížená spolehlivost, jen pro čtení)', Smart::parse($critical)['problems'][0]['text']);
    }

    public function testHealthyHostHasNoProblems(): void
    {
        $snapshot = Report::normalize(self::input(['disks' => []]), self::NOW)['snapshot'];
        $problems = HostEvaluator::problems($snapshot, [Smart::parse(self::smart('smart-ata-ok'))], []);
        self::assertSame([], $problems);
        self::assertSame(['status' => 'healthy', 'reason' => null, 'detail' => ''], HostEvaluator::summarize($problems));
    }

    public function testThresholdsAndSmartProblems(): void
    {
        $snapshot = Report::normalize(self::input([
            'cpu' => ['usage_pct' => 99, 'cores' => 16, 'load' => [30, 20, 10]],
            'temperatures' => [
                ['chip' => 'coretemp', 'label' => 'Package id 0', 'c' => 101, 'max_c' => 84, 'crit_c' => 100],
                ['chip' => 'acpitz', 'label' => '', 'c' => 86],
            ],
            'gpus' => [['index' => 0, 'name' => 'NVIDIA Example', 'temp_c' => 88]],
        ]), self::NOW)['snapshot'];
        $smart = [Smart::parse(self::smart('smart-ata-failing')), Smart::parse(self::smart('smart-nvme-worn'))];
        $problems = HostEvaluator::problems($snapshot, $smart, []);
        $byCode = [];
        foreach ($problems as $p) {
            $byCode[$p['code']][] = $p['severity'] . ' ' . $p['text'];
        }
        self::assertSame(['degraded CPU 99 %'], $byCode['cpu_high']);
        self::assertSame(['degraded /data zaplněno 87.5 % (místo)'], $byCode['disk_full']);
        self::assertSame(['unhealthy coretemp Package id 0 101 °C', 'degraded acpitz 86 °C', 'degraded GPU 0 NVIDIA Example 88 °C'], $byCode['temperature_high']);
        self::assertSame(['degraded /dev/nvme0: opotřebení 87 %'], $byCode['disk_wear']);
        self::assertContains('unhealthy /dev/sdb: SMART hlásí selhávající disk', $byCode['disk_failing']);

        $summary = HostEvaluator::summarize($problems);
        self::assertSame('unhealthy', $summary['status']);
        self::assertSame('disk_failing', $summary['reason'], 'Při stejné závažnosti má selhávající disk přednost.');
        self::assertStringStartsWith('/dev/sdb: SMART hlásí selhávající disk', $summary['detail']);
        self::assertLessThanOrEqual(300, mb_strlen($summary['detail']));

        // Prahy z konfigurace kontroly.
        $relaxed = HostEvaluator::problems($snapshot, [], ['cpu_pct' => 100, 'disk_degraded_pct' => 90, 'temp_degraded_c' => 90, 'temp_unhealthy_c' => 120]);
        self::assertSame(['temperature_high'], array_values(array_unique(array_column($relaxed, 'code'))), 'Kritická mez čidla platí dál.');
    }

    public function testFullDiskAndInodes(): void
    {
        $snapshot = Report::normalize(self::input(['disks' => [
            ['mount' => '/var', 'used_b' => 96, 'avail_b' => 4, 'inodes_total' => 100, 'inodes_used' => 10],
            ['mount' => '/srv', 'used_b' => 10, 'avail_b' => 90, 'inodes_total' => 1000, 'inodes_used' => 990],
        ]]), self::NOW)['snapshot'];
        $texts = array_map(static fn (array $p): string => $p['severity'] . ' ' . $p['text'], HostEvaluator::problems($snapshot, [], []));
        self::assertContains('unhealthy /var zaplněno 96 % (místo)', $texts);
        self::assertContains('unhealthy /srv zaplněno 99 % (inody)', $texts);
    }
}
