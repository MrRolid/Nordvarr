<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CurlRunner;
use Nordvarr\Tests\FixtureServers;
use PHPUnit\Framework\TestCase;

/**
 * Kontroly http, tcp a tls_expiry proti lokálním fixture serverům.
 */
final class CurlRunnerTest extends TestCase
{
    private static FixtureServers $servers;
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        self::$servers = new FixtureServers();
        self::$base = self::$servers->http();
    }

    public static function tearDownAfterClass(): void
    {
        self::$servers->stop();
    }

    /**
     * @param array<string, array{type: string, config: array<string, mixed>, timeout_s?: int}> $checks
     * @return array<string, CheckResult>
     */
    private function runChecks(array $checks): array
    {
        $rows = [];
        foreach ($checks as $id => $check) {
            $rows[] = ['id' => $id, 'timeout_s' => $check['timeout_s'] ?? 5] + $check;
        }
        $results = [];
        (new CurlRunner('Nordvarr-test'))->run($rows, function (string $id, CheckResult $r) use (&$results): void {
            $results[$id] = $r;
        });

        return $results;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{type: string, config: array<string, mixed>}
     */
    private static function http(string $path, array $config = []): array
    {
        return ['type' => 'http', 'config' => $config + ['url' => self::$base . $path, 'method' => 'GET', 'expected_status' => '200-399', 'contains' => '', 'latency_degraded_ms' => 0]];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function outcome(CheckResult $r): array
    {
        return [$r->status, $r->reasonCode];
    }

    public function testHttpOutcomes(): void
    {
        $r = $this->runChecks([
            'ok' => self::http('/ok', ['contains' => 'fixture OK']),
            'head' => self::http('/ok', ['method' => 'HEAD']),
            '500' => self::http('/status/500'),
            '404' => self::http('/status/404'),
            '404-expected' => self::http('/status/404', ['expected_status' => '404']),
            'mismatch' => self::http('/ok', ['contains' => 'tohle tam neni']),
            'redirect' => self::http('/redirect/3'),
            'too-many-redirects' => self::http('/redirect/7'),
            'slow-degraded' => self::http('/slow?ms=400', ['latency_degraded_ms' => 100]),
            'big' => self::http('/big', ['contains' => 'ZACATEK']),
        ]);
        self::assertSame(['healthy', null], self::outcome($r['ok']));
        self::assertNotNull($r['ok']->latencyMs);
        self::assertSame(['healthy', null], self::outcome($r['head']));
        self::assertSame(['unhealthy', 'http_5xx'], self::outcome($r['500']));
        self::assertStringContainsString('HTTP 500', $r['500']->detail);
        self::assertSame(['unhealthy', 'http_4xx'], self::outcome($r['404']));
        self::assertSame(['healthy', null], self::outcome($r['404-expected']));
        self::assertSame(['unhealthy', 'content_mismatch'], self::outcome($r['mismatch']));
        self::assertSame(['healthy', null], self::outcome($r['redirect']));
        self::assertSame(['unhealthy', 'unknown_error'], self::outcome($r['too-many-redirects']));
        self::assertSame(['degraded', 'latency_degraded'], self::outcome($r['slow-degraded']));
        self::assertSame(['healthy', null], self::outcome($r['big']), 'Odpověď nad 1 MB se vyhodnotí z přijaté části.');
    }

    public function testTimeoutAndClosedPort(): void
    {
        $closed = FixtureServers::freePort();
        $r = $this->runChecks([
            'timeout' => self::http('/slow?ms=3000') + ['timeout_s' => 1],
            'closed' => ['type' => 'http', 'config' => ['url' => 'http://127.0.0.1:' . $closed . '/', 'method' => 'GET', 'expected_status' => '200-399']],
        ]);
        self::assertSame(['unhealthy', 'check_timeout'], self::outcome($r['timeout']));
        self::assertSame(['unhealthy', 'tcp_refused'], self::outcome($r['closed']));
    }

    public function testRequestsRunInParallel(): void
    {
        // Server obslouží všechna spojení současně, takže měří jen paralelismus runneru.
        $slow = self::$servers->slowHttp(700);
        $checks = [];
        for ($i = 0; $i < 20; $i++) {
            $checks['slow' . $i] = ['type' => 'http', 'config' => ['url' => $slow . '/', 'method' => 'GET', 'expected_status' => '200-399']];
        }
        $start = microtime(true);
        $r = $this->runChecks($checks);
        self::assertCount(20, $r);
        foreach ($r as $result) {
            self::assertSame('healthy', $result->status);
        }
        self::assertLessThan(3.0, microtime(true) - $start, 'Dvacet pomalých požadavků paralelně, ne postupně (14 s).');
    }

    public function testTcp(): void
    {
        $port = (int) parse_url(self::$base, PHP_URL_PORT);
        $r = $this->runChecks([
            'open' => ['type' => 'tcp', 'config' => ['host' => '127.0.0.1', 'port' => $port]],
            'closed' => ['type' => 'tcp', 'config' => ['host' => '127.0.0.1', 'port' => FixtureServers::freePort()]],
        ]);
        self::assertSame(['healthy', null], self::outcome($r['open']));
        self::assertSame(['unhealthy', 'tcp_refused'], self::outcome($r['closed']));
    }

    public function testTlsExpiry(): void
    {
        $tls = fn (int $port): array => ['type' => 'tls_expiry', 'config' => ['host' => '127.0.0.1', 'port' => $port, 'degraded_days' => 21, 'unhealthy_days' => 7]];
        $r = $this->runChecks([
            'valid' => $tls(self::$servers->tls(90)),
            'soon' => $tls(self::$servers->tls(10)),
            'very-soon' => $tls(self::$servers->tls(3)),
            'closed' => $tls(FixtureServers::freePort()),
            'plain-http' => $tls((int) parse_url(self::$base, PHP_URL_PORT)),
        ]);
        self::assertSame(['healthy', null], self::outcome($r['valid']));
        self::assertNotNull($r['valid']->expiresAt);
        self::assertSame(['degraded', 'cert_expiring'], self::outcome($r['soon']));
        self::assertMatchesRegularExpression('/za (9|10) dní/', $r['soon']->detail);
        self::assertSame(['unhealthy', 'cert_expiring'], self::outcome($r['very-soon']));
        self::assertSame(['unknown', 'tcp_refused'], self::outcome($r['closed']));
        self::assertSame(['unknown', 'tls_error'], self::outcome($r['plain-http']));
    }

    public function testHttpsWithUntrustedCertificateIsTlsError(): void
    {
        $port = self::$servers->tls(90);
        $r = $this->runChecks(['https' => ['type' => 'http', 'config' => ['url' => 'https://127.0.0.1:' . $port . '/', 'method' => 'GET', 'expected_status' => '200-399']]]);
        self::assertSame(['unhealthy', 'tls_error'], self::outcome($r['https']));
    }

    public function testDeadlinePreventsNewRequests(): void
    {
        $results = [];
        (new CurlRunner())->run([['id' => 'x', 'timeout_s' => 5] + self::http('/ok')], function (string $id, CheckResult $r) use (&$results): void {
            $results[$id] = $r;
        }, microtime(true) - 1);
        self::assertSame([], $results);
    }
}
