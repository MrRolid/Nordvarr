<?php

declare(strict_types=1);

namespace Nordvarr\Topology;

/**
 * Patra mapy: objekty rozdělené do vrstev infrastruktury (hardware dole, internet nahoře)
 * a jejich rozvržení. Patro určuje typ objektu, atribut "vrstva" ho může přepsat
 * (například služba v Dockeru -> patro kontejnerů).
 *
 * Rozvržení je deterministické: řádek podle patra, vodorovná poloha podle průměru
 * poloh sousedů v ostatních patrech (několik průchodů zdola nahoru a zpět). Objekty
 * spojené vztahem tak leží nad sebou ve "sloupcích".
 */
final class Floors
{
    /** Patra zdola nahoru. */
    public const FLOORS = ['hardware', 'virtual', 'containers', 'services', 'web', 'domains', 'external'];

    /** Klíče atributu, který přepíše patro odvozené z typu. */
    public const ATTRIBUTE_KEYS = ['vrstva', 'patro', 'layer'];

    /** Nejvíc objektů v jednom řádku patra, další se zalomí do dalšího řádku. */
    public const PER_ROW = 10;

    private const BY_TYPE = [
        'server' => 'hardware',
        'network_device' => 'hardware',
        'vm' => 'virtual',
        'cluster' => 'containers',
        'container' => 'containers',
        'service' => 'services',
        'database' => 'services',
        'website' => 'web',
        'domain' => 'domains',
        'other' => 'external',
    ];

    private const SWEEPS = 6;

    /**
     * Patro objektu. Přepis přijímá klíč patra nebo jeho číslo 1 až 7 (zdola); jiná hodnota se ignoruje.
     */
    public static function floorOf(string $type, ?string $override = null): string
    {
        $override = strtolower(trim((string) $override));
        if (in_array($override, self::FLOORS, true)) {
            return $override;
        }
        if (preg_match('/^[1-7]$/', $override) === 1) {
            return self::FLOORS[(int) $override - 1];
        }

        return self::BY_TYPE[$type] ?? 'external';
    }

    /**
     * Rozvržení uzlů do pater. Souřadnice jsou v jednotkách: x = rozestup sousedních uzlů
     * (střed kresby je 0), y = řádek shora (0 = horní řádek nejvyššího neprázdného patra).
     * Prázdná patra se vynechají.
     *
     * @param list<array{id: string, name: string, floor: string}> $nodes
     * @param list<array{source: string, target: string}> $edges
     * @return array{positions: array<string, array{x: float, y: int}>, bands: list<array{floor: string, y: int, rows: int}>}
     */
    public static function layout(array $nodes, array $edges): array
    {
        $floorIndex = array_flip(self::FLOORS);
        $byFloor = [];
        $names = [];
        $floorOf = [];
        foreach ($nodes as $n) {
            $floor = isset($floorIndex[$n['floor']]) ? $n['floor'] : 'external';
            $byFloor[$floor][] = $n['id'];
            $names[$n['id']] = mb_strtolower($n['name']);
            $floorOf[$n['id']] = $floor;
        }
        if ($byFloor === []) {
            return ['positions' => [], 'bands' => []];
        }

        // Sousedé v jiných patrech (vazby uvnitř patra polohu neurčují).
        $neighbors = [];
        foreach ($edges as $e) {
            $a = $e['source'];
            $b = $e['target'];
            if (isset($floorOf[$a], $floorOf[$b]) && $floorOf[$a] !== $floorOf[$b]) {
                $neighbors[$a][] = $b;
                $neighbors[$b][] = $a;
            }
        }

        // Výchozí pořadí podle názvu, patra zdola nahoru.
        $order = array_values(array_filter(self::FLOORS, static fn (string $f): bool => isset($byFloor[$f])));
        $x = [];
        foreach ($order as $floor) {
            usort($byFloor[$floor], static fn (string $a, string $b): int => [$names[$a], $a] <=> [$names[$b], $b]);
            $count = count($byFloor[$floor]);
            foreach ($byFloor[$floor] as $i => $id) {
                $x[$id] = $i - ($count - 1) / 2;
            }
        }

        for ($sweep = 0; $sweep < self::SWEEPS; $sweep++) {
            $floors = $sweep % 2 === 0 ? $order : array_reverse($order);
            foreach ($floors as $floor) {
                $desired = [];
                foreach ($byFloor[$floor] as $id) {
                    $nb = $neighbors[$id] ?? [];
                    $desired[$id] = $nb === [] ? $x[$id] : array_sum(array_map(static fn (string $n): float => $x[$n], $nb)) / count($nb);
                }
                $ids = $byFloor[$floor];
                usort($ids, static fn (string $a, string $b): int => [$desired[$a], $names[$a], $a] <=> [$desired[$b], $names[$b], $b]);
                foreach (self::pack(array_map(static fn (string $id): float => $desired[$id], $ids)) as $i => $pos) {
                    $x[$ids[$i]] = $pos;
                }
                $byFloor[$floor] = $ids;
            }
        }

        // Střed kresby na 0.
        $min = min($x);
        $max = max($x);
        $mid = ($min + $max) / 2;

        $positions = [];
        $bands = [];
        $row = 0;
        foreach (array_reverse($order) as $floor) {
            $ids = $byFloor[$floor];
            $chunks = array_chunk($ids, self::PER_ROW);
            foreach ($chunks as $r => $chunk) {
                if (count($chunks) === 1) {
                    foreach ($chunk as $id) {
                        $positions[$id] = ['x' => round($x[$id] - $mid, 2), 'y' => $row];
                    }
                } else {
                    // Zalomené patro: každý řádek rovnoměrně kolem středu patra.
                    $center = array_sum(array_map(static fn (string $id): float => $x[$id], $ids)) / count($ids) - $mid;
                    foreach ($chunk as $i => $id) {
                        $positions[$id] = ['x' => round($center + $i - (count($chunk) - 1) / 2, 2), 'y' => $row + $r];
                    }
                }
            }
            $bands[] = ['floor' => $floor, 'y' => $row, 'rows' => count($chunks)];
            $row += count($chunks);
        }

        return ['positions' => $positions, 'bands' => $bands];
    }

    /**
     * Seřazené požadované polohy posune tak, aby sousední byly aspoň 1 od sebe
     * a průměr zůstal stejný.
     *
     * @param list<float> $desired
     * @return list<float>
     */
    private static function pack(array $desired): array
    {
        $packed = [];
        foreach ($desired as $i => $want) {
            $packed[$i] = $i === 0 ? $want : max($want, $packed[$i - 1] + 1);
        }
        $shift = (array_sum($desired) - array_sum($packed)) / count($desired);

        return array_map(static fn (float $p): float => $p + $shift, $packed);
    }
}
