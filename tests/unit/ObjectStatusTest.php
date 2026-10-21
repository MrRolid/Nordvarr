<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Status;
use PHPUnit\Framework\TestCase;

final class ObjectStatusTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function check(string $object, string $status, bool $enabled = true, string $lifecycle = 'active', string $since = '2026-10-20T10:00:00Z'): array
    {
        return ['id' => uniqid(), 'object_id' => $object, 'status' => $status, 'enabled' => $enabled, 'object_lifecycle' => $lifecycle, 'since' => $since];
    }

    public function testWorst(): void
    {
        self::assertSame(Status::NONE, Status::worst([]));
        self::assertSame(Status::UNHEALTHY, Status::worst(['healthy', 'unhealthy', 'degraded']));
        self::assertSame(Status::UNKNOWN, Status::worst(['healthy', 'unknown', 'paused']));
        self::assertSame(Status::HEALTHY, Status::worst(['paused', 'healthy']));
    }

    public function testObjectStatusIsWorstOfItsChecks(): void
    {
        $statuses = ObjectStatus::forObjects([
            $this->check('a', 'healthy'),
            $this->check('a', 'degraded'),
            $this->check('b', 'unhealthy', false),
            $this->check('c', 'unhealthy', true, 'archived'),
        ]);
        self::assertSame('degraded', $statuses['a']['status']);
        self::assertSame('paused', $statuses['b']['status'], 'Vypnutá kontrola je paused.');
        self::assertSame('paused', $statuses['c']['status'], 'Kontrola archivovaného objektu je paused.');
        self::assertArrayNotHasKey('d', $statuses);

        self::assertSame(['status' => 'none', 'check' => null], ObjectStatus::forObject(['id' => 'd', 'lifecycle' => 'active'], []));
        self::assertSame('paused', ObjectStatus::forObject(['id' => 'e', 'lifecycle' => 'archived'], [])['status']);
    }

    public function testOldestFailureWinsForSameStatus(): void
    {
        $older = $this->check('a', 'unhealthy', since: '2026-10-20T09:00:00Z');
        $newer = $this->check('a', 'unhealthy', since: '2026-10-20T10:00:00Z');
        self::assertSame($older['id'], ObjectStatus::forObjects([$newer, $older])['a']['check']['id']);
    }
}
