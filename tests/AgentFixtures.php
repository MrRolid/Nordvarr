<?php

declare(strict_types=1);

namespace Nordvarr\Tests;

use Nordvarr\Time;

/**
 * Ukázková hlášení hostového agenta a výstupy smartctl pro testy (fiktivní server).
 */
final class AgentFixtures
{
    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function report(array $override = []): array
    {
        return array_replace([
            'collected_at' => Time::toDb(Time::now()),
            'agent_version' => '1.0.0',
            'hostname' => 'gpu1.example.com',
            'os' => 'Debian GNU/Linux 13',
            'kernel' => '6.12.0-amd64',
            'uptime_s' => 864000,
            'cpu' => ['usage_pct' => 37.5, 'cores' => 16, 'model' => 'Example CPU 16C', 'load' => [2.5, 2.1, 1.9]],
            'memory' => ['total_b' => 68719476736, 'available_b' => 25769803776, 'swap_total_b' => 8589934592, 'swap_free_b' => 8589934592],
            'disks' => [
                ['mount' => '/', 'device' => '/dev/nvme0n1p2', 'fs' => 'ext4', 'size_b' => 500000000000, 'used_b' => 200000000000, 'avail_b' => 275000000000, 'inodes_total' => 30000000, 'inodes_used' => 900000],
                ['mount' => '/data', 'device' => '/dev/sdb1', 'fs' => 'xfs', 'size_b' => 4000000000000, 'used_b' => 3500000000000, 'avail_b' => 500000000000, 'inodes_total' => null, 'inodes_used' => null],
            ],
            'temperatures' => [
                ['chip' => 'coretemp', 'label' => 'Package id 0', 'c' => 61, 'max_c' => 84, 'crit_c' => 100],
                ['chip' => 'nvme', 'label' => 'Composite', 'c' => 45.85, 'max_c' => 81.85, 'crit_c' => 84.85],
            ],
            'gpus' => [['index' => 0, 'name' => 'NVIDIA Example 24GB', 'util_pct' => 92, 'mem_used_mb' => 18000, 'mem_total_mb' => 24576, 'temp_c' => 71, 'power_w' => 280.5, 'fan_pct' => 55]],
            'network' => [['iface' => 'eth0', 'rx_bps' => 1250000, 'tx_bps' => 250000], ['iface' => 'eth1', 'rx_bps' => 50000, 'tx_bps' => 10000]],
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    public static function smart(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/agent/' . $name . '.json'), true, 64, JSON_THROW_ON_ERROR);
    }
}
