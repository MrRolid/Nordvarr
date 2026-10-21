<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\PingRunner;
use PHPUnit\Framework\TestCase;

final class PingRunnerTest extends TestCase
{
    /**
     * @return array<string, CheckResult>
     */
    private function ping(string $binary, array $hosts, int $timeout = 2): array
    {
        $checks = [];
        foreach ($hosts as $id => $host) {
            $checks[] = ['id' => is_int($id) ? $host : $id, 'type' => 'ping', 'timeout_s' => $timeout, 'config' => ['host' => $host]];
        }
        $results = [];
        (new PingRunner($binary))->run($checks, function (string $id, CheckResult $r) use (&$results): void {
            $results[$id] = $r;
        });

        return $results;
    }

    public function testFakePingOutcomes(): void
    {
        $results = $this->ping(dirname(__DIR__) . '/fixtures/fake-ping', ['ok.example.com', 'down.example.com', 'nxdomain.example.com', 'denied.example.com', 'odd.example.com']);
        self::assertSame(['healthy', null, 2], [$results['ok.example.com']->status, $results['ok.example.com']->reasonCode, $results['ok.example.com']->latencyMs]);
        self::assertSame(['unhealthy', 'check_timeout'], [$results['down.example.com']->status, $results['down.example.com']->reasonCode]);
        self::assertSame(['unhealthy', 'dns_failure'], [$results['nxdomain.example.com']->status, $results['nxdomain.example.com']->reasonCode]);
        self::assertSame(['unknown', 'tool_missing'], [$results['denied.example.com']->status, $results['denied.example.com']->reasonCode]);
        self::assertSame(['unknown', 'unknown_error'], [$results['odd.example.com']->status, $results['odd.example.com']->reasonCode]);
    }

    public function testHangingPingIsKilledAfterTimeout(): void
    {
        $start = microtime(true);
        $results = $this->ping(dirname(__DIR__) . '/fixtures/fake-ping', ['hang.example.com'], 1);
        self::assertLessThan(6, microtime(true) - $start);
        self::assertSame(['unhealthy', 'check_timeout'], [$results['hang.example.com']->status, $results['hang.example.com']->reasonCode]);
    }

    public function testMissingBinaryIsToolMissing(): void
    {
        $results = $this->ping('/neexistuje/ping', ['ok.example.com']);
        self::assertSame(['unknown', 'tool_missing'], [$results['ok.example.com']->status, $results['ok.example.com']->reasonCode]);
    }

    public function testPingsRunInParallel(): void
    {
        $hosts = [];
        for ($i = 0; $i < 5; $i++) {
            $hosts['h' . $i] = 'hang.example.com';
        }
        $start = microtime(true);
        $results = $this->ping(dirname(__DIR__) . '/fixtures/fake-ping', $hosts, 1);
        self::assertCount(5, $results);
        // Postupně by to trvalo 5 x 3 s; paralelně jen jednou.
        self::assertLessThan(6, microtime(true) - $start);
    }
}
