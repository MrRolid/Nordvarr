<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Repository\MaintenanceRepository;
use Nordvarr\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * "Ukončit teď" u okna, které začalo ve stejné sekundě (časy jsou na sekundy).
 */
final class MaintenanceEndTest extends TestCase
{
    public function testEndInTheSameSecondAsStart(): void
    {
        $pdo = TestDb::memory();
        $windows = new MaintenanceRepository($pdo);
        $now = '2026-11-05T01:12:00Z';
        $id = $windows->create(null, 'udrzba', $now, '2026-11-05T02:12:00Z', true, 'okno', null);
        self::assertCount(1, $windows->active($now), 'Okno začínající teď už probíhá.');

        $windows->end($id, $now);
        self::assertSame([], $windows->active($now), 'Ukončené okno už neprobíhá.');
        $row = $windows->find($id);
        self::assertSame($now, $row['ends_at']);
        self::assertSame('2026-11-05T01:11:59Z', $row['starts_at'], 'Začátek se posune o sekundu, aby konec zůstal po začátku.');
    }

    public function testEndKeepsStartAndIgnoresPlannedAndEnded(): void
    {
        $pdo = TestDb::memory();
        $windows = new MaintenanceRepository($pdo);
        $running = $windows->create(null, 'a', '2026-11-05T01:00:00Z', '2026-11-05T02:00:00Z', false, '', null);
        $planned = $windows->create(null, 'b', '2026-11-05T03:00:00Z', '2026-11-05T04:00:00Z', false, '', null);
        $ended = $windows->create(null, 'c', '2026-11-05T00:00:00Z', '2026-11-05T00:30:00Z', false, '', null);
        $now = '2026-11-05T01:30:00Z';
        foreach ([$running, $planned, $ended] as $id) {
            $windows->end($id, $now);
        }
        self::assertSame(['2026-11-05T01:00:00Z', $now], [$windows->find($running)['starts_at'], $windows->find($running)['ends_at']]);
        self::assertSame('2026-11-05T04:00:00Z', $windows->find($planned)['ends_at'], 'Naplánované okno se nezmění.');
        self::assertSame('2026-11-05T00:30:00Z', $windows->find($ended)['ends_at'], 'Skončené okno se nezmění.');
    }
}
