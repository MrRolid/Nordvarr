<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class ObjectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM objects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * Objekt daného typu podle názvu (bez ohledu na velikost písmen), nejstarší první.
     *
     * @return array<string, mixed>|null
     */
    public function findByTypeAndName(string $type, string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM objects WHERE type = ? AND lower(name) = lower(?) ORDER BY created_at, id LIMIT 1');
        $stmt->execute([$type, $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->find((string) $id);
    }

    public function findByExternalId(string $source, string $externalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.* FROM objects o
             JOIN object_external_ids x ON x.object_id = o.id
             WHERE x.source = ? AND x.external_id = ?'
        );
        $stmt->execute([$source, $externalId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{type: string, name: string, description?: string, lifecycle?: string} $data
     */
    public function create(array $data, ?string $id = null): string
    {
        $id ??= Uuid::v7();
        $now = Time::toDb(Time::now());
        $stmt = $this->pdo->prepare(
            'INSERT INTO objects (id, type, name, slug, description, lifecycle, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['type'],
            $data['name'],
            $this->uniqueSlug(Vocabulary::slugify($data['name'])),
            $data['description'] ?? '',
            $data['lifecycle'] ?? 'active',
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Zapíše změněná pole. Vrací seznam polí, která se opravdu změnila.
     *
     * @param array<string, string> $data povolená pole: type, name, description, lifecycle
     * @return list<string>
     */
    public function update(string $id, array $data): array
    {
        $current = $this->find($id);
        if ($current === null) {
            return [];
        }
        $changed = [];
        foreach (['type', 'name', 'description', 'lifecycle'] as $field) {
            if (array_key_exists($field, $data) && (string) $current[$field] !== $data[$field]) {
                $changed[] = $field;
            }
        }
        if ($changed === []) {
            return [];
        }
        $sets = implode(', ', array_map(static fn (string $f): string => "$f = ?", $changed));
        $values = array_map(static fn (string $f): string => $data[$f], $changed);
        $stmt = $this->pdo->prepare("UPDATE objects SET $sets, updated_at = ? WHERE id = ?");
        $stmt->execute([...$values, Time::toDb(Time::now()), $id]);

        return $changed;
    }

    public function touch(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE objects SET updated_at = ? WHERE id = ?');
        $stmt->execute([Time::toDb(Time::now()), $id]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM objects WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function uniqueSlug(string $base, ?string $exceptId = null): string
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM objects WHERE slug = ? AND id <> ?');
        $slug = $base;
        for ($i = 2; ; $i++) {
            $stmt->execute([$slug, $exceptId ?? '']);
            if ($stmt->fetchColumn() === false) {
                return $slug;
            }
            $slug = $base . '-' . $i;
        }
    }

    /**
     * Seznam objektů podle filtru (typ, tag, text, životní cyklus), seřazený podle názvu.
     *
     * @param array{type?: string, tag?: string, q?: string, lifecycle?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function search(array $filter, int $limit = 500): array
    {
        $where = [];
        $params = [];
        if (($filter['type'] ?? '') !== '') {
            $where[] = 'o.type = ?';
            $params[] = $filter['type'];
        }
        if (($filter['lifecycle'] ?? '') !== '') {
            $where[] = 'o.lifecycle = ?';
            $params[] = $filter['lifecycle'];
        }
        if (($filter['tag'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM object_tags ot JOIN tags t ON t.id = ot.tag_id WHERE ot.object_id = o.id AND t.name = ?)';
            $params[] = $filter['tag'];
        }
        if (($filter['q'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filter['q'])) . '%';
            $where[] = "(lower(o.name) LIKE ? ESCAPE '\\' OR o.slug LIKE ? ESCAPE '\\' OR lower(o.description) LIKE ? ESCAPE '\\'
                OR EXISTS (SELECT 1 FROM object_external_ids x WHERE x.object_id = o.id AND lower(x.external_id) LIKE ? ESCAPE '\\')
                OR EXISTS (SELECT 1 FROM object_attributes a WHERE a.object_id = o.id AND lower(a.value) LIKE ? ESCAPE '\\'))";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = 'SELECT o.* FROM objects o' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY lower(o.name), o.id LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM objects ORDER BY created_at, id')->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM objects')->fetchColumn();
    }

    /**
     * @return array<string, string> id => název, pro výběrové seznamy
     */
    public function names(): array
    {
        $rows = $this->pdo->query('SELECT id, name FROM objects ORDER BY lower(name), id')->fetchAll();

        return array_column($rows, 'name', 'id');
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM objects WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['id']] = $row;
        }

        return $result;
    }
}
