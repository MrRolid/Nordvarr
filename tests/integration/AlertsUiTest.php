<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Alerts\Notifier;
use Nordvarr\App;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Http\Request;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Security\SecretBox;
use Nordvarr\Tests\FixtureServers;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Tests\TempDir;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

final class AlertsUiTest extends TestCase
{
    use InstalledApp;

    private FixtureServers $servers;
    private string $mockDir;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
        $this->servers = new FixtureServers();
        $this->mockDir = TempDir::create('nordvarr-mock-');
    }

    protected function tearDown(): void
    {
        $this->servers->stop();
        TempDir::remove($this->mockDir);
        $this->uninstall();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mockRequests(): array
    {
        $files = glob($this->mockDir . '/*.json') ?: [];
        sort($files);

        return array_map(static fn (string $f): array => json_decode((string) file_get_contents($f), true), $files);
    }

    private function flash(): array
    {
        return (array) $this->session->get('flash');
    }

    private function createWebhook(string $url, string $tags = ''): string
    {
        $response = $this->post('/settings/channels', ['type' => 'webhook', 'name' => 'Ops hook', 'enabled' => '1', 'tags' => $tags,
            'cfg' => ['webhook' => ['url' => $url, 'secret' => 'tajemstvi-webhooku-123']]]);
        self::assertSame(303, $response->status, $response->body);
        preg_match('#/settings/channels/([0-9a-f-]{36})$#', $response->headers['Location'], $m);

        return $m[1];
    }

    public function testChannelCrudSecretsAndTestSend(): void
    {
        $bad = $this->post('/settings/channels', ['type' => 'webhook', 'name' => '', 'cfg' => ['webhook' => ['url' => 'ftp://x', 'secret' => 'kratke']]]);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Tajemství musí mít aspoň 16 znaků.', $bad->body);
        self::assertStringNotContainsString('kratke', $bad->body, 'Tajné položky se do formuláře nevracejí.');

        $base = $this->servers->mockEndpoint($this->mockDir);
        $id = $this->createWebhook($base . '/hook/ok');
        $raw = (string) $this->pdo()->query('SELECT config FROM channels')->fetchColumn();
        self::assertStringNotContainsString('tajemstvi-webhooku-123', $raw);

        $page = $this->ui('GET', '/settings/channels/' . $id)->body;
        self::assertStringNotContainsString('tajemstvi-webhooku-123', $page);
        self::assertStringContainsString('nastaveno, prázdné pole ponechá', $page);

        // Úprava bez zadání tajemství ho ponechá.
        $this->post('/settings/channels/' . $id, ['name' => 'Ops hook 2', 'enabled' => '1', 'tags' => '', 'cfg' => ['webhook' => ['url' => $base . '/hook/ok', 'secret' => '']]]);
        $config = json_decode((string) $this->pdo()->query('SELECT config FROM channels')->fetchColumn(), true);
        self::assertSame('tajemstvi-webhooku-123', (new SecretBox($this->config()->appSecret()))->decrypt($config['secret']));

        $this->post('/settings/channels/' . $id . '/test');
        self::assertSame('channel.test_ok', $this->flash()[0]);
        $requests = $this->mockRequests();
        self::assertCount(1, $requests);
        self::assertSame('sha256=' . hash_hmac('sha256', $requests[0]['body'], 'tajemstvi-webhooku-123'), $requests[0]['headers']['x-nordvarr-signature']);
        self::assertSame('test', json_decode($requests[0]['body'], true)['event']);

        // Neúspěšný test se ukáže hned a neopakuje se.
        $this->post('/settings/channels/' . $id, ['name' => 'Ops hook 2', 'enabled' => '1', 'tags' => '', 'cfg' => ['webhook' => ['url' => $base . '/hook/fail', 'secret' => '']]]);
        $this->post('/settings/channels/' . $id . '/test');
        self::assertSame(['channel.test_failed', ['error' => 'Webhook vrátil HTTP 500']], $this->flash());
        self::assertSame('failed', $this->pdo()->query("SELECT status FROM deliveries ORDER BY created_at DESC, id DESC LIMIT 1")->fetchColumn());
        self::assertStringContainsString('Webhook vrátil HTTP 500', $this->ui('GET', '/settings/channels/' . $id)->body);

        $actions = array_column((new AuditLogRepository($this->pdo()))->latest(), 'action');
        foreach (['channel.create', 'channel.update', 'channel.test'] as $action) {
            self::assertContains($action, $actions);
        }
        $this->post('/settings/channels/' . $id . '/delete', ['confirm' => '1']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM channels')->fetchColumn());
        self::assertContains('channel.delete', array_column((new AuditLogRepository($this->pdo()))->latest(), 'action'));
    }

    public function testTelegramAndEmailChannelForms(): void
    {
        file_put_contents($this->app . '/config.php', str_replace("'debug' => false,", "'debug' => false,\n  'telegram_api_url' => " . var_export($this->servers->mockEndpoint($this->mockDir), true) . ',', (string) file_get_contents($this->app . '/config.php')));
        $response = $this->post('/settings/channels', ['type' => 'telegram', 'name' => 'Telegram', 'enabled' => '1', 'tags' => '',
            'cfg' => ['telegram' => ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', 'chat_id' => '-1001234']]]);
        self::assertSame(303, $response->status, $response->body);
        preg_match('#/settings/channels/([0-9a-f-]{36})$#', $response->headers['Location'], $m);
        $this->post('/settings/channels/' . $m[1] . '/test');
        self::assertSame('channel.test_ok', $this->flash()[0]);
        self::assertSame('/bot123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345/sendMessage', $this->mockRequests()[0]['path']);

        $mailDir = TempDir::create('nordvarr-mail-');
        try {
            $port = $this->servers->smtp($mailDir, ['user' => 'nordvarr', 'pass' => 'smtp-heslo'], true);
            $response = $this->post('/settings/channels', ['type' => 'email', 'name' => 'E-mail', 'enabled' => '1', 'tags' => '', 'cfg' => ['email' => [
                'from' => 'nordvarr@example.com', 'to' => 'admin@example.com, ops@example.net', 'verify_tls' => '0',
                'primary_host' => '127.0.0.1', 'primary_port' => (string) $port, 'primary_security' => 'starttls',
                'primary_username' => 'nordvarr', 'primary_password' => 'smtp-heslo', 'fallback_host' => '',
            ]]]);
            self::assertSame(303, $response->status, $response->body);
            preg_match('#/settings/channels/([0-9a-f-]{36})$#', $response->headers['Location'], $m);
            $this->post('/settings/channels/' . $m[1] . '/test');
            self::assertSame('channel.test_ok', $this->flash()[0], json_encode($this->flash()) ?: '');
            self::assertCount(1, glob($mailDir . '/*.json') ?: []);
            self::assertStringNotContainsString('smtp-heslo', (string) $this->pdo()->query("SELECT config FROM channels WHERE type = 'email'")->fetchColumn());
        } finally {
            TempDir::remove($mailDir);
        }
    }

    public function testMaintenanceWindows(): void
    {
        $object = (new Inventory($this->pdo()))->objects->create(['type' => 'database', 'name' => 'db1.example.com']);
        $bad = $this->post('/maintenance', ['scope' => 'object', 'object_id' => $object, 'starts_at' => '2026-10-20T12:00', 'ends_at' => '2026-10-20T11:00']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('Konec okna musí být po jeho začátku.', $bad->body);
        self::assertSame(422, $this->post('/maintenance', ['scope' => 'tag', 'tag' => 'dva slova', 'starts_at' => '2026-10-20T12:00', 'ends_at' => '2026-10-20T13:00'])->status);

        $tz = new \DateTimeZone('Europe/Prague');
        $this->post('/maintenance', ['scope' => 'object', 'object_id' => $object, 'pause_checks' => '1', 'note' => 'upgrade PostgreSQL',
            'starts_at' => Time::now()->modify('-10 minutes')->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_at' => Time::now()->modify('+1 hour')->setTimezone($tz)->format('Y-m-d\TH:i')]);
        self::assertSame('maintenance.created', $this->flash()[0]);
        $page = $this->ui('GET', '/alerts')->body;
        self::assertMatchesRegularExpression('#data-test="windows">.*db1\.example\.com.*upgrade PostgreSQL.*probíhá#s', $page);

        $objectPage = $this->ui('GET', '/objects/' . $object)->body;
        self::assertStringContainsString('data-test="in-maintenance"', $objectPage);

        // Kontroly objektu jsou pozastavené.
        $valid = CheckTypes::validate('tcp', ['host' => '127.0.0.1', 'port' => '5432']);
        (new CheckService($this->pdo()))->checks->create($object, 'tcp', $valid['common'], $valid['config']);
        self::assertStringContainsString('data-status="paused"', $this->ui('GET', '/objects/' . $object)->body);

        $id = (string) $this->pdo()->query('SELECT id FROM maintenance_windows')->fetchColumn();
        $this->post('/maintenance/' . $id . '/end');
        self::assertStringContainsString('skončilo', $this->ui('GET', '/alerts')->body);
        $this->post('/maintenance/' . $id . '/delete');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM maintenance_windows')->fetchColumn());
        $actions = array_column((new AuditLogRepository($this->pdo()))->latest(), 'action');
        foreach (['maintenance.create', 'maintenance.end', 'maintenance.delete'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function testAlertsPageShowsProbableCauseAndDeliveries(): void
    {
        $base = $this->servers->mockEndpoint($this->mockDir);
        $this->createWebhook($base . '/hook/ok');
        $inventory = new Inventory($this->pdo());
        $db = $inventory->objects->create(['type' => 'database', 'name' => 'db1.example.com']);
        $web = $inventory->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
        $inventory->relate($web, $db, 'depends_on', '', 'manual');
        $service = new CheckService($this->pdo());
        foreach ([$db => 'tcp_refused', $web => 'http_5xx'] as $objectId => $reason) {
            $valid = CheckTypes::validate('tcp', ['host' => '127.0.0.1', 'port' => '9', 'failure_threshold' => '1']);
            $checkId = $service->checks->create($objectId, 'tcp', $valid['common'], $valid['config']);
            $at = Time::toDb(Time::now());
            $service->record((array) $service->checks->find($checkId), new CheckResult('unhealthy', $reason, 'detail', null, $at, $at));
        }
        (new SettingsRepository($this->pdo()))->set('base_url', 'https://nordvarr.example.com');
        Notifier::fromConfig($this->pdo(), $this->app, $this->config())->run();

        $requests = $this->mockRequests();
        self::assertCount(1, $requests, 'Web je potlačený databází, odchází jen notifikace o databázi.');
        $payload = json_decode($requests[0]['body'], true);
        self::assertSame('db1.example.com', $payload['object']['name']);
        self::assertSame('https://nordvarr.example.com/objects/' . $db, $payload['object']['url']);

        $page = $this->ui('GET', '/alerts')->body;
        self::assertMatchesRegularExpression('#shop\.example\.com</a>\s*<br><span class="small-text" data-test="probable-cause">pravděpodobná příčina: <a[^>]*>db1\.example\.com#', $page);
        self::assertStringContainsString('notifikace potlačena', $page);

        $webPage = $this->ui('GET', '/objects/' . $web)->body;
        self::assertMatchesRegularExpression('#data-test="probable-cause"><a[^>]*>db1\.example\.com#', $webPage);
        self::assertMatchesRegularExpression('#data-test="down-now">.*shop\.example\.com.*pravděpodobná příčina#s', $this->ui('GET', '/')->body);

        $dbAlert = (string) $this->pdo()->query("SELECT a.id FROM alerts a WHERE a.object_id = " . $this->pdo()->quote($db))->fetchColumn();
        $detail = $this->ui('GET', '/alerts/' . $dbAlert)->body;
        self::assertStringContainsString('data-test="delivery-status">odesláno', $detail);
        self::assertStringContainsString('nový alert', $this->ui('GET', '/alerts/deliveries')->body);
        self::assertStringContainsString('data-test="no-alerts"', $this->ui('GET', '/alerts', [], ['status' => 'resolved'])->body);
    }

    public function testNotificationSettingsAndBaseUrlAutodetection(): void
    {
        $settings = new SettingsRepository($this->pdo());
        self::assertNull($settings->get('base_url'));
        $app = new App($this->app, $this->config());
        $app->handle(new Request('GET', '/', [], [], [], ['host' => 'nordvarr.example.com'], '/nordvarr', false, true, '192.0.2.50'), $this->session);
        self::assertSame('https://nordvarr.example.com/nordvarr/index.php?r=', $settings->get('base_url'));

        $this->post('/settings/notifications', ['reminder_hours' => '999', 'base_url' => '', 'notification_lang' => 'en']);
        self::assertSame('err.invalid_reminder', $this->flash()[0]);
        $this->post('/settings/notifications', ['reminder_hours' => '6', 'base_url' => 'https://monitoring.example.com/', 'notification_lang' => 'en']);
        self::assertSame(['6', 'https://monitoring.example.com', 'en'], [$settings->get('alert_reminder_hours'), $settings->get('base_url'), $settings->get('notification_lang')]);
        self::assertContains('settings.notifications', array_column((new AuditLogRepository($this->pdo()))->latest(), 'action'));
    }
}
