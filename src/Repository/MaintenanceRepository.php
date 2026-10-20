<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Údržbová okna (rozsah objekt nebo tag).
 */
final class MaintenanceRepository
{
    private const SELECT = 'SELECT m.*, o.name AS object_name FROM maintenance_windows m LEFT JOIN objects o ON o.id = m.object_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(?string $objectId, ?string $tag, string $startsAt, string $endsAt, bool $pauseChecks, string $note, ?string $createdBy): string
    {
        $id = Uuid::v7();
        $this->pdo->prepare(
            'INSERT INTO maintenance_windows (id, object_id, tag, starts_at, ends_at, pause_checks, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $objectId, $tag, $startsAt, $endsAt, $pauseChecks ? 1 : 0, $note, $createdBy, Time::toDb(Time::now())]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE m.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM maintenance_windows WHERE id = ?')->execute([$id]);
    }

    /**
     * Ukončí probíhající okno teď (ends_at = now).
     */
    public function end(string $id, string $now): void
    {
        $this->pdo->prepare('UPDATE maintenance_windows SET ends_at = ? WHERE id = ? AND starts_at < ? AND ends_at > ?')
            ->execute([$now, $id, $now, $now]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(string $now): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE m.starts_at <= ? AND m.ends_at > ? ORDER BY m.ends_at');
        $stmt->execute([$now, $now]);

        return $stmt->fetchAll();
    }

    /**
     * Probíhající a budoucí okna a okna skončená od daného času.
     *
     * @return list<array<string, mixed>>
     */
    public function listSince(string $since): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE m.ends_at >= ? ORDER BY m.starts_at DESC, m.id DESC');
        $stmt->execute([$since]);

        return $stmt->fetchAll();
    }
}
