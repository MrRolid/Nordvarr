<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use DateTimeImmutable;
use Nordvarr\Agent\AgentService;
use Nordvarr\Api\ApiTokens;
use Nordvarr\Checks\CurlRunner;
use Nordvarr\Checks\PingRunner;
use Nordvarr\Checks\RdapRunner;
use Nordvarr\Checks\Worker;
use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\HttpCacheRepository;
use Nordvarr\Tests\AgentFixtures;
use Nordvarr\Tests\FixtureHttpClient;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Tests\TempDir;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

/**
 * Hostový agent přes API: založení serveru a kontroly, vyhodnocení, chybějící hlášení,
 * agregace a retence, migrace 0005.
 */
final class AgentApiTest extends TestCase
{
    use InstalledApp;

    private string $token;

    protected function setUp(): void
    {
        $this->install();
        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $this->token = $tokens->issue('Agent', 'agent', ['agent:write'], null)['token'];
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function report(array $override = []): array
    {
        return AgentFixtures::report($override);
    }

    private function smart(string $name): array
    {
        return AgentFixtures::smart($name);
    }

    private function worker(): Worker
    {
        return new Worker(
            $this->pdo(),
            new CurlRunner('Nordvarr-test'),
            new PingRunner(dirname(__DIR__) . '/fixtures/fake-ping'),
            new RdapRunner(new FixtureHttpClient(), new HttpCacheRepository($this->pdo()), 'https://bootstrap.example.net/dns.json'),
        );
    }

    public function testFirstReportCreatesServerAndAgentCheck(): void
    {
        $healthyDisks = ['disks' => [AgentFixtures::report()['disks'][0]]];
        $response = $this->api('POST', '/api/v1/agent/report', $this->report($healthyDisks + ['smart' => [$this->smart('smart-ata-ok')]]), $this->token);
        self::assertSame(200, $response->status, $response->body);
        $body = self::json($response);
        self::assertTrue($body['created']);
        self::assertSame('healthy', $body['status']);

        $inventory = new Inventory($this->pdo());
        $object = $inventory->objects->find($body['object_id']);
        self::assertSame(['server', 'gpu1.example.com'], [$object['type'], $object['name']]);
        self::assertSame($body['object_id'], $inventory->externalIds->objectId('agent', 'host:gpu1.example.com'));

        $check = (new CheckRepository($this->pdo()))->forObject($body['object_id'])[0];
        self::assertSame(['agent', 3, true], [$check['type'], $check['failure_threshold'], (bool) $check['notify_on_degraded']]);
        self::assertSame(85, $check['config']['disk_degraded_pct']);

        $host = (new AgentRepository($this->pdo()))->host($body['object_id']);
        self::assertSame('1.0.0', $host['agent_version']);
        self::assertSame('EXAMPLE SSD 1TB', $host['smart'][0]['model']);
        self::assertNotNull($host['smart_at']);

        // Druhé hlášení bez SMART: stejný objekt, SMART zůstane.
        $again = self::json($this->api('POST', '/api/v1/agent/report', $this->report(), $this->token));
        self::assertFalse($again['created']);
        self::assertSame($body['object_id'], $again['object_id']);
        self::assertSame('EXAMPLE SSD 1TB', (new AgentRepository($this->pdo()))->host($body['object_id'])['smart'][0]['model']);
        self::assertCount(1, (new CheckRepository($this->pdo()))->forObject($body['object_id']), 'Kontrola se nezakládá znovu.');
    }

    public function testReportAttachesToExistingObject(): void
    {
        $inventory = new Inventory($this->pdo());
        $id = $inventory->objects->create(['type' => 'server', 'name' => 'hv1.example.net']);
        $body = self::json($this->api('POST', '/api/v1/agent/report', $this->report(['object_id' => $id, 'hostname' => 'hv1']), $this->token));
        self::assertFalse($body['created']);
        self::assertSame($id, $body['object_id']);
        self::assertSame('hv1.example.net', $inventory->objects->find($id)['name'], 'Název objektu se nepřepisuje.');

        $other = $inventory->objects->create(['type' => 'server', 'name' => 'jiny.example.net']);
        $conflict = $this->api('POST', '/api/v1/agent/report', $this->report(['object_id' => $other, 'hostname' => 'hv1']), $this->token);
        self::assertSame(422, $conflict->status, 'external_id už patří jinému objektu.');
        self::assertSame(422, $this->api('POST', '/api/v1/agent/report', $this->report(['object_id' => '00000000-0000-4000-8000-000000000000']), $this->token)->status);
    }

    public function testAuthAndValidation(): void
    {
        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $writer = $tokens->issue('Ansible', 'ansible', ['inventory:write'], null)['token'];
        self::assertSame(401, $this->api('POST', '/api/v1/agent/report', $this->report())->status);
        self::assertSame(403, $this->api('POST', '/api/v1/agent/report', $this->report(), $writer)->status);
        $invalid = $this->api('POST', '/api/v1/agent/report', $this->report(['hostname' => 'bad host']), $this->token);
        self::assertSame(422, $invalid->status);
        self::assertSame('invalid_hostname', self::json($invalid)['fields']['hostname']);
    }

    public function testProblemsNeedThreeReportsAndThenOpenAlert(): void
    {
        $failing = $this->report(['smart' => [$this->smart('smart-ata-failing')]]);
        $first = self::json($this->api('POST', '/api/v1/agent/report', $failing, $this->token));
        self::assertSame('unhealthy', $first['status']);
        $checkId = $first['check_id'];
        $checks = new CheckRepository($this->pdo());
        self::assertSame('unknown', $checks->state($checkId)['status'], 'Práh 3 hlášení po sobě.');
        $this->api('POST', '/api/v1/agent/report', $this->report(), $this->token);
        $this->api('POST', '/api/v1/agent/report', $this->report(), $this->token);
        $state = $checks->state($checkId);
        self::assertSame('unhealthy', $state['status'], 'Uložený SMART stav platí i pro hlášení bez SMART.');
        self::assertSame('disk_failing', $state['reason_code']);
        self::assertStringContainsString('/dev/sdb: SMART hlásí selhávající disk', $state['reason_detail']);

        $this->worker()->run();
        \Nordvarr\Alerts\Notifier::fromConfig($this->pdo(), $this->app, $this->config())->run();
        $alert = $this->pdo()->query("SELECT * FROM alerts WHERE status = 'open'")->fetch();
        self::assertSame(['agent', 'disk_failing'], [$alert['check_type'], $alert['reason_code']]);
    }

    public function testWorkerReportsMissingAgent(): void
    {
        $body = self::json($this->api('POST', '/api/v1/agent/report', $this->report(), $this->token));
        $checks = new CheckRepository($this->pdo());
        $service = new AgentService($this->pdo());
        $check = $checks->find($body['check_id']);
        self::assertNull($service->evaluateMissing($check, Time::now()->modify('+200 seconds')), 'Interval 60 s + tolerance 180 s.');
        $result = $service->evaluateMissing($check, Time::now()->modify('+241 seconds'));
        self::assertNotNull($result);
        self::assertSame(['unhealthy', 'agent_missing'], [$result->status, $result->reasonCode]);
        self::assertStringStartsWith('poslední hlášení ', $result->detail);

        // Přes worker: posunout termín i poslední hlášení do minulosti.
        $this->pdo()->exec("UPDATE agent_hosts SET last_report_at = '2026-01-01T00:00:00Z'");
        $this->pdo()->prepare('UPDATE check_state SET next_run_at = ? WHERE check_id = ?')->execute(['2026-01-01T00:00:00Z', $body['check_id']]);
        $this->pdo()->prepare('UPDATE checks SET failure_threshold = 1 WHERE id = ?')->execute([$body['check_id']]);
        $this->worker()->run();
        self::assertSame('agent_missing', $checks->state($body['check_id'])['reason_code']);
    }

    public function testSamplesAggregateAndRetention(): void
    {
        $repo = new AgentRepository($this->pdo());
        $id = (new Inventory($this->pdo()))->objects->create(['type' => 'server', 'name' => 'srv.example.com']);
        $repo->insertSample($id, '2026-10-20T10:05:00Z', ['cpu_pct' => 10.0, 'mem_pct' => 50.0, 'temp_max_c' => 40.0]);
        $repo->insertSample($id, '2026-10-20T10:35:00Z', ['cpu_pct' => 30.0, 'mem_pct' => 70.0, 'temp_max_c' => 60.0]);
        $repo->insertSample($id, '2026-10-20T11:05:00Z', ['cpu_pct' => 50.0]);
        $repo->aggregate('2026-10-20T00:00:00Z');
        $hourly = $repo->hourly($id, '2026-10-20T00:00:00Z');
        self::assertSame(['2026-10-20T10:00:00Z', '2026-10-20T11:00:00Z'], array_column($hourly, 'hour'));
        self::assertSame([2, 20.0, 30.0, 60.0, 60.0], [(int) $hourly[0]['count'], (float) $hourly[0]['cpu_avg'], (float) $hourly[0]['cpu_max'], (float) $hourly[0]['mem_avg'], (float) $hourly[0]['temp_max']]);

        self::assertSame(2, $repo->purgeSamples('2026-10-20T11:00:00Z'));
        self::assertSame(1, $repo->purgeHourly('2026-10-20T11:00:00Z'));
    }

    public function testMigrationKeepsChecksResultsAndAlerts(): void
    {
        $dir = TempDir::create('nordvarr-mig-');
        try {
            $pdo = Database::connectSqlite($dir . '/db.sqlite');
            $migrations = TempDir::create('nordvarr-mig-sql-');
            foreach (glob(dirname(__DIR__, 2) . '/migrations/000[1-4]_*.sql') ?: [] as $file) {
                copy($file, $migrations . '/' . basename($file));
            }
            (new Migrator($pdo, $migrations))->migrate();
            $pdo->exec("INSERT INTO objects (id, type, name, slug, description, lifecycle, created_at, updated_at)
                VALUES ('o1', 'server', 'srv', 'srv', '', 'active', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
            $pdo->exec("INSERT INTO checks (id, object_id, type, name, config, interval_s, timeout_s, failure_threshold, enabled, notify_on_degraded, created_at, updated_at)
                VALUES ('c1', 'o1', 'tcp', '', '{}', 60, 10, 2, 1, 0, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
            $pdo->exec("INSERT INTO check_state (check_id, status, since) VALUES ('c1', 'healthy', '2026-01-01T00:00:00Z')");
            $pdo->exec("INSERT INTO check_results (check_id, started_at, finished_at, status) VALUES ('c1', '2026-01-01T00:00:00Z', '2026-01-01T00:00:01Z', 'healthy')");
            $pdo->exec("INSERT INTO alerts (id, check_id, object_id, status, level, check_type, opened_at) VALUES ('a1', 'c1', 'o1', 'open', 'unhealthy', 'tcp', '2026-01-01T00:00:00Z')");

            copy(dirname(__DIR__, 2) . '/migrations/0005_agent.sql', $migrations . '/0005_agent.sql');
            self::assertSame(['0005'], (new Migrator($pdo, $migrations))->migrate());
            foreach (['checks', 'check_state', 'check_results', 'alerts'] as $table) {
                self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
            }
            self::assertSame('c1', $pdo->query('SELECT check_id FROM alerts')->fetchColumn());
            self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'Cizí klíče jsou po migraci znovu zapnuté.');
            $pdo->exec("INSERT INTO checks (id, object_id, type, name, config, interval_s, timeout_s, failure_threshold, enabled, notify_on_degraded, created_at, updated_at)
                VALUES ('c2', 'o1', 'agent', '', '{}', 60, 10, 3, 1, 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
            $pdo->exec("DELETE FROM objects WHERE id = 'o1'");
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM check_results')->fetchColumn(), 'Kaskáda po přestavbě funguje.');
            TempDir::remove($migrations);
        } finally {
            TempDir::remove($dir);
        }
    }

    public function testReportAtUsesServerTimeWhenAgentClockIsOff(): void
    {
        $body = self::json($this->api('POST', '/api/v1/agent/report', $this->report(['collected_at' => '2001-01-01T00:00:00Z']), $this->token));
        $host = (new AgentRepository($this->pdo()))->host($body['object_id']);
        self::assertGreaterThan('2026-01-01', $host['last_report_at']);
        self::assertLessThan(new DateTimeImmutable('+1 minute'), new DateTimeImmutable($host['last_report_at']));
    }
}
