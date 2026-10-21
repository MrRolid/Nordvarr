<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class EventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySourceId(string $source, string $sourceEventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE source = ? AND source_event_id = ?');
        $stmt->execute([$source, $sourceEventId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{source: string, source_event_id: string, type: string, occurred_at: string, summary: string, details: string} $event
     * @param list<string> $objectIds
     */
    public function create(array $event, array $objectIds, ?string $id = null, ?string $createdAt = null): string
    {
        $id ??= Uuid::v7();
        $stmt = $this->pdo->prepare(
            'INSERT INTO events (id, source, source_event_id, type, occurred_at, summary, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $event['source'],
            $event['source_event_id'],
            $event['type'],
            $event['occurred_at'],
            $event['summary'],
            $event['details'],
            $createdAt ?? Time::toDb(Time::now()),
        ]);
        $link = $this->pdo->prepare('INSERT INTO event_objects (event_id, object_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
        foreach (array_unique($objectIds) as $objectId) {
            $link->execute([$id, $objectId]);
        }

        return $id;
    }

    /**
     * Časová osa: události daných objektů (objekt a jeho sousedé), nejnovější první.
     * U každé události je seznam id dotčených objektů z dané množiny.
     *
     * @param list<string> $objectIds
     * @return list<array<string, mixed>>
     */
    public function timeline(array $objectIds, int $limit = 50, ?string $since = null): array
    {
        if ($objectIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($objectIds), '?'));
        $params = array_values($objectIds);
        $sinceSql = '';
        if ($since !== null) {
            $sinceSql = ' AND e.occurred_at >= ?';
            $params[] = $since;
        }
        $stmt = $this->pdo->prepare(
            "SELECT e.*, group_concat(eo.object_id) AS object_ids FROM events e
             JOIN event_objects eo ON eo.event_id = e.id
             WHERE eo.object_id IN ($placeholders)$sinceSql
             GROUP BY e.id ORDER BY e.occurred_at DESC, e.id DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['object_ids'] = explode(',', (string) $row['object_ids']);
        }

        return $rows;
    }

    /**
     * Všechny události od daného času, nejnovější první, s id dotčených objektů.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $since, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, group_concat(eo.object_id) AS object_ids FROM events e
             LEFT JOIN event_objects eo ON eo.event_id = e.id
             WHERE e.occurred_at >= ? GROUP BY e.id ORDER BY e.occurred_at DESC, e.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$since]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['object_ids'] = $row['object_ids'] === null ? [] : explode(',', (string) $row['object_ids']);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM events ORDER BY occurred_at, id')->fetchAll();
    }

    /**
     * @return list<string>
     */
    public function objectIds(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT object_id FROM event_objects WHERE event_id = ? ORDER BY object_id');
        $stmt->execute([$eventId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function normalizeTime(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return Time::toDb(new \DateTimeImmutable($value));
        } catch (\Exception) {
            return null;
        }
    }
}
