<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Topology\Floors;
use PHPUnit\Framework\TestCase;

final class FloorsTest extends TestCase
{
    /**
     * @param array<string, string> $floors název => patro
     * @param list<array{0: string, 1: string}> $edges
     * @return array{positions: array<string, array{x: float, y: int}>, bands: list<array{floor: string, y: int, rows: int}>}
     */
    private static function layout(array $floors, array $edges = []): array
    {
        return Floors::layout(
            array_map(static fn (string $name, string $floor): array => ['id' => $name, 'name' => $name, 'floor' => $floor], array_keys($floors), $floors),
            array_map(static fn (array $e): array => ['source' => $e[0], 'target' => $e[1]], $edges)
        );
    }

    public function testFloorFromTypeAndOverride(): void
    {
        self::assertSame('hardware', Floors::floorOf('server'));
        self::assertSame('hardware', Floors::floorOf('network_device'));
        self::assertSame('virtual', Floors::floorOf('vm'));
        self::assertSame('containers', Floors::floorOf('container'));
        self::assertSame('containers', Floors::floorOf('cluster'));
        self::assertSame('providers', Floors::floorOf('provider'));
        self::assertSame('external', Floors::floorOf('external'));
        self::assertSame('providers', Floors::floorOf('server', '0'), 'Číslo 0 jsou poskytovatelé.');
        self::assertSame('services', Floors::floorOf('database'));
        self::assertSame('web', Floors::floorOf('website'));
        self::assertSame('domains', Floors::floorOf('domain'));
        self::assertSame('external', Floors::floorOf('other'));
        self::assertSame('external', Floors::floorOf('neznamy'));

        self::assertSame('containers', Floors::floorOf('service', 'containers'), 'Služba v Dockeru.');
        self::assertSame('containers', Floors::floorOf('service', ' Containers '));
        self::assertSame('virtual', Floors::floorOf('server', '2'), 'Číslo patra zdola.');
        self::assertSame('external', Floors::floorOf('service', '7'));
        self::assertSame('services', Floors::floorOf('service', '8'), 'Neplatné číslo se ignoruje.');
        self::assertSame('services', Floors::floorOf('service', 'sklep'), 'Neznámé patro se ignoruje.');
    }

    public function testRowsTopDownAndEmptyFloorsSkipped(): void
    {
        $l = self::layout(['hw' => 'hardware', 'web' => 'web', 'dom' => 'domains']);
        self::assertSame([
            ['floor' => 'domains', 'y' => 0, 'rows' => 1],
            ['floor' => 'web', 'y' => 1, 'rows' => 1],
            ['floor' => 'hardware', 'y' => 2, 'rows' => 1],
        ], $l['bands']);
        self::assertSame(0, $l['positions']['dom']['y']);
        self::assertSame(2, $l['positions']['hw']['y']);
        self::assertSame([], Floors::layout([], [])['bands']);
    }

    public function testRelatedObjectsFormColumns(): void
    {
        // Dva fyzické servery, na každém VM se službou; web závisí na službě z druhého serveru.
        $l = self::layout(
            ['a-host' => 'hardware', 'b-host' => 'hardware', 'vm-b' => 'virtual', 'vm-a' => 'virtual', 'svc-b' => 'services', 'svc-a' => 'services', 'shop' => 'web'],
            [['a-host', 'vm-a'], ['b-host', 'vm-b'], ['vm-a', 'svc-a'], ['vm-b', 'svc-b'], ['shop', 'svc-b']]
        );
        $x = array_map(static fn (array $p): float => $p['x'], $l['positions']);
        self::assertLessThan($x['b-host'], $x['a-host']);
        self::assertLessThan($x['vm-b'], $x['vm-a'], 'VM leží nad svým serverem, ne podle abecedy.');
        self::assertLessThan($x['svc-b'], $x['svc-a']);
        self::assertGreaterThan($x['svc-a'], $x['shop'], 'Web je ve sloupci služby, na které závisí.');
        foreach ([['a-host', 'vm-a'], ['b-host', 'vm-b'], ['vm-b', 'svc-b']] as [$low, $high]) {
            self::assertEqualsWithDelta($x[$low], $x[$high], 0.6, "$high nad $low");
        }
        self::assertEqualsWithDelta(0.0, (min($x) + max($x)) / 2, 0.01, 'Kresba je kolem nuly.');
    }

    public function testNodesInFloorKeepDistanceAndLayoutIsDeterministic(): void
    {
        $floors = ['hw' => 'hardware', 's1' => 'services', 's2' => 'services', 's3' => 'services'];
        $edges = [['hw', 's1'], ['hw', 's2'], ['hw', 's3']];
        $l = self::layout($floors, $edges);
        $xs = [$l['positions']['s1']['x'], $l['positions']['s2']['x'], $l['positions']['s3']['x']];
        sort($xs);
        self::assertGreaterThanOrEqual(1.0, $xs[1] - $xs[0] + 0.001);
        self::assertGreaterThanOrEqual(1.0, $xs[2] - $xs[1] + 0.001);
        self::assertEqualsWithDelta($xs[1], $l['positions']['hw']['x'], 0.01, 'Server pod středem svých služeb.');
        self::assertSame($l, self::layout(array_reverse($floors, true), array_reverse($edges)), 'Pořadí vstupu nemění výsledek.');
    }

    public function testCrowdedFloorWraps(): void
    {
        $floors = ['hw' => 'hardware'];
        for ($i = 1; $i <= 2 * Floors::PER_ROW + 2; $i++) {
            $floors[sprintf('web%02d', $i)] = 'web';
        }
        $l = self::layout($floors);
        self::assertSame([['floor' => 'web', 'y' => 0, 'rows' => 3], ['floor' => 'hardware', 'y' => 3, 'rows' => 1]], $l['bands']);
        $perRow = array_count_values(array_map(static fn (array $p): int => $p['y'], $l['positions']));
        self::assertSame([0 => Floors::PER_ROW, 1 => Floors::PER_ROW, 2 => 2, 3 => 1], $perRow);
    }
}
