<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Time;
use PDO;

final class AttributeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Uloží atribut. Vrací 'added', 'updated' nebo null (beze změny).
     */
    public function set(string $objectId, string $key, string $value, bool $isJson, string $source): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value, is_json FROM object_attributes WHERE object_id = ? AND key = ? AND source = ?');
        $stmt->execute([$objectId, $key, $source]);
        $current = $stmt->fetch();
        if ($current !== false && (string) $current['value'] === $value && (bool) $current['is_json'] === $isJson) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO object_attributes (object_id, key, value, is_json, source, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (object_id, key, source) DO UPDATE SET value = excluded.value, is_json = excluded.is_json,
             updated_at = excluded.updated_at'
        );
        $stmt->execute([$objectId, $key, $value, $isJson ? 1 : 0, $source, Time::toDb(Time::now())]);

        return $current === false ? 'added' : 'updated';
    }

    public function remove(string $objectId, string $key, string $source): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM object_attributes WHERE object_id = ? AND key = ? AND source = ?');
        $stmt->execute([$objectId, $key, $source]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<string>
     */
    public function keysFromSource(string $objectId, string $source): array
    {
        $stmt = $this->pdo->prepare('SELECT key FROM object_attributes WHERE object_id = ? AND source = ? ORDER BY key');
        $stmt->execute([$objectId, $source]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forObject(string $objectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM object_attributes WHERE object_id = ? ORDER BY key, source');
        $stmt->execute([$objectId]);

        return $stmt->fetchAll();
    }

    /**
     * Atributy seskupené podle klíče s vyznačením rozporu a platné hodnoty.
     * Platná je manuální hodnota; bez ní jen tehdy, když se zdroje shodují.
     *
     * @return list<array{key: string, values: list<array<string, mixed>>, conflict: bool, valid: ?string}>
     */
    public function grouped(string $objectId): array
    {
        $groups = [];
        foreach ($this->forObject($objectId) as $row) {
            $groups[(string) $row['key']][] = $row;
        }
        $result = [];
        foreach ($groups as $key => $rows) {
            $distinct = array_unique(array_map(static fn (array $r): string => (string) $r['value'], $rows));
            $manual = array_values(array_filter($rows, static fn (array $r): bool => $r['source'] === Vocabulary::MANUAL_SOURCE));
            $conflict = count($distinct) > 1;
            $valid = $manual !== [] ? (string) $manual[0]['value'] : ($conflict ? null : (string) $rows[0]['value']);
            $result[] = ['key' => (string) $key, 'values' => $rows, 'conflict' => $conflict, 'valid' => $valid];
        }

        return $result;
    }
}
