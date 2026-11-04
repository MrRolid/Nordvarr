<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Api\ApiTokens;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Tests\AgentFixtures;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

/**
 * Služby systemd a logy z hlášení agenta: problém na kontrole serveru, uložení logů, karta serveru.
 */
final class AgentServicesTest extends TestCase
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
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function send(array $extra): array
    {
        $report = AgentFixtures::report(['disks' => [AgentFixtures::report()['disks'][0]]]) + $extra;
        $response = $this->api('POST', '/api/v1/agent/report', $report, $this->token);
        self::assertSame(200, $response->status, $response->body);

        return self::json($response);
    }

    public function testServicesAndLogs(): void
    {
        $services = [
            ['name' => 'httpd', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled', 'restarts' => 0],
            ['name' => 'mariadb', 'active' => 'failed', 'sub' => 'failed', 'enabled' => 'enabled', 'restarts' => 3],
            ['name' => 'postgresql', 'active' => 'inactive', 'sub' => 'dead', 'enabled' => 'enabled', 'restarts' => 0],
            ['name' => 'bad name; rm', 'active' => 'active'],
        ];
        $logs = [
            ['path' => '/var/log/httpd/error_log', 'lines' => "[error] AH00128: File does not exist\n[error] <script>alert(1)</script>"],
            ['path' => '/var/log/httpd/access_log', 'lines' => 'GET /admin?password=Tajne-heslo-1 HTTP/1.1 200'],
            ['path' => 'relativni/cesta', 'lines' => 'x'],
        ];
        $body = $this->send(['services' => $services, 'logs' => $logs]);
        self::assertSame('unhealthy', $body['status']);

        $host = (new AgentRepository($this->pdo()))->host($body['object_id']);
        self::assertSame(['httpd', 'mariadb', 'postgresql'], array_column($host['snapshot']['services'], 'name'), 'Neplatné jméno služby se zahodí.');
        self::assertSame(['/var/log/httpd/error_log', '/var/log/httpd/access_log'], array_column($host['logs'], 'path'));
        self::assertStringNotContainsString('Tajne-heslo-1', $host['logs'][1]['lines']);

        // Další hlášení bez logů: logy zůstanou, služby se aktualizují.
        $this->send(['services' => [['name' => 'httpd', 'active' => 'active', 'sub' => 'running', 'enabled' => 'enabled', 'restarts' => 0]]]);
        $host = (new AgentRepository($this->pdo()))->host($body['object_id']);
        self::assertCount(2, $host['logs']);
        self::assertCount(1, $host['snapshot']['services']);

        // Karta serveru: tabulka služeb, logy escapované.
        $this->send(['services' => $services]);
        $this->loginAdmin();
        $page = $this->ui('GET', '/objects/' . $body['object_id'])->body;
        self::assertStringContainsString('data-test="host-services"', $page);
        self::assertStringContainsString('<td class="lvl-bad">failed (failed)</td>', $page);
        self::assertStringContainsString('<td class="lvl-bad">inactive (dead)</td>', $page);
        self::assertStringContainsString('služba mariadb spadla (failed)', $page);
        self::assertStringContainsString('data-test="host-logs"', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringNotContainsString('<script>alert(1)</script>', $page);
    }
}
