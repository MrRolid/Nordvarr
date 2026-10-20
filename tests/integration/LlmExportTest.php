<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Alerts\Notifier;
use Nordvarr\Api\ApiApp;
use Nordvarr\Api\ApiTokens;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Export\LlmContext;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\I18n;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

/**
 * Export pro LLM: obsah, řazení okolí, limit 24 kB a kanárkové tajné hodnoty,
 * které se nesmí objevit v Markdownu, JSON, stránce ani v API.
 */
final class LlmExportTest extends TestCase
{
    use InstalledApp;

    /** Kanárky: každá hodnota je zastrčená na jiném místě, žádná nesmí projít. */
    private const CANARIES = [
        'attr_key' => 'Kanarek01Atribut',
        'url' => 'Kanarek02Url',
        'dsn' => 'Kanarek03Dsn',
        'entropy' => 'xK9mQ2vL7pR4tN8wZ3bC6yH1',
        'docs_kv' => 'Kanarek05Docs',
        'bearer' => 'Kanarek06Bearer',
        'pem' => 'Kanarek07KlicPem',
        'aws' => 'Kanarek08Aws',
        'event' => 'Kanarek09Udalost',
        'description' => 'Kanarek10Popis',
        'check_url' => 'Kanarek11Kontrola',
        'hex' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        'reason' => 'Kanarek12Duvod',
        'neighbor' => 'aB3dE5gH7jK9mN1pQ3sT5vW7',
    ];

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

    private function context(string $lang = 'cs'): LlmContext
    {
        return new LlmContext($this->pdo(), new I18n(dirname(__DIR__, 2) . '/lang', $lang), 'Europe/Prague');
    }

    private function object(string $name, string $type = 'service', string $description = ''): string
    {
        return $this->inventory->objects->create(['type' => $type, 'name' => $name, 'description' => $description]);
    }

    private function check(string $objectId, string $type, array $input, ?CheckResult $result = null): string
    {
        $service = new CheckService($this->pdo());
        $valid = CheckTypes::validate($type, $input + ['failure_threshold' => '1']);
        self::assertSame([], $valid['errors']);
        $id = $service->checks->create($objectId, $type, $valid['common'], $valid['config']);
        if ($result !== null) {
            $service->record((array) $service->checks->find($id), $result);
        }

        return $id;
    }

    /**
     * Objekt s kanárky ve všech místech, odkud export bere text.
     *
     * @return array{api: string, db: string}
     */
    private function seedWithCanaries(): array
    {
        $c = self::CANARIES;
        $db = $this->object('db1.example.com', 'database');
        $api = $this->object('shop-api', 'service', 'Služba e-shopu, heslo: ' . $c['description']);
        $this->inventory->relate($api, $db, 'depends_on', '', 'manual');
        $vault = $this->object('vault.example.com', 'service', 'Klientský token ' . $c['neighbor']);
        $this->inventory->relate($vault, $api, 'depends_on', '', 'manual');

        $attrs = $this->inventory->attributes;
        $attrs->set($api, 'db_password', $c['attr_key'], false, 'manual');
        $attrs->set($api, 'connection', 'postgres://shop:' . $c['url'] . '@db1.example.com/shop', false, 'manual');
        $attrs->set($api, 'dsn', 'pgsql:host=db1.example.com;dbname=shop;password=' . $c['dsn'], false, 'manual');
        $attrs->set($api, 'deploy_id', $c['entropy'], false, 'ansible');
        $attrs->set($api, 'webhook', $c['hex'], false, 'manual');
        $attrs->set($api, 'runtime', 'PHP 8.4', false, 'manual');
        $attrs->set($api, 'git_commit', '3f2a9c1d4e5b6a7f8091a2b3c4d5e6f7a8b9c0d1', false, 'ansible');
        $attrs->set($api, 'document_root', '/srv/www/shop/releases/2026-10-20', false, 'ansible');
        $attrs->set($api, 'port', '8080', false, 'manual');
        $attrs->set($api, 'port', '8081', false, 'ansible');

        $this->inventory->docs->save($api, implode("\n", [
            '# Provoz shop-api',
            '',
            'Restart: `systemctl restart shop-api`.',
            'Servisní účet: password: ' . $this->canary('docs_kv'),
            'Volání: `curl -H "Authorization: Bearer ' . $c['bearer'] . 'x1"`',
            'aws_secret_access_key = ' . $c['aws'],
            '',
            '-----BEGIN OPENSSH PRIVATE KEY-----',
            'b3BlbnNzaC1rZXktdjEAAAAA' . $c['pem'],
            '-----END OPENSSH PRIVATE KEY-----',
        ]), 'admin');

        $this->inventory->addManualEvent($api, 'deploy', 'release 43 z https://ci:' . $c['event'] . '@ci.example.com', 'token=' . $c['event'],
            Time::toDb(Time::now()->modify('-2 hours')));
        $this->inventory->addManualEvent($db, 'config', 'shared_buffers 4 GB', '', Time::toDb(Time::now()->modify('-1 hour')));
        $this->inventory->addManualEvent($db, 'note', 'stará poznámka', '', Time::toDb(Time::now()->modify('-9 days')));

        $at = Time::toDb(Time::now());
        $this->check($api, 'http', ['url' => 'https://monitor:' . $c['check_url'] . '@shop.example.com/health'],
            new CheckResult('unhealthy', 'http_5xx', 'HTTP 502, token=' . $c['reason'], 120, $at, $at));
        $this->check($db, 'tcp', ['host' => '192.0.2.10', 'port' => '5432'], new CheckResult('unhealthy', 'tcp_refused', 'refused', null, $at, $at));
        Notifier::fromConfig($this->pdo(), $this->app, $this->config())->run();

        return ['api' => $api, 'db' => $db];
    }

    private function canary(string $name): string
    {
        return self::CANARIES[$name];
    }

    private function assertNoCanary(string $text, string $where): void
    {
        foreach (self::CANARIES as $name => $value) {
            self::assertStringNotContainsString($value, $text, "Kanárek $name prošel do: $where");
        }
    }

    private function apiGet(string $path, ?string $token, array $query = []): Response
    {
        $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];

        return (new ApiApp($this->pdo(), $this->config()->appSecret()))
            ->handle(new Request('GET', $path, $query, [], [], $headers, '', true, false, '198.51.100.9'));
    }

    public function testExportContentAndCanaries(): void
    {
        ['api' => $api, 'db' => $db] = $this->seedWithCanaries();
        $context = $this->context();
        $data = $context->build($api);
        self::assertNotNull($data);
        $markdown = $context->markdown($data);
        $json = LlmContext::json($data);
        $this->assertNoCanary($markdown, 'Markdown');
        $this->assertNoCanary($json, 'JSON');

        // Obsah podle SPEC kap. 10.
        self::assertStringStartsWith('# Kontext objektu: shop-api (Služba)', $markdown);
        self::assertStringContainsString('Stav: nefunguje (HTTP: http_5xx', $markdown);
        self::assertStringContainsString('pravděpodobná příčina: db1.example.com', $markdown);
        self::assertStringContainsString('notifikace potlačena', $markdown);
        self::assertStringContainsString('| HTTP | GET https://monitor:***@shop.example.com/health | nefunguje (http_5xx) |', $markdown);
        self::assertStringContainsString('| db1.example.com | Databáze | závislost (objekt na ní závisí) | nefunguje |', $markdown);
        self::assertStringContainsString('| vault.example.com | Služba | závisí na objektu |', $markdown);
        self::assertStringContainsString('[konfigurace] shared_buffers 4 GB (db1.example.com)', $markdown);
        self::assertStringNotContainsString('stará poznámka', $markdown, 'Události jen za 7 dní.');
        self::assertStringContainsString('- db_password: ***', $markdown);
        self::assertStringContainsString('- runtime: PHP 8.4', $markdown);
        self::assertStringContainsString('- port: 8080 (rozpor mezi zdroji: ansible = 8081; manual = 8080)', $markdown);
        self::assertStringContainsString('### Provoz shop-api', $markdown, 'Nadpisy dokumentace jsou posunuté o dvě úrovně.');
        self::assertStringContainsString('systemctl restart shop-api', $markdown);

        // Nemaskuje víc, než musí.
        self::assertStringContainsString('3f2a9c1d4e5b6a7f8091a2b3c4d5e6f7a8b9c0d1', $markdown);
        self::assertStringContainsString('/srv/www/shop/releases/2026-10-20', $markdown);

        self::assertSame(LlmContext::FORMAT, $data['format']);
        self::assertSame($api, $data['object']['id']);
        self::assertSame('unhealthy', $data['status']['status']);
        self::assertSame($db, $data['open_alerts'][0]['probable_cause']['id']);
        self::assertSame(['dependency', 'dependent'], array_column($data['neighbors'], 'role'));
        self::assertFalse($data['truncated']);

        // Anglická verze má stejná data.
        $english = $this->context('en')->markdown($data);
        self::assertStringStartsWith('# Object context: shop-api (Service)', $english);
    }

    public function testUiPageAndApiDoNotLeakCanaries(): void
    {
        ['api' => $api] = $this->seedWithCanaries();

        $page = $this->ui('GET', '/objects/' . $api . '/llm');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-copy-target="llm-text"', $page->body);
        self::assertStringContainsString('# Kontext objektu: shop-api', $page->body);
        $this->assertNoCanary($page->body, 'stránka exportu');
        self::assertStringContainsString('data-test="llm-export"', $this->ui('GET', '/objects/' . $api)->body);
        self::assertSame(404, $this->ui('GET', '/objects/00000000-0000-4000-8000-000000000000/llm')->status);

        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $reader = $tokens->issue('LLM', 'llm', ['context:read'], null)['token'];
        $writer = $tokens->issue('Ansible', 'ansible', ['inventory:write', 'events:write'], null)['token'];

        $response = $this->apiGet('/api/v1/objects/' . $api . '/context', $reader);
        self::assertSame(200, $response->status);
        self::assertStringStartsWith('application/json', $response->headers['Content-Type']);
        $this->assertNoCanary($response->body, 'API JSON');
        self::assertSame('shop-api', json_decode($response->body, true)['object']['name']);

        $markdown = $this->apiGet('/api/v1/objects/' . $api . '/context', $reader, ['format' => 'markdown', 'lang' => 'en']);
        self::assertSame(200, $markdown->status);
        self::assertStringStartsWith('text/markdown', $markdown->headers['Content-Type']);
        self::assertStringStartsWith('# Object context: shop-api', $markdown->body);
        $this->assertNoCanary($markdown->body, 'API Markdown');

        self::assertSame(403, $this->apiGet('/api/v1/objects/' . $api . '/context', $writer)->status, 'Zapisovací token kontext nečte.');
        self::assertSame(401, $this->apiGet('/api/v1/objects/' . $api . '/context', null)->status);
        self::assertSame(404, $this->apiGet('/api/v1/objects/00000000-0000-4000-8000-000000000000/context', $reader)->status);
    }

    public function testNeighborsOrderedUnhealthyThenRecentlyChangedThenCloser(): void
    {
        $center = $this->object('center');
        $near = $this->object('near-ok');
        $farChanged = $this->object('far-changed');
        $farBroken = $this->object('far-broken');
        $nearDegraded = $this->object('near-degraded');
        $this->inventory->relate($center, $near, 'depends_on', '', 'manual');
        $this->inventory->relate($near, $farChanged, 'depends_on', '', 'manual');
        $this->inventory->relate($near, $farBroken, 'depends_on', '', 'manual');
        $this->inventory->relate($nearDegraded, $center, 'depends_on', '', 'manual');
        $at = Time::toDb(Time::now());
        $this->check($farBroken, 'tcp', ['host' => '192.0.2.20', 'port' => '80'], new CheckResult('unhealthy', 'tcp_refused', '', null, $at, $at));
        $this->check($nearDegraded, 'tcp', ['host' => '192.0.2.21', 'port' => '80'], new CheckResult('degraded', 'slow', '', 900, $at, $at));
        $this->check($near, 'tcp', ['host' => '192.0.2.22', 'port' => '80'], CheckResult::healthy($at, $at, 3, 'ok'));
        $this->inventory->addManualEvent($farChanged, 'deploy', 'nová verze', '', Time::toDb(Time::now()->modify('-1 day')));

        $data = $this->context()->build($center);
        self::assertSame(['far-broken', 'near-degraded', 'far-changed', 'near-ok'], array_column($data['neighbors'], 'name'));
        self::assertSame([2, 1, 2, 1], array_column($data['neighbors'], 'distance'));
        self::assertSame('near-ok', $data['neighbors'][0]['via']['name']);
    }

    public function testExportIsLimitedTo24Kb(): void
    {
        $center = $this->object('big.example.com', 'server', str_repeat('Popis serveru. ', 40));
        for ($i = 0; $i < 45; $i++) {
            $n = $this->object(sprintf('vm%02d.example.com s dlouhým názvem pro test velikosti exportu', $i), 'vm');
            $this->inventory->relate($center, $n, 'contains', '', 'manual');
            $this->inventory->addManualEvent($n, 'deploy', str_repeat('nasazení verze ' . $i . ' ', 15), str_repeat('podrobnosti ', 60),
                Time::toDb(Time::now()->modify('-' . ($i + 1) . ' hours')));
        }
        for ($i = 0; $i < 60; $i++) {
            $this->inventory->attributes->set($center, 'attr_' . $i, str_repeat('hodnota ' . $i . ' ', 30), false, 'manual');
        }
        $this->inventory->docs->save($center, str_repeat("Odstavec dokumentace se slovy.\n", 400), 'admin');

        $context = $this->context();
        $data = $context->build($center);
        $markdown = $context->markdown($data);
        self::assertTrue($data['truncated']);
        self::assertLessThanOrEqual(LlmContext::MAX_BYTES, strlen($markdown));
        self::assertLessThanOrEqual(LlmContext::MAX_BYTES, strlen(LlmContext::json($data)));
        self::assertLessThanOrEqual(LlmContext::MAX_NEIGHBORS, count($data['neighbors']));
        self::assertLessThanOrEqual(LlmContext::MAX_EVENTS, count($data['events_7d']));
        self::assertLessThanOrEqual(LlmContext::MAX_DOC_CHARS, mb_strlen((string) ($data['docs']['markdown'] ?? '')));
        self::assertStringContainsString('(Export je zkrácený na 24 kB.)', $markdown);
        self::assertTrue(mb_check_encoding($markdown, 'UTF-8'));
    }

    public function testUnknownObjectAndEmptyObject(): void
    {
        self::assertNull($this->context()->build('00000000-0000-4000-8000-000000000000'));
        $id = $this->object('samotný.example.com', 'other');
        $markdown = $this->context()->markdown((array) $this->context()->build($id));
        foreach (['Stav: bez kontrol', 'Otevřené alerty: žádné.', 'Objekt nemá kontroly.', 'Objekt nemá vztahy k jiným objektům.',
            'Za 7 dní žádné události.', 'Žádné atributy.', 'Dokumentace chybí.'] as $text) {
            self::assertStringContainsString($text, $markdown);
        }
    }
}
