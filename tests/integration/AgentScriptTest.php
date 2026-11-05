<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Agent\Report;
use Nordvarr\Tests\FixtureServers;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Skript agenta (agent/nordvarr-agent.sh) nad falešným /proc a /sys a falešnými
 * příkazy df, nvidia-smi a smartctl. Odesílá jen na lokální mock endpoint.
 */
final class AgentScriptTest extends TestCase
{
    private string $tmp;
    private string $fixtures;
    private string $script;

    protected function setUp(): void
    {
        $this->fixtures = dirname(__DIR__) . '/fixtures/agent';
        $this->script = dirname(__DIR__, 2) . '/agent/nordvarr-agent.sh';
        $this->tmp = TempDir::create('nordvarr-agent-');
        exec('cp -R ' . escapeshellarg($this->fixtures . '/root') . ' ' . escapeshellarg($this->tmp . '/root'));
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    private function agent(string $arg, array $env = []): array
    {
        $env += [
            'PATH' => $this->fixtures . '/bin:/usr/local/bin:/usr/bin:/bin',
            'NORDVARR_ROOT' => $this->tmp . '/root',
            'NORDVARR_STATE_DIR' => $this->tmp . '/state',
            'NORDVARR_HOSTNAME' => 'gpu1.example.com',
            'NORDVARR_CONF' => $this->tmp . '/neexistuje.conf',
            'NORDVARR_SMARTCTL' => $this->fixtures . '/bin/smartctl',
            'NORDVARR_NVIDIA_SMI' => $this->fixtures . '/bin/nvidia-smi',
            'NORDVARR_DOCKER' => $this->fixtures . '/bin/docker',
            'NORDVARR_SYSTEMCTL' => $this->fixtures . '/bin/systemctl',
            'LC_ALL' => 'C',
        ];
        $process = proc_open(['bash', $this->script, ...($arg === '' ? [] : [$arg])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @return array<string, mixed>
     */
    private function dryRun(array $env = []): array
    {
        [$code, $out, $err] = $this->agent('--dry-run', $env);
        self::assertSame(0, $code, $err);

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSyntaxAndVersion(): void
    {
        exec('bash -n ' . escapeshellarg($this->script) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame([0, "nordvarr-agent 1.3.0\n"], array_slice($this->agent('--version'), 0, 2));
    }

    public function testCollectsMetricsAndRatesFromTwoRuns(): void
    {
        $first = $this->dryRun();
        self::assertSame('gpu1.example.com', $first['hostname']);
        self::assertSame('Debian GNU/Linux 13 (trixie)', $first['os']);
        self::assertSame('6.12.0-example-amd64', $first['kernel']);
        self::assertSame(864000, $first['uptime_s']);
        self::assertNull($first['cpu']['usage_pct'], 'První běh nemá s čím porovnat.');
        self::assertSame([4, 'Example CPU 4C', [1.5, 1.2, 0.9]], [$first['cpu']['cores'], $first['cpu']['model'], $first['cpu']['load']]);
        self::assertSame(['total_b' => 67108864000, 'available_b' => 16777216000, 'swap_total_b' => 8388608000, 'swap_free_b' => 6291456000], $first['memory']);
        self::assertSame(['/', '/mnt/zalohy disk'], array_column($first['disks'], 'mount'), 'tmpfs se přeskočí, mezera v cestě vadí.');
        self::assertSame([1000000, 990000], [$first['disks'][1]['inodes_total'], $first['disks'][1]['inodes_used']]);
        self::assertSame(['Package id 0', 'Core 0', 'Composite'], array_column($first['temperatures'], 'label'));
        self::assertEquals([52, 84, 100], [$first['temperatures'][0]['c'], $first['temperatures'][0]['max_c'], $first['temperatures'][0]['crit_c']]);
        self::assertCount(2, $first['gpus']);
        self::assertEquals([0, 87, 74, 285.4], [$first['gpus'][0]['index'], $first['gpus'][0]['util_pct'], $first['gpus'][0]['temp_c'], $first['gpus'][0]['power_w']]);
        self::assertNull($first['gpus'][1]['power_w'], '[N/A] -> null.');
        self::assertSame(['eth0'], array_column($first['network'], 'iface'), 'lo a veth se přeskočí.');
        self::assertNull($first['network'][0]['rx_bps']);
        self::assertCount(3, $first['smart']);

        // O minutu později: CPU 50 % (900 z 1800 tiků nečinných), eth0 1 MB/s dovnitř, 100 kB/s ven.
        $root = $this->tmp . '/root/proc';
        file_put_contents($root . '/stat', "cpu  10600 0 5300 80900 1000 0 0 0 0 0\n");
        file_put_contents($root . '/uptime', "864060.50 1700100.00\n");
        $dev = (string) file_get_contents($root . '/net/dev');
        file_put_contents($root . '/net/dev', str_replace(['1000000000 900000', '200000000 500000'], ['1060000000 950000', '206000000 510000'], $dev));
        $second = $this->dryRun();
        self::assertEquals(50.0, $second['cpu']['usage_pct']);
        self::assertEquals([1000000, 100000], [$second['network'][0]['rx_bps'], $second['network'][0]['tx_bps']]);

        // Server hlášení přijme a SMART rozebere.
        $report = Report::normalize($second, $second['collected_at']);
        self::assertSame(['/dev/sda', '/dev/sdb', '/dev/nvme0'], array_column($report['smart'], 'device'));
        self::assertFalse($report['smart'][1]['health_passed']);
        self::assertSame(95.0, Report::sample($report['snapshot'])['disk_max_pct']);
    }

    public function testDockerContainers(): void
    {
        $data = $this->dryRun();
        self::assertSame(['shop-api', 'shop-redis', 'queue-worker', 'shop-migrate', 'nightly-backup', 'grafana'], array_column($data['containers'], 'Names'));
        self::assertArrayNotHasKey('containers_error', $data);

        // Server výstup dockeru rozebere: stav, healthcheck, návratový kód, projekt compose.
        $containers = Report::normalize($data, $data['collected_at'])['containers'];
        self::assertSame(['name' => 'shop-api', 'image' => 'example/shop-api:1.4', 'state' => 'running', 'health' => 'healthy', 'exit_code' => null, 'project' => 'shop', 'status' => 'Up 2 hours (healthy)'], $containers[0]);
        self::assertSame([0, 137], [$containers[3]['exit_code'], $containers[4]['exit_code']]);
        self::assertSame(['restarting', 'unhealthy'], [$containers[2]['state'], $containers[5]['health']]);

        // Vypnuté hlášení kontejnerů, docker bez přístupu, docker vůbec není.
        self::assertArrayNotHasKey('containers', $this->dryRun(['NORDVARR_DOCKER' => '0']));
        $denied = $this->dryRun(['NORDVARR_DOCKER' => '/bin/false']);
        self::assertSame('docker_unavailable', $denied['containers_error']);
        self::assertArrayNotHasKey('containers', $denied);
        $none = $this->dryRun(['NORDVARR_DOCKER' => $this->tmp . '/nic/docker']);
        self::assertArrayNotHasKey('containers', $none);
        self::assertArrayNotHasKey('containers_error', $none);
    }

    public function testServicesAndLogs(): void
    {
        $data = $this->dryRun();
        self::assertSame(['httpd', 'mariadb', 'php-fpm', 'sshd'], array_column($data['services'], 'name'), 'Vypnutá postgresql a neznámá služba se nehlásí.');
        self::assertSame(['failed', 'failed', 3], [$data['services'][1]['active'], $data['services'][1]['sub'], $data['services'][1]['restarts']]);
        self::assertSame(['/var/log/httpd/error_log', '/var/log/httpd/access_log'], array_column($data['logs'], 'path'));
        self::assertCount(50, explode("\n", $data['logs'][1]['lines']), 'Posledních 50 řádků.');

        // Server tajné hodnoty v logu zamaskuje a služby vyhodnotí.
        $report = Report::normalize($data, $data['collected_at']);
        self::assertStringNotContainsString('a8f3k2m9q7x1z5c4v6b8n0', $report['logs'][1]['lines']);
        self::assertStringContainsString('PHP Fatal error', $report['logs'][0]['lines']);
        self::assertSame(['active', 'failed'], array_column(array_slice($report['snapshot']['services'], 0, 2), 'active'));

        // Výslovný seznam služeb, vypnuté služby a logy, interval logů.
        $explicit = $this->dryRun(['NORDVARR_SERVICES' => 'postgresql httpd']);
        self::assertSame(['postgresql', 'httpd'], array_column($explicit['services'], 'name'), 'Výslovně zadaná služba se hlásí i vypnutá.');
        $off = $this->dryRun(['NORDVARR_SERVICES' => '0', 'NORDVARR_LOGS' => '0']);
        self::assertArrayNotHasKey('services', $off);
        self::assertArrayNotHasKey('logs', $off);
        self::assertArrayNotHasKey('logs', $this->dryRun(['NORDVARR_LOG_INTERVAL' => '0']));
    }

    public function testMissingSmartctlAndSmartInterval(): void
    {
        self::assertSame('smartctl_missing', $this->dryRun(['NORDVARR_SMARTCTL' => $this->tmp . '/nic/smartctl'])['smart_error']);
        $off = $this->dryRun(['NORDVARR_SMART_INTERVAL' => '0']);
        self::assertArrayNotHasKey('smart', $off);
        self::assertArrayNotHasKey('smart_error', $off);
    }

    public function testConfigFileIsReadNotExecuted(): void
    {
        $marker = $this->tmp . '/spusteno';
        file_put_contents($this->tmp . '/agent.conf', implode("\n", [
            '# komentář',
            'NORDVARR_HOSTNAME="srv7.example.net"',
            "NORDVARR_EXTERNAL_ID='host:srv7'",
            'NORDVARR_OBJECT_ID=$(touch ' . $marker . ')',
            'PATH=/nikam',
            'NORDVARR_SMART_INTERVAL=0',
        ]) . "\n");
        $data = $this->dryRun(['NORDVARR_CONF' => $this->tmp . '/agent.conf', 'NORDVARR_HOSTNAME' => '']);
        self::assertSame('srv7.example.net', $data['hostname']);
        self::assertSame('host:srv7', $data['external_id']);
        self::assertSame('$(touch ' . $marker . ')', $data['object_id'], 'Hodnota se bere doslova.');
        self::assertFileDoesNotExist($marker);
        self::assertCount(2, $data['disks'], 'Neznámý klíč (PATH) se ignoruje.');
    }

    public function testSendsReportWithTokenInHeader(): void
    {
        $servers = new FixtureServers();
        $mockDir = TempDir::create('nordvarr-agent-mock-');
        try {
            $base = $servers->mockEndpoint($mockDir);
            [$code, $out, $err] = $this->agent('--test', ['NORDVARR_URL' => $base . '/', 'NORDVARR_TOKEN' => 'nv_0123456789ab_tajny-token']);
            self::assertSame(0, $code, $err);
            self::assertStringStartsWith('OK: {"object_id":"mock"', $out);
            $files = glob($mockDir . '/*.json') ?: [];
            self::assertCount(1, $files);
            $request = json_decode((string) file_get_contents($files[0]), true);
            self::assertSame(['POST', '/api/v1/agent/report'], [$request['method'], $request['path']]);
            self::assertSame('Bearer nv_0123456789ab_tajny-token', $request['headers']['authorization']);
            self::assertSame('application/json', $request['headers']['content-type']);
            self::assertSame('gpu1.example.com', json_decode($request['body'], true)['hostname']);
            self::assertFileDoesNotExist($this->tmp . '/state/headers', 'Soubor s tokenem se po odeslání smaže.');
            self::assertFileExists($this->tmp . '/state/smart-at');

            // Nedostupný server: kód 2, chyba v last-error, SMART se zkusí znovu.
            [$code, , $err] = $this->agent('', ['NORDVARR_URL' => 'http://127.0.0.1:' . FixtureServers::freePort(), 'NORDVARR_TOKEN' => 'x']);
            self::assertSame(2, $code);
            self::assertStringContainsString('Odeslání selhalo', $err);
            self::assertFileExists($this->tmp . '/state/last-error');
            self::assertFileDoesNotExist($this->tmp . '/state/smart-at');
        } finally {
            $servers->stop();
            TempDir::remove($mockDir);
        }
    }

    public function testLogPathsFromServerOnlyFromAllowedDirectories(): void
    {
        $root = $this->tmp . '/root';
        mkdir($root . '/var/www/vhosts/example_com', 0o777, true);
        is_dir($root . '/etc') || mkdir($root . '/etc');
        file_put_contents($root . '/var/www/vhosts/example_com/access.log', "203.0.113.5 - - \"GET / HTTP/1.1\" 200\n");
        file_put_contents($root . '/etc/tajne', "nesmi-odejit\n");
        symlink($root . '/etc/tajne', $root . '/var/www/vhosts/example_com/odkaz.log');

        $servers = new FixtureServers();
        $mockDir = TempDir::create('nordvarr-agent-mock-');
        try {
            $base = $servers->mockEndpoint($mockDir);
            $paths = ['/var/www/vhosts/example_com/access.log', '/etc/tajne', '/var/www/vhosts/example_com/odkaz.log', '/var/www/neni.log', '/var/www/../etc/tajne'];
            file_put_contents($mockDir . '/log-paths.json', json_encode($paths, JSON_UNESCAPED_SLASHES));
            $env = ['NORDVARR_URL' => $base, 'NORDVARR_TOKEN' => 'nv_test'];
            [$code, , $err] = $this->agent('--test', $env);
            self::assertSame(0, $code, $err);
            self::assertSame(array_slice($paths, 0, 4), file($this->tmp . '/state/log-paths', FILE_IGNORE_NEW_LINES), 'Cesta s .. se neuloží.');
            self::assertFileDoesNotExist($this->tmp . '/state/logs-at', 'Nové cesty: logy se pošlou hned při dalším běhu.');

            $logs = array_column($this->dryRun()['logs'], 'lines', 'path');
            self::assertSame(['/var/log/httpd/error_log', '/var/log/httpd/access_log', '/var/www/vhosts/example_com/access.log'], array_keys($logs),
                'Soubor mimo povolené adresáře, odkaz ven a chybějící soubor se nečtou.');
            self::assertStringContainsString('GET / HTTP/1.1', $logs['/var/www/vhosts/example_com/access.log']);
            self::assertStringNotContainsString('nesmi-odejit', json_encode($logs));

            // Povolené adresáře jdou změnit v konfiguraci agenta.
            $allowed = array_column($this->dryRun(['NORDVARR_LOG_ALLOW' => '/etc'])['logs'], 'path');
            self::assertNotContains('/var/www/vhosts/example_com/access.log', $allowed);
            self::assertContains('/etc/tajne', $allowed);

            // Server cesty přestal posílat: agent je zapomene.
            unlink($mockDir . '/log-paths.json');
            [$code, , $err] = $this->agent('--test', $env);
            self::assertSame(0, $code, $err);
            self::assertFileDoesNotExist($this->tmp . '/state/log-paths');
        } finally {
            $servers->stop();
            TempDir::remove($mockDir);
        }
    }

    public function testRequiresUrlAndToken(): void
    {
        [$code, , $err] = $this->agent('');
        self::assertSame(1, $code);
        self::assertStringContainsString('Chybí NORDVARR_URL nebo NORDVARR_TOKEN', $err);
    }
}
