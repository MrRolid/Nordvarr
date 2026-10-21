<?php

declare(strict_types=1);

namespace Nordvarr\Alerts;

use Nordvarr\Repository\MaintenanceRepository;
use PDO;

/**
 * Které objekty jsou právě v údržbě a u kterých jsou kontroly pozastavené.
 */
final class MaintenanceState
{
    /**
     * @param array<string, true> $inMaintenance
     * @param array<string, true> $paused
     */
    public function __construct(private readonly array $inMaintenance = [], private readonly array $paused = [])
    {
    }

    public static function load(PDO $pdo, string $now): self
    {
        $windows = (new MaintenanceRepository($pdo))->active($now);
        if ($windows === []) {
            return new self();
        }
        $tags = [];
        foreach ($windows as $w) {
            if ($w['tag'] !== null) {
                $tags[(string) $w['tag']] = true;
            }
        }
        $byTag = [];
        if ($tags !== []) {
            $placeholders = implode(', ', array_fill(0, count($tags), '?'));
            $stmt = $pdo->prepare("SELECT ot.object_id, t.name FROM object_tags ot JOIN tags t ON t.id = ot.tag_id WHERE t.name IN ($placeholders)");
            $stmt->execute(array_keys($tags));
            foreach ($stmt->fetchAll() as $row) {
                $byTag[(string) $row['name']][] = (string) $row['object_id'];
            }
        }
        $in = [];
        $paused = [];
        foreach ($windows as $w) {
            $objects = $w['object_id'] !== null ? [(string) $w['object_id']] : ($byTag[(string) $w['tag']] ?? []);
            foreach ($objects as $objectId) {
                $in[$objectId] = true;
                if ((int) $w['pause_checks'] === 1) {
                    $paused[$objectId] = true;
                }
            }
        }

        return new self($in, $paused);
    }

    public function inMaintenance(string $objectId): bool
    {
        return isset($this->inMaintenance[$objectId]);
    }

    public function checksPaused(string $objectId): bool
    {
        return isset($this->paused[$objectId]);
    }

    /**
     * @return array<string, true> id objektů s pozastavenými kontrolami
     */
    public function pausedObjects(): array
    {
        return $this->paused;
    }
}
