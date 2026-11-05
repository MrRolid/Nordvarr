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
 * Logy webů: cesty na kartě webu, server je pošle agentovi souvisejícího serveru, karta webu ukáže řádky.
 */
final class ObjectLogsTest extends TestCase
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
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function report(string $serverId, array $extra = []): array
    {
        $response = $this->api('POST', '/api/v1/agent/report', AgentFixtures::report(['object_id' => $serverId]) + $extra, $this->token);
        self::assertSame(200, $response->status, $response->body);

        return self::json($response);
    }

    public function testParseAndValidatePaths(): void
    {
        $parsed = ObjectLogs::parse(" /var/www/vhosts/example_com/access.log\n/var/log/httpd/error_log, /var/www/vhosts/example_com/access.log ;relativni ../etc/passwd /var/www/../etc/shadow /var/www/ /var/log/a b");
        self::assertSame(['/var/www/vhosts/example_com/access.log', '/var/log/httpd/error_log', '/var/log/a'], $parsed['paths']);
        self::assertSame(['relativni', '../etc/passwd', '/var/www/../etc/shadow', '/var/www/', 'b'], $parsed['invalid']);
        self::assertFalse(ObjectLogs::isValidPath('/var/log/"x'));
        self::assertFalse(ObjectLogs::isValidPath('/var/log/./x'));
        self::assertTrue(ObjectLogs::isValidPath('/var/www/vhosts/shop.example.com/logs/access_ssl.log'));
    }

    public function testWebsiteLogsGoThroughRelatedServerAgent(): void
    {
        $server = $this->inventory->objects->create(['type' => 'server', 'name' => 'web1.example.net']);
        $web = $this->inventory->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
        $other = $this->inventory->objects->create(['type' => 'website', 'name' => 'jiny.example.org']);

        // Bez cest: agent nic navíc nečte, karta webu nabízí formulář.
        self::assertSame([], $this->report($server)['log_paths']);
        $page = $this->ui('GET', '/objects/' . $web)->body;
        self::assertStringContainsString('id="logs"', $page);
        self::assertStringContainsString('data-test="logs-form"', $page);
        self::assertStringNotContainsString('id="logs"', $this->ui('GET', '/objects/' . $server)->body, 'Server má logy z konfigurace agenta.');

        // Neplatná cesta se neuloží.
        $this->post('/objects/' . $web . '/logs', ['paths' => "/var/www/vhosts/example_com/access.log\n../etc/passwd"]);
        self::assertSame(['logs.invalid_path', []], $this->session->get('flash'));
        self::assertSame([], $this->inventory->attributes->forObject($web));

        $response = $this->post('/objects/' . $web . '/logs', ['paths' => "/var/www/vhosts/example_com/access.log\r\n/var/www/vhosts/example_com/error.log"]);
        self::assertStringEndsWith('#logs', $response->headers['Location']);
        self::assertSame(['logs.saved', []], $this->session->get('flash'));
        $this->inventory->attributes->set($other, ObjectLogs::ATTRIBUTE, '/var/www/jiny/access.log', false, 'manual');

        // Web zatím nesouvisí se serverem: agent cesty nedostane, karta upozorní.
        self::assertSame([], $this->report($server)['log_paths']);
        self::assertStringContainsString('data-test="logs-no-agent"', $this->ui('GET', '/objects/' . $web)->body);

        // Server obsahuje web: agent dostane cesty jen tohoto webu.
        $this->inventory->relate($server, $web, 'contains', '', 'manual');
        self::assertSame(['/var/www/vhosts/example_com/access.log', '/var/www/vhosts/example_com/error.log'], $this->report($server)['log_paths']);
        $page = $this->ui('GET', '/objects/' . $web)->body;
        self::assertStringNotContainsString('data-test="logs-no-agent"', $page);
        self::assertSame(2, substr_count($page, 'data-test="log-waiting"'));

        // Agent pošle řádky: na kartě webu jsou zamaskované a escapované, druhý soubor dál čeká.
        $this->report($server, ['logs' => [
            ['path' => '/var/log/httpd/error_log', 'lines' => 'chyba serveru'],
            ['path' => '/var/www/vhosts/example_com/access.log', 'lines' => "GET /login?password=Tajne-heslo-1 200\n<script>alert(1)</script>"],
        ]]);
        $page = $this->ui('GET', '/objects/' . $web)->body;
        self::assertStringContainsString('data-test="object-logs"', $page);
        self::assertStringContainsString('web1.example.net', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringNotContainsString('Tajne-heslo-1', $page);
        self::assertStringNotContainsString('chyba serveru', $page, 'Logy serveru se na kartě webu neukazují.');
        self::assertSame(1, substr_count($page, 'data-test="log-waiting"'));

        // Vymazání cest.
        $this->post('/objects/' . $web . '/logs', ['paths' => '']);
        self::assertSame([], $this->inventory->attributes->forObject($web));
        self::assertSame([], $this->report($server)['log_paths']);
    }

    public function testDependencyChainAndServiceType(): void
    {
        // Web závisí na službě, server obsahuje službu: vzdálenost 2.
        $server = $this->inventory->objects->create(['type' => 'server', 'name' => 'app1.example.net']);
        $service = $this->inventory->objects->create(['type' => 'service', 'name' => 'php-fpm']);
        $web = $this->inventory->objects->create(['type' => 'website', 'name' => 'www.example.com']);
        $this->inventory->relate($server, $service, 'contains', '', 'manual');
        $this->inventory->relate($web, $service, 'depends_on', '', 'manual');
        $this->inventory->attributes->set($web, ObjectLogs::ATTRIBUTE, '/var/www/www/access.log', false, 'manual');
        $this->inventory->attributes->set($service, ObjectLogs::ATTRIBUTE, '/var/log/php-fpm/www-error.log', false, 'manual');
        self::assertSame(['/var/log/php-fpm/www-error.log', '/var/www/www/access.log'], $this->report($server)['log_paths']);
        self::assertStringContainsString('id="logs"', $this->ui('GET', '/objects/' . $service)->body);
    }
}
