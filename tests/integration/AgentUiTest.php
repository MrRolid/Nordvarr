<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Agent\AgentService;
use Nordvarr\Export\LlmContext;
use Nordvarr\I18n;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Tests\AgentFixtures;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Time;
use Nordvarr\Topology\Topology;
use PHPUnit\Framework\TestCase;

/**
 * Server s agentem v UI: karta objektu, panel a popisek v topologii, export pro LLM,
 * formulář kontroly, návod v nastavení a veřejné soubory agenta.
 */
final class AgentUiTest extends TestCase
{
    use InstalledApp;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @return array{object_id: string, check_id: string}
     */
    private function seedHost(): array
    {
        $report = AgentFixtures::report(['smart' => [AgentFixtures::smart('smart-ata-ok'), AgentFixtures::smart('smart-ata-failing')]]);
        $service = new AgentService($this->pdo());
        for ($i = 0; $i < 3; $i++) {
            $result = $service->ingest($report, 'agent');
        }
        $repo = new AgentRepository($this->pdo());
        for ($m = 600; $m >= 10; $m -= 10) {
            $repo->insertSample($result['object_id'], Time::toDb(Time::now()->modify("-$m minutes")), ['cpu_pct' => 30.0, 'mem_pct' => 60.0, 'temp_max_c' => 55.0, 'gpu_util_max_pct' => 80.0]);
        }

        return ['object_id' => $result['object_id'], 'check_id' => $result['check_id']];
    }

    public function testObjectCardShowsServerSection(): void
    {
        ['object_id' => $id] = $this->seedHost();
        $page = $this->ui('GET', '/objects/' . $id)->body;
        self::assertStringContainsString('data-test="host"', $page);
        self::assertStringContainsString('Debian GNU/Linux 13, jádro 6.12.0-amd64', $page);
        self::assertMatchesRegularExpression('#<span>CPU</span><strong>38 %</strong>#', $page, 'Hodnota 37,5 zaokrouhlená na celé procento.');
        self::assertStringContainsString('<span>RAM</span><strong>63 %</strong>', $page);
        self::assertStringContainsString('<span>GPU 0</span><strong>92 %</strong>', $page);
        self::assertStringContainsString('40,0 GiB / 64,0 GiB', $page);
        self::assertMatchesRegularExpression('#<code>/data</code></td>\s*<td class="cell-bar"><svg class="bar bar-warn"[^>]*>.*?</svg> <span class="lvl-warn">88 %</span>#s', $page);
        self::assertStringContainsString('<svg class="chart"', $page);
        self::assertStringContainsString('class="chart-line line-gpu"', $page);
        self::assertStringContainsString('EXAMPLE HDD 4TB', $page);
        self::assertStringContainsString('selhává', $page);
        self::assertStringContainsString('přemapované sektory: 1872', $page);
        self::assertStringContainsString('data-test="host-problems"', $page);
        // Stav kontroly po 3 hlášeních a diagnostická hlavička.
        self::assertMatchesRegularExpression('#data-test="object-status">.*nefunguje.*Agent \(server\): <code>disk_failing</code>#s', $page);
        self::assertDoesNotMatchRegularExpression('/<[^>]+\sstyle=/i', $page, 'Žádné inline styly (CSP).');
    }

    public function testNoServerSectionWithoutAgent(): void
    {
        $id = (new \Nordvarr\Inventory\Inventory($this->pdo()))->objects->create(['type' => 'server', 'name' => 'bez-agenta.example.com']);
        self::assertStringNotContainsString('data-test="host"', $this->ui('GET', '/objects/' . $id)->body);
    }

    public function testStaleAgentIsMarked(): void
    {
        ['object_id' => $id] = $this->seedHost();
        $this->pdo()->exec("UPDATE agent_hosts SET last_report_at = '2026-01-01T00:00:00Z'");
        self::assertStringContainsString('data-test="host-stale"', $this->ui('GET', '/objects/' . $id)->body);
    }

    public function testTopologyNodeAndPanel(): void
    {
        ['object_id' => $id] = $this->seedHost();
        $graph = (new Topology($this->pdo(), new I18n($this->app . '/lang', 'cs')))->neighborhood($id, 1);
        self::assertSame("CPU 38 %\nRAM 63 %\nGPU 92 %", $graph['nodes'][0]['data']['subtitle']);
        self::assertTrue($graph['nodes'][0]['data']['host']);
        // Pruhy prostředků v mapě: disk /data je nad prahem pro omezeně (85 %).
        $resources = $graph['nodes'][0]['data']['resources'];
        self::assertFalse($resources['stale']);
        self::assertSame(['cpu' => 'ok', 'mem' => 'ok', 'disk' => 'warn', 'gpu' => 'none'], array_column($resources['items'], 'level', 'key'));

        $html = json_decode($this->ui('GET', '/topology/node/' . $id)->body, true)['html'];
        self::assertStringContainsString('data-test="node-host"', $html);
        self::assertStringContainsString('<dt>GPU 0</dt>', $html);
        self::assertStringContainsString('Disky (2): 1 s problémem podle SMART.', $html);
    }

    public function testLlmExportContainsServer(): void
    {
        ['object_id' => $id] = $this->seedHost();
        $context = new LlmContext($this->pdo(), new I18n($this->app . '/lang', 'cs'), 'Europe/Prague');
        $data = $context->build($id);
        self::assertSame(37.5, $data['host']['cpu_pct']);
        self::assertSame(['/', '/data'], array_column($data['host']['disks'], 'mount'));
        $markdown = $context->markdown($data);
        self::assertStringContainsString('## Server (hostový agent)', $markdown);
        self::assertStringContainsString('- CPU 37.5 % (16 jader, load 2.5 / 2.1 / 1.9), RAM 62.5 %, swap 0 %', $markdown);
        self::assertStringContainsString('- Disk /data: zaplněno 87.5 %, inody -', $markdown);
        self::assertStringContainsString('- GPU 0 NVIDIA Example 24GB: 92 %, VRAM 73.2 %, 71 °C, 280.5 W', $markdown);
        self::assertStringContainsString('SMART /dev/sdb EXAMPLE HDD 4TB: selhává, opotřebení -, v provozu 52300 h: SMART hlásí selhávající disk', $markdown);
        self::assertLessThanOrEqual(LlmContext::MAX_BYTES, strlen($markdown));
    }

    public function testAgentCheckFormValidatesLimits(): void
    {
        ['check_id' => $checkId] = $this->seedHost();
        $form = $this->ui('GET', '/checks/' . $checkId . '/edit')->body;
        self::assertStringContainsString('name="cfg[agent][disk_unhealthy_pct]"', $form);
        $base = ['name' => '', 'interval_s' => '60', 'timeout_s' => '10', 'failure_threshold' => '3', 'enabled' => '1', 'notify_on_degraded' => '1'];
        $cfg = ['grace_s' => '180', 'cpu_pct' => '95', 'mem_pct' => '95', 'disk_degraded_pct' => '90', 'disk_unhealthy_pct' => '80',
            'temp_degraded_c' => '85', 'temp_unhealthy_c' => '95', 'wear_degraded_pct' => '80'];
        $bad = $this->post('/checks/' . $checkId . '/edit', $base + ['cfg' => ['agent' => $cfg]]);
        self::assertSame(422, $bad->status);
        $cfg['disk_unhealthy_pct'] = '97';
        self::assertSame(303, $this->post('/checks/' . $checkId . '/edit', $base + ['cfg' => ['agent' => $cfg]])->status);
        self::assertSame(97, (new CheckRepository($this->pdo()))->find($checkId)['config']['disk_unhealthy_pct']);
    }

    public function testSettingsGuideAndPublicAgentFiles(): void
    {
        (new SettingsRepository($this->pdo()))->set('base_url', 'https://nordvarr.example.com');
        self::assertStringContainsString('data-test="agent-guide"', $this->ui('GET', '/settings')->body);
        $guide = $this->ui('GET', '/settings/agent')->body;
        self::assertStringContainsString('curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.sh -o /usr/local/bin/nordvarr-agent.sh', $guide);
        self::assertStringContainsString('NORDVARR_URL=https://nordvarr.example.com', $guide);
        self::assertStringContainsString('agent:write', $this->ui('GET', '/settings')->body, 'Scope je v nabídce tokenů.');

        // Soubory agenta jdou stáhnout bez přihlášení, jiné soubory ne.
        $this->session = \Nordvarr\Http\Session::inMemory();
        $script = $this->ui('GET', '/agent/nordvarr-agent.sh');
        self::assertSame(200, $script->status);
        self::assertStringStartsWith('#!/usr/bin/env bash', $script->body);
        self::assertStringStartsWith('text/plain', $script->headers['Content-Type']);
        self::assertSame('nosniff', $script->headers['X-Content-Type-Options']);
        self::assertSame(200, $this->ui('GET', '/agent/nordvarr-agent.timer')->status);
        self::assertSame(303, $this->ui('GET', '/agent/../config.php')->status);
        self::assertSame(303, $this->ui('GET', '/agent/nordvarr-agent.php')->status, 'Mimo seznam -> přihlášení.');
    }
}
