<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Uuid;
use PDO;

final class TagRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function idFor(string $name): string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string) $id;
        }
        $id = Uuid::v7();
        $this->pdo->prepare('INSERT INTO tags (id, name) VALUES (?, ?)')->execute([$id, $name]);

        return $id;
    }

    /**
     * Přidá tag objektu. Vrací true, když objekt tag ještě neměl.
     */
    public function attach(string $objectId, string $name): bool
    {
        $stmt = $this->pdo->prepare('INSERT INTO object_tags (object_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
        $stmt->execute([$objectId, $this->idFor($name)]);

        return $stmt->rowCount() > 0;
    }

    public function detach(string $objectId, string $name): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM object_tags WHERE object_id = ? AND tag_id = (SELECT id FROM tags WHERE name = ?)'
        );
        $stmt->execute([$objectId, $name]);
        // Nepoužívané tagy se mažou, aby nezůstávaly ve filtru.
        $this->pdo->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM object_tags)');
    }

    /**
     * @return list<string>
     */
    public function forObject(string $objectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.name FROM tags t JOIN object_tags ot ON ot.tag_id = t.id WHERE ot.object_id = ? ORDER BY t.name'
        );
        $stmt->execute([$objectId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $objectIds
     * @return array<string, list<string>> id objektu => tagy
     */
    public function forObjects(array $objectIds): array
    {
        if ($objectIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($objectIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT ot.object_id, t.name FROM object_tags ot JOIN tags t ON t.id = ot.tag_id
             WHERE ot.object_id IN ($placeholders) ORDER BY t.name"
        );
        $stmt->execute(array_values($objectIds));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['object_id']][] = (string) $row['name'];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map('strval', $this->pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
    }
}
