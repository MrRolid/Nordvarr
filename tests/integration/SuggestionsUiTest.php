<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

/**
 * Rychlé nastavení monitoringu: návrhy na kartě objektu, hromadně z inventáře, tip v přehledu.
 */
final class SuggestionsUiTest extends TestCase
{
    use InstalledApp;

    private Inventory $inventory;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
        $this->inventory = new Inventory($this->pdo());
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @return list<string>
     */
    private function checkTargets(string $objectId): array
    {
        return array_map(
            static fn (array $c): string => $c['type'] . ' ' . \Nordvarr\Checks\CheckTypes::target((string) $c['type'], $c['config']),
            (new CheckRepository($this->pdo()))->forObject($objectId)
        );
    }

    public function testObjectCardSuggestsAndAddsSelectedChecks(): void
    {
        $web = $this->inventory->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
        $page = $this->ui('GET', '/objects/' . $web)->body;
        self::assertStringContainsString('data-test="suggestions"', $page);
        self::assertStringContainsString('value="http:GET https://shop.example.com/" checked', $page);
        self::assertStringContainsString('value="tls_expiry:shop.example.com:443" checked', $page);

        $response = $this->post('/objects/' . $web . '/checks/suggested', ['keys' => ['http:GET https://shop.example.com/', 'neexistuje']]);
        self::assertSame(303, $response->status);
        self::assertStringEndsWith('#checks', $response->headers['Location']);
        self::assertSame(['http GET https://shop.example.com/'], $this->checkTargets($web), 'Jen vybraný návrh, neznámý klíč se ignoruje.');
        $actions = (new AuditLogRepository($this->pdo()))->latest();
        self::assertSame('check.create', $actions[0]['action']);

        // Zbývající návrh se nabízí dál, přidaná kontrola už ne.
        $page = $this->ui('GET', '/objects/' . $web)->body;
        // Adresa webu je proklik do nové karty (pod názvem objektu i u kontroly).
        self::assertStringContainsString('<p class="object-web" data-test="object-web"><a href="https://shop.example.com/" target="_blank" rel="noopener noreferrer"', $page);
        self::assertStringContainsString('class="web-link">GET https://shop.example.com/<svg', $page);
        self::assertStringNotContainsString('value="http:GET', $page);
        self::assertStringContainsString('value="tls_expiry:shop.example.com:443"', $page);

        self::assertStringContainsString('value="domain_expiry:example.com" checked', $page);
        self::assertStringContainsString('založí objekt Doména', $page);

        $this->post('/objects/' . $web . '/checks/suggested', ['keys' => ['tls_expiry:shop.example.com:443', 'domain_expiry:example.com']]);
        self::assertStringNotContainsString('data-test="suggestions"', $this->ui('GET', '/objects/' . $web)->body);

        // Doména se založila jako objekt s kontrolou expirace a web na ní závisí.
        $domain = $this->inventory->objects->findByTypeAndName('domain', 'example.com');
        self::assertNotNull($domain);
        self::assertSame(['domain_expiry example.com'], $this->checkTargets((string) $domain['id']));
        self::assertNotNull($this->inventory->relationships->findEdge($web, (string) $domain['id'], 'depends_on'));

        // Druhý web se stejnou doménou použije existující objekt domény.
        $blog = $this->inventory->objects->create(['type' => 'website', 'name' => 'blog.example.com']);
        $this->post('/objects/' . $blog . '/checks/suggested', ['keys' => ['domain_expiry:example.com']]);
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM objects WHERE type = 'domain'")->fetchColumn());
        self::assertSame(['domain_expiry example.com'], $this->checkTargets((string) $domain['id']), 'Kontrola expirace se nezdvojí.');
        self::assertNotNull($this->inventory->relationships->findEdge($blog, (string) $domain['id'], 'depends_on'));
    }

    public function testAgentHintOnServerWithoutAgent(): void
    {
        $server = $this->inventory->objects->create(['type' => 'server', 'name' => '192.0.2.10']);
        $page = $this->ui('GET', '/objects/' . $server)->body;
        self::assertStringContainsString('data-test="agent-hint"', $page);
        self::assertStringContainsString('value="ping:192.0.2.10" checked', $page);
        $web = $this->inventory->objects->create(['type' => 'website', 'name' => 'www.example.com']);
        self::assertStringNotContainsString('data-test="agent-hint"', $this->ui('GET', '/objects/' . $web)->body);
    }

    public function testBulkSuggestedChecksFromInventoryAndOverviewHint(): void
    {
        $web = $this->inventory->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
        $domain = $this->inventory->objects->create(['type' => 'domain', 'name' => 'example.net']);
        $server = $this->inventory->objects->create(['type' => 'server', 'name' => 'Server v racku']);
        $this->inventory->attributes->set($server, 'ip', '198.51.100.7', false, 'manual');
        $service = $this->inventory->objects->create(['type' => 'service', 'name' => 'interní služba']);

        $overview = $this->ui('GET', '/')->body;
        self::assertStringContainsString('Objekty bez kontrol: 4.', $overview);

        $inventory = $this->ui('GET', '/inventory')->body;
        self::assertStringContainsString('formaction="/inventory/checks" formnovalidate', $inventory);

        $response = $this->post('/inventory/checks', ['ids' => [$web, $domain, $server, $service], 'back' => 'status=none']);
        self::assertSame(303, $response->status);
        self::assertSame('/inventory?status=none', $response->headers['Location']);
        self::assertSame(['suggest.bulk_done', ['checks' => 5, 'objects' => 3, 'without' => 1]], $this->session->get('flash'));
        self::assertEqualsCanonicalizing(['http GET https://shop.example.com/', 'tls_expiry shop.example.com:443'], $this->checkTargets($web));
        self::assertSame(['domain_expiry example.net'], $this->checkTargets($domain));
        self::assertSame(['ping 198.51.100.7'], $this->checkTargets($server));
        self::assertSame([], $this->checkTargets($service));

        // Opakování nic nezduplikuje.
        $this->post('/inventory/checks', ['ids' => [$web, $domain, $server], 'back' => '']);
        self::assertSame(['suggest.bulk_done', ['checks' => 0, 'objects' => 0, 'without' => 3]], $this->session->get('flash'));
        self::assertStringContainsString('Objekty bez kontrol: 1.', $this->ui('GET', '/')->body);

        $this->post('/inventory/checks', ['back' => '']);
        self::assertSame(['inventory.bulk_err_none', []], $this->session->get('flash'));
    }
}
