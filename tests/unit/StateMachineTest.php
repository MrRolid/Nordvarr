<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\StateMachine;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function initial(): array
    {
        return ['check_id' => 'c', 'status' => 'unknown', 'since' => '2026-10-20T10:00:00Z', 'consecutive_failures' => 0, 'last_result_at' => null];
    }

    private function res(string $status, string $at, ?string $reason = null, ?string $expires = null): CheckResult
    {
        return new CheckResult($status, $reason, 'detail ' . $at, 12, $at, $at, $expires);
    }

    public function testWorseStateOnlyAfterThreshold(): void
    {
        $state = StateMachine::apply($this->initial(), $this->res('healthy', '2026-10-20T10:01:00Z'), 2);
        self::assertSame(['healthy', '2026-10-20T10:01:00Z'], [$state['to'], $state['state']['since']]);

        $state = StateMachine::apply($state['state'], $this->res('unhealthy', '2026-10-20T10:02:00Z', 'tcp_refused'), 2);
        self::assertSame('healthy', $state['to'], 'První selhání stav nemění.');
        self::assertSame(1, $state['state']['consecutive_failures']);

        $state = StateMachine::apply($state['state'], $this->res('unhealthy', '2026-10-20T10:03:00Z', 'tcp_refused'), 2);
        self::assertSame(['healthy', 'unhealthy'], [$state['from'], $state['to']]);
        self::assertSame('tcp_refused', $state['state']['reason_code']);
        self::assertSame('2026-10-20T10:03:00Z', $state['state']['since']);

        // Zlepšení z unhealthy na degraded se projeví hned (stav sleduje poslední výsledek).
        $state = StateMachine::apply($state['state'], $this->res('degraded', '2026-10-20T10:04:00Z', 'latency_degraded'), 2);
        self::assertSame('degraded', $state['to']);

        // Návrat do healthy po prvním úspěchu.
        $state = StateMachine::apply($state['state'], $this->res('healthy', '2026-10-20T10:05:00Z'), 2);
        self::assertSame(['degraded', 'healthy'], [$state['from'], $state['to']]);
        self::assertSame(0, $state['state']['consecutive_failures']);
        self::assertNull($state['state']['reason_code']);
    }

    public function testThresholdOneChangesImmediately(): void
    {
        $state = StateMachine::apply($this->initial(), $this->res('degraded', '2026-10-20T10:01:00Z', 'cert_expiring', '2026-11-01T00:00:00Z'), 1);
        self::assertSame('degraded', $state['to']);
        self::assertSame('2026-11-01T00:00:00Z', $state['state']['expires_at']);
    }

    public function testLateResultGoesOnlyToHistory(): void
    {
        $state = StateMachine::apply($this->initial(), $this->res('healthy', '2026-10-20T10:05:00Z'), 1);
        $late = StateMachine::apply($state['state'], $this->res('unhealthy', '2026-10-20T10:04:00Z', 'http_5xx'), 1);
        self::assertFalse($late['applied']);
        self::assertSame($state['state'], $late['state']);
        self::assertSame('healthy', $late['to']);
    }

    public function testFailureStreakFromUnknownNeedsThresholdToo(): void
    {
        $state = StateMachine::apply($this->initial(), $this->res('unhealthy', '2026-10-20T10:01:00Z', 'dns_failure'), 3);
        $state = StateMachine::apply($state['state'], $this->res('unhealthy', '2026-10-20T10:02:00Z', 'dns_failure'), 3);
        self::assertSame('unknown', $state['to']);
        $state = StateMachine::apply($state['state'], $this->res('unhealthy', '2026-10-20T10:03:00Z', 'dns_failure'), 3);
        self::assertSame('unhealthy', $state['to']);
    }

    public function testDetailIsTruncatedTo300Characters(): void
    {
        $result = new CheckResult('unhealthy', 'unknown_error', str_repeat('á', 500), null, 'a', 'a');
        self::assertSame(300, mb_strlen($result->detail));
    }
}
