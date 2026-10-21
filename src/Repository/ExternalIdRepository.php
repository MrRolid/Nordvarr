<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use PDO;

final class ExternalIdRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(string $objectId, string $source, string $externalId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO object_external_ids (object_id, source, external_id) VALUES (?, ?, ?)
             ON CONFLICT (source, external_id) DO NOTHING'
        );
        $stmt->execute([$objectId, $source, $externalId]);
    }

    public function remove(string $objectId, string $source, string $externalId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM object_external_ids WHERE object_id = ? AND source = ? AND external_id = ?');
        $stmt->execute([$objectId, $source, $externalId]);
    }

    /**
     * @return list<array{source: string, external_id: string}>
     */
    public function forObject(string $objectId): array
    {
        $stmt = $this->pdo->prepare('SELECT source, external_id FROM object_external_ids WHERE object_id = ? ORDER BY source, external_id');
        $stmt->execute([$objectId]);

        return $stmt->fetchAll();
    }

    /**
     * Které z daných externích id daného zdroje neexistují.
     *
     * @param list<string> $externalIds
     * @return list<string>
     */
    public function missing(string $source, array $externalIds): array
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM object_external_ids WHERE source = ? AND external_id = ?');
        $missing = [];
        foreach (array_unique($externalIds) as $externalId) {
            $stmt->execute([$source, $externalId]);
            if ($stmt->fetchColumn() === false) {
                $missing[] = $externalId;
            }
        }

        return $missing;
    }

    public function objectId(string $source, string $externalId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT object_id FROM object_external_ids WHERE source = ? AND external_id = ?');
        $stmt->execute([$source, $externalId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }
}
