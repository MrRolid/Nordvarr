<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\RdapRunner;
use Nordvarr\Http\Client\CurlHttpClient;
use Nordvarr\Http\Client\HttpResponse;
use Nordvarr\Repository\HttpCacheRepository;
use Nordvarr\Tests\FixtureHttpClient;
use Nordvarr\Tests\FixtureServers;
use Nordvarr\Tests\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Expirace domény přes RDAP z fixture JSON (vyměnitelný HTTP klient, žádná síť).
 */
final class RdapRunnerTest extends TestCase
{
    private const BOOTSTRAP = 'https://bootstrap.example.net/dns.json';

    private PDO $pdo;
    private FixtureHttpClient $http;

    protected function setUp(): void
    {
        $this->pdo = TestDb::memory();
        $this->http = new FixtureHttpClient();
        $this->http->set(self::BOOTSTRAP, FixtureHttpClient::file('rdap-bootstrap.json'));
    }

    private function domain(string $domain, string $expiry): void
    {
        $this->http->set(
            'https://rdap.example.net/test/domain/' . $domain,
            FixtureHttpClient::file('rdap-domain.json', ['{{DOMAIN}}' => $domain, '{{EXPIRY}}' => $expiry])
        );
    }

    private function check(string $domain): CheckResult
    {
        $runner = new RdapRunner($this->http, new HttpCacheRepository($this->pdo), self::BOOTSTRAP);

        return $runner->check(['id' => 'c', 'timeout_s' => 5, 'config' => ['domain' => $domain, 'degraded_days' => 30, 'unhealthy_days' => 7]]);
    }

    private static function inDays(int $days): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400 + 3600);
    }

    public function testExpiryStates(): void
    {
        $this->domain('valid.test', self::inDays(200));
        $this->domain('soon.test', self::inDays(20));
        $this->domain('urgent.test', self::inDays(3));
        $this->domain('gone.test', '2026-01-01T00:00:00Z');

        $valid = $this->check('valid.test');
        self::assertSame(['healthy', null], [$valid->status, $valid->reasonCode]);
        self::assertNotNull($valid->expiresAt);
        self::assertSame(['degraded', 'domain_expiring'], [$this->check('soon.test')->status, $this->check('soon.test')->reasonCode]);
        self::assertSame(['unhealthy', 'domain_expiring'], [$this->check('urgent.test')->status, $this->check('urgent.test')->reasonCode]);
        self::assertSame(['unhealthy', 'domain_expired'], [$this->check('gone.test')->status, $this->check('gone.test')->reasonCode]);
    }

    public function testBootstrapIsCachedForSevenDays(): void
    {
        $this->domain('a.test', self::inDays(200));
        $this->domain('b.test', self::inDays(200));
        $this->check('a.test');
        $this->check('b.test');
        self::assertSame(1, count(array_filter($this->http->requests, static fn (string $u): bool => $u === self::BOOTSTRAP)));

        // Starší než 7 dní -> nové stažení.
        $this->pdo->exec("UPDATE http_cache SET fetched_at = '2020-01-01T00:00:00Z'");
        $this->check('a.test');
        self::assertSame(2, count(array_filter($this->http->requests, static fn (string $u): bool => $u === self::BOOTSTRAP)));
    }

    public function testUnknownOutcomes(): void
    {
        $this->http->set('https://rdap.example.net/test/domain/noexpiry.test', FixtureHttpClient::file('rdap-domain-no-expiry.json'));
        $r = $this->check('noexpiry.test');
        self::assertSame(['unknown', 'rdap_no_expiry'], [$r->status, $r->reasonCode]);

        $this->http->set('https://rdap.example.net/test/domain/missing.test', new HttpResponse(404, '{}'));
        self::assertSame(['unknown', 'unknown_error'], [$this->check('missing.test')->status, $this->check('missing.test')->reasonCode]);

        $r = $this->check('nodata.test');
        self::assertSame(['unknown', 'dns_failure'], [$r->status, $r->reasonCode]);

        $r = $this->check('example.org');
        self::assertSame('unknown', $r->status);
        self::assertStringContainsString('bootstrapu', $r->detail);
    }

    public function testLongestSuffixAndHttpsPreferred(): void
    {
        $this->http->set('https://rdap.example.com/domain/shop.co.example', FixtureHttpClient::file('rdap-domain.json', ['{{DOMAIN}}' => 'shop.co.example', '{{EXPIRY}}' => self::inDays(100)]));
        self::assertSame('healthy', $this->check('shop.co.example')->status);
        self::assertContains('https://rdap.example.com/domain/shop.co.example', $this->http->requests);
    }

    public function testBrokenBootstrap(): void
    {
        $this->http->set(self::BOOTSTRAP, new HttpResponse(500, 'error'));
        $r = $this->check('a.test');
        self::assertSame(['unknown', 'unknown_error'], [$r->status, $r->reasonCode]);
    }

    public function testCurlHttpClientAgainstLocalFixtureServer(): void
    {
        $servers = new FixtureServers();
        try {
            $base = $servers->http();
            $client = new CurlHttpClient('Nordvarr-test');
            $ok = $client->get($base . '/files/rdap-domain-no-expiry.json', 5);
            self::assertSame(200, $ok->status);
            self::assertSame('noexpiry.test', json_decode($ok->body, true)['ldhName']);
            self::assertSame(404, $client->get($base . '/nic', 5)->status);
            $closed = $client->get('http://127.0.0.1:' . FixtureServers::freePort() . '/', 5);
            self::assertSame(0, $closed->status);
            self::assertNotSame('', $closed->error);
        } finally {
            $servers->stop();
        }
    }
}
