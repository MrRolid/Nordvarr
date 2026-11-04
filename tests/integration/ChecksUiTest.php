<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\App;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

final class ChecksUiTest extends TestCase
{
    use InstalledApp;

    private string $objectId;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
        $this->objectId = (new Inventory($this->pdo()))->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function createCheck(string $type, array $cfg, array $common = []): Response
    {
        return $this->post('/objects/' . $this->objectId . '/checks', $common + [
            'type' => $type, 'name' => '', 'interval_s' => '60', 'timeout_s' => '10', 'failure_threshold' => '1',
            'enabled' => '1', 'notify_on_degraded' => '0', 'cfg' => [$type => $cfg],
        ]);
    }

    private function checkId(Response $response): string
    {
        self::assertSame(303, $response->status, $response->body);
        preg_match('#/checks/([0-9a-f-]{36})$#', $response->headers['Location'], $m);

        return $m[1];
    }

    private function failCheck(string $checkId, string $reason = 'tcp_refused', string $status = 'unhealthy'): void
    {
        $service = new CheckService($this->pdo());
        $at = Time::toDb(Time::now()->modify('-3 minutes'));
        $service->record((array) $service->checks->find($checkId), new CheckResult($status, $reason, 'spojení odmítnuto', null, $at, $at));
    }

    public function testCreateCheckWithValidation(): void
    {
        $bad = $this->createCheck('http', ['url' => 'ftp://example.com/', 'method' => 'GET', 'expected_status' => '200-399']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Zadejte platnou adresu', $bad->body);

        $id = $this->checkId($this->createCheck('http', ['url' => 'https://shop.example.com/', 'method' => 'GET', 'expected_status' => '200-399', 'contains' => '', 'latency_degraded_ms' => '0']));
        $detail = $this->ui('GET', '/checks/' . $id)->body;
        self::assertStringContainsString('GET https://shop.example.com/', $detail);
        self::assertStringContainsString('data-status="unknown"', $detail);
        self::assertContains('check.create', array_column((new AuditLogRepository($this->pdo()))->latest(), 'action'));

        $form = $this->ui('GET', '/objects/' . $this->objectId . '/checks/new', [], ['type' => 'tls_expiry'])->body;
        self::assertStringContainsString('value="tls_expiry" selected', $form);
    }

    public function testObjectStatusHeaderAndInventoryFilter(): void
    {
        $id = $this->checkId($this->createCheck('tcp', ['host' => '127.0.0.1', 'port' => '9']));
        $this->failCheck($id);

        $object = $this->ui('GET', '/objects/' . $this->objectId)->body;
        self::assertMatchesRegularExpression('#data-test="object-status">\s*<span class="status status-unhealthy"#', $object);
        self::assertStringContainsString('TCP: <code>tcp_refused</code>, 3 min', $object);

        $other = (new Inventory($this->pdo()))->objects->create(['type' => 'server', 'name' => 'bez-kontrol.example.com']);
        $unhealthy = $this->ui('GET', '/inventory', [], ['status' => 'unhealthy'])->body;
        self::assertStringContainsString('shop.example.com', $unhealthy);
        self::assertStringNotContainsString('bez-kontrol.example.com', $unhealthy);
        self::assertStringContainsString('bez-kontrol.example.com', $this->ui('GET', '/inventory', [], ['status' => 'none'])->body);
        self::assertNotSame('', $other);
    }

    public function testDependencyStatusOnObjectHeader(): void
    {
        $inventory = new Inventory($this->pdo());
        $db = $inventory->objects->create(['type' => 'database', 'name' => 'db1.example.com']);
        $inventory->relate($this->objectId, $db, 'depends_on', '', 'manual');
        $this->objectId = $db;
        $this->failCheck($this->checkId($this->createCheck('tcp', ['host' => '127.0.0.1', 'port' => '5432'])));
        $web = $this->pdo()->query("SELECT id FROM objects WHERE name = 'shop.example.com'")->fetchColumn();
        $html = $this->ui('GET', '/objects/' . $web)->body;
        self::assertMatchesRegularExpression('#data-test="dependencies">.*db1\.example\.com</a> <span class="status status-unhealthy"#s', $html);
    }

    public function testToggleRunNowAndDelete(): void
    {
        $id = $this->checkId($this->createCheck('ping', ['host' => '192.0.2.1']));
        $this->post('/checks/' . $id . '/toggle');
        self::assertStringContainsString('data-status="paused"', $this->ui('GET', '/checks/' . $id)->body);
        $this->post('/checks/' . $id . '/toggle');
        $this->post('/checks/' . $id . '/run');
        self::assertSame(['check.scheduled', []], $this->session->get('flash'));

        $this->post('/checks/' . $id . '/delete');
        self::assertSame(200, $this->ui('GET', '/checks/' . $id)->status);
        $this->post('/checks/' . $id . '/delete', ['confirm' => '1']);
        self::assertSame(404, $this->ui('GET', '/checks/' . $id)->status);
        self::assertContains('check.delete', array_column((new AuditLogRepository($this->pdo()))->latest(), 'action'));
    }

    public function testEditKeepsTypeAndValidates(): void
    {
        $id = $this->checkId($this->createCheck('tcp', ['host' => '127.0.0.1', 'port' => '22']));
        $form = ['name' => 'ssh', 'interval_s' => '120', 'timeout_s' => '5', 'failure_threshold' => '3', 'enabled' => '1', 'notify_on_degraded' => '0'];
        $bad = $this->post('/checks/' . $id . '/edit', $form + ['cfg' => ['tcp' => ['host' => '127.0.0.1', 'port' => '0']]]);
        self::assertSame(422, $bad->status);
        $this->post('/checks/' . $id . '/edit', $form + ['type' => 'http', 'cfg' => ['tcp' => ['host' => '127.0.0.1', 'port' => '2222']]]);
        $row = $this->pdo()->query('SELECT type, name, interval_s, failure_threshold, config FROM checks')->fetch();
        self::assertSame(['tcp', 'ssh', 120, 3, '{"host":"127.0.0.1","port":2222}'], [$row['type'], $row['name'], (int) $row['interval_s'], (int) $row['failure_threshold'], $row['config']]);
    }

    public function testDomainExpiryWithManualDate(): void
    {
        $cfg = ['domain' => 'example.eu', 'degraded_days' => '30', 'unhealthy_days' => '7'];
        $bad = $this->createCheck('domain_expiry', $cfg + ['expires_on' => '2027-02-30']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Neplatné datum.', $bad->body);

        $id = $this->checkId($this->createCheck('domain_expiry', $cfg + ['expires_on' => '2027-03-31']));
        $config = json_decode((string) $this->pdo()->query('SELECT config FROM checks')->fetchColumn(), true);
        self::assertSame('2027-03-31', $config['expires_on']);
        $form = $this->ui('GET', '/checks/' . $id . '/edit')->body;
        self::assertStringContainsString('name="cfg[domain_expiry][expires_on]" type="date" value="2027-03-31"', $form);

        // Vymazání data ho z konfigurace odstraní.
        $edit = ['name' => '', 'interval_s' => '86400', 'timeout_s' => '15', 'failure_threshold' => '1', 'enabled' => '1', 'notify_on_degraded' => '1'];
        $this->post('/checks/' . $id . '/edit', $edit + ['cfg' => ['domain_expiry' => $cfg + ['expires_on' => '']]]);
        $config = json_decode((string) $this->pdo()->query('SELECT config FROM checks')->fetchColumn(), true);
        self::assertArrayNotHasKey('expires_on', $config);
    }

    public function testHeartbeatUrlIsShownOnCheckPage(): void
    {
        $id = $this->checkId($this->createCheck('heartbeat', ['grace_s' => '600'], ['interval_s' => '3600']));
        $app = new App($this->app, $this->config());
        $page = $app->handle(new Request('GET', '/checks/' . $id, [], [], [], ['host' => 'nordvarr.example.com'], '', true, true, '192.0.2.50'), $this->session)->body;
        $token = $this->pdo()->query('SELECT heartbeat_token FROM checks')->fetchColumn();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $token);
        self::assertStringContainsString('https://nordvarr.example.com/hb/' . $token, $page);
    }

    public function testOverviewSectionsAndJsonEtag(): void
    {
        $tcp = $this->checkId($this->createCheck('tcp', ['host' => '127.0.0.1', 'port' => '9']));
        $this->failCheck($tcp);
        $tls = $this->checkId($this->createCheck('tls_expiry', ['host' => 'shop.example.com', 'port' => '443', 'degraded_days' => '21', 'unhealthy_days' => '7']));
        $service = new CheckService($this->pdo());
        $now = Time::toDb(Time::now());
        $service->record((array) $service->checks->find($tls), new CheckResult('degraded', 'cert_expiring', 'Certifikát vyprší', null, $now, $now, Time::toDb(Time::now()->modify('+10 days +1 hour'))));
        (new Inventory($this->pdo()))->addManualEvent($this->objectId, 'deploy', 'release 42', '', Time::toDb(Time::now()->modify('-1 hour')));

        $page = $this->ui('GET', '/')->body;
        self::assertStringContainsString('data-refresh-url="/overview/data"', $page);
        self::assertMatchesRegularExpression('#data-test="down-now">.*shop\.example\.com.*<code>tcp_refused</code>, 3 min#s', $page);
        self::assertMatchesRegularExpression('#data-test="expiring">.*shop\.example\.com:443.*vyprší za 10 dní#s', $page);
        self::assertMatchesRegularExpression('#data-test="changes">.*release 42#s', $page);
        self::assertStringContainsString('data-test="summary-unhealthy">1<', $page);
        self::assertStringContainsString('data-test="worker-warning"', $page);

        (new SettingsRepository($this->pdo()))->set('worker_last_run', Time::toDb(Time::now()));
        $json = $this->ui('GET', '/overview/data');
        self::assertSame(200, $json->status);
        self::assertSame('application/json; charset=utf-8', $json->headers['Content-Type']);
        self::assertStringNotContainsString('worker-warning', self::json($json)['html']);
        $etag = $json->headers['ETag'];

        $app = new App($this->app, $this->config());
        $cached = $app->handle(new Request('GET', '/overview/data', [], [], [], ['if-none-match' => $etag], '', true, false, '192.0.2.50'), $this->session);
        self::assertSame(304, $cached->status);
        self::assertSame('', $cached->body);

        $this->failCheck($tcp, 'tcp_timeout');
        $changed = $app->handle(new Request('GET', '/overview/data', [], [], [], ['if-none-match' => $etag], '', true, false, '192.0.2.50'), $this->session);
        self::assertSame(200, $changed->status);
        self::assertStringContainsString('tcp_timeout', self::json($changed)['html']);
    }

    public function testWorkerWarningWhenStale(): void
    {
        (new SettingsRepository($this->pdo()))->set('worker_last_run', Time::toDb(Time::now()->modify('-4 minutes')));
        self::assertStringContainsString('Worker neběžel déle než 3 minuty', $this->ui('GET', '/')->body);
        (new SettingsRepository($this->pdo()))->set('worker_last_run', Time::toDb(Time::now()->modify('-2 minutes')));
        self::assertStringNotContainsString('worker-warning', $this->ui('GET', '/')->body);
    }
}
