<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testFormatAndVersion(): void
    {
        $id = Uuid::v7();
        self::assertTrue(Uuid::isValid($id), $id);
    }

    public function testTimestampPrefixIsSortable(): void
    {
        $a = Uuid::v7(1_700_000_000_000);
        $b = Uuid::v7(1_700_000_000_001);
        self::assertLessThan(0, strcmp($a, $b));
        self::assertStringStartsWith('018bcfe5-6800', $a);
    }

    public function testUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[Uuid::v7()] = true;
        }
        self::assertCount(1000, $ids);
    }
}
