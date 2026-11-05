<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Agent\ObjectLogs;
use Nordvarr\Api\ApiTokens;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Tests\AgentFixtures;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

/**
 * Události ze serveru (agent event), weby ze serveru (agent site), přehled Události s důležitostí.
 */
final class AgentEventsTest extends TestCase
{
    use InstalledApp;

    private string $token;
    private Inventory $inventory;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $this->token = $tokens->issue('Agent', 'agent', ['agent:write'], null)['token'];
        $this->inventory = new Inventory($this->pdo());
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>}
     */
    private function call(string $path, array $body): array
    {
        $response = $this->api('POST', $path, $body, $this->token);

        return [$response->status, self::json($response)];
    }

    public function testEventsFromServer(): void
    {
        $host = ['hostname' => 'gpu1.example.com'];
        [$status, $data] = $this->call('/api/v1/agent/event', $host + ['summary' => 'dřív než hlášení']);
        self::assertSame([422, 'unknown_host'], [$status, $data['fields']['host'] ?? null], 'Server bez hlášení není známý.');

        $this->api('POST', '/api/v1/agent/report', AgentFixtures::report(), $this->token);
        $serverId = (string) $this->inventory->externalIds->objectId('agent', 'host:gpu1.example.com');

        [$status, $data] = $this->call('/api/v1/agent/event', $host + ['type' => 'security', 'severity' => 'warning', 'event_id' => 'f2b-1',
            'summary' => 'fail2ban zabanoval 203.0.113.7', 'details' => 'jail sshd, <script>x</script>']);
        self::assertSame(201, $status);
        self::assertSame([$serverId], $data['objects']);
        [$status, $again] = $this->call('/api/v1/agent/event', $host + ['type' => 'security', 'severity' => 'warning', 'event_id' => 'f2b-1', 'summary' => 'znovu']);
        self::assertSame([200, false, $data['id']], [$status, $again['created'], $again['id']], 'Stejné id události se nezdvojí.');

        // Bez typu je to vzkaz; neplatná důležitost a neznámý web se odmítnou.
        [$status] = $this->call('/api/v1/agent/event', $host + ['summary' => 'AI agent: aktualizoval jsem certifikát', 'severity' => 'notice']);
        self::assertSame(201, $status);
        self::assertSame(422, $this->call('/api/v1/agent/event', $host + ['summary' => 'x', 'severity' => 'hrozne'])[0]);
        self::assertSame('unknown_site', $this->call('/api/v1/agent/event', $host + ['summary' => 'x', 'site' => 'nikde.example.com'])[1]['fields']['site'] ?? null);

        // Web ze serveru: založí se, závisí na serveru, má cesty k logům; podruhé se nezdvojí.
        [$status, $site] = $this->call('/api/v1/agent/site', $host + ['name' => 'Shop.Example.com.', 'logs' => ['/var/www/shop/access.log']]);
        self::assertSame([200, true, ['/var/www/shop/access.log'], []], [$status, $site['created'], $site['log_paths'], $site['checks']]);
        self::assertSame('shop.example.com', $this->inventory->objects->find($site['object_id'])['name'] ?? null);
        self::assertNotNull($this->inventory->relationships->findEdge($site['object_id'], $serverId, 'depends_on'));
        [, $again] = $this->call('/api/v1/agent/site', $host + ['name' => 'shop.example.com', 'checks' => true]);
        self::assertSame([$site['object_id'], false, ['/var/www/shop/access.log']], [$again['object_id'], $again['created'], $again['log_paths']], 'Bez logů se cesty nemění.');
        self::assertContains('http GET https://shop.example.com/', $again['checks']);
        self::assertSame(['/var/www/shop/access.log'], ObjectLogs::pathsForHost($this->pdo(), $serverId));
        self::assertSame('invalid_logs', $this->call('/api/v1/agent/site', $host + ['name' => 'shop.example.com', 'logs' => ['../etc/passwd']])[1]['fields']['logs'] ?? null);
        self::assertSame('invalid_site_name', $this->call('/api/v1/agent/site', $host + ['name' => '192.0.2.1'])[1]['fields']['name'] ?? null);

        // Událost k webu: u serveru i u webu.
        [, $order] = $this->call('/api/v1/agent/event', $host + ['type' => 'business', 'severity' => 'notice', 'site' => 'shop.example.com', 'summary' => 'Nová objednávka č. 1042']);
        self::assertEqualsCanonicalizing([$serverId, $site['object_id']], $order['objects']);

        // Přehled Události: barvy podle důležitosti, filtr, escapování.
        $page = $this->ui('GET', '/events')->body;
        self::assertStringContainsString('data-test="event-feed"', $page);
        self::assertStringContainsString('fail2ban zabanoval 203.0.113.7', $page);
        self::assertStringContainsString('data-severity="warning"', $page);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $page);
        self::assertStringContainsString('href="/events"', $page, 'Položka v hlavní navigaci.');
        $warnings = $this->ui('GET', '/events', [], ['severity' => 'warning'])->body;
        self::assertStringContainsString('fail2ban', $warnings);
        self::assertStringNotContainsString('Nová objednávka', $warnings, 'Filtr nejméně "pozor".');
        $business = $this->ui('GET', '/events', [], ['type' => 'business'])->body;
        self::assertStringContainsString('Nová objednávka', $business);
        self::assertStringNotContainsString('fail2ban', $business);

        // Na kartě webu i v přehledu (24 h).
        self::assertStringContainsString('Nová objednávka č. 1042', $this->ui('GET', '/objects/' . $site['object_id'])->body);
        self::assertStringContainsString('sev-row-warning', $this->ui('GET', '/')->body);
    }

    public function testApiEventsAcceptSeverityAndManualForm(): void
    {
        $id = $this->inventory->objects->create(['type' => 'service', 'name' => 'shop-api']);
        $this->post('/objects/' . $id . '/events', ['type' => 'incident', 'severity' => 'critical', 'summary' => 'výpadek platební brány', 'details' => '', 'occurred_at' => '2026-11-05T10:00']);
        self::assertSame('critical', $this->pdo()->query('SELECT severity FROM events')->fetchColumn());
        $this->post('/objects/' . $id . '/events', ['type' => 'note', 'severity' => 'nesmysl', 'summary' => 'x', 'details' => '', 'occurred_at' => '2026-11-05T10:00']);
        self::assertSame(['err.invalid_severity', []], $this->session->get('flash'));
    }
}
