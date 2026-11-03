<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Agent\ContainerEvaluator;
use PHPUnit\Framework\TestCase;

final class ContainerEvaluatorTest extends TestCase
{
    /**
     * @return array{0: string, 1: ?string}
     */
    private static function eval(string $state, string $health = '', ?int $exit = null, string $status = ''): array
    {
        $r = ContainerEvaluator::evaluate(['state' => $state, 'health' => $health, 'exit_code' => $exit, 'status' => $status]);

        return [$r['status'], $r['reason']];
    }

    public function testStates(): void
    {
        self::assertSame(['healthy', null], self::eval('running', '', null, 'Up 2 hours'));
        self::assertSame(['healthy', null], self::eval('running', 'healthy'));
        self::assertSame(['healthy', null], self::eval('running', 'starting'), 'Healthcheck se teprve rozbíhá.');
        self::assertSame(['unhealthy', 'container_unhealthy'], self::eval('running', 'unhealthy'));
        self::assertSame(['unhealthy', 'container_down'], self::eval('restarting'));
        self::assertSame(['unhealthy', 'container_down'], self::eval('dead'));
        self::assertSame(['healthy', null], self::eval('exited', '', 0), 'Jednorázová úloha skončila v pořádku.');
        self::assertSame(['unhealthy', 'container_down'], self::eval('exited', '', 137));
        self::assertSame(['unknown', 'unknown_error'], self::eval('exited'), 'Bez návratového kódu nejde rozhodnout.');
        self::assertSame(['degraded', 'container_paused'], self::eval('paused'));
        self::assertSame(['degraded', 'container_down'], self::eval('created'));
        self::assertSame(['unknown', 'unknown_error'], self::eval('unknown'));
    }

    public function testDetail(): void
    {
        self::assertSame('skončil s kódem 1', ContainerEvaluator::evaluate(['state' => 'exited', 'health' => '', 'exit_code' => 1, 'status' => 'Exited (1) now'])['detail']);
        self::assertSame('Up 2 hours', ContainerEvaluator::evaluate(['state' => 'running', 'health' => '', 'exit_code' => null, 'status' => 'Up 2 hours'])['detail']);
    }
}
