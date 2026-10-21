<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

final class RelationshipRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM relationships WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEdge(string $fromId, string $toId, string $kind): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM relationships WHERE from_id = ? AND to_id = ? AND kind = ?');
        $stmt->execute([$fromId, $toId, $kind]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Přidá vztah, pokud ještě neexistuje. Kontrolu cyklů dělá volající.
     * Vrací id vztahu a příznak, jestli vznikl nový.
     *
     * @return array{id: string, created: bool}
     */
    public function add(string $fromId, string $toId, string $kind, string $note, string $source, ?string $id = null, ?string $createdAt = null): array
    {
        $existing = $this->findEdge($fromId, $toId, $kind);
        if ($existing !== null) {
            if ((string) $existing['note'] !== $note && $existing['source'] === $source) {
                $this->pdo->prepare('UPDATE relationships SET note = ? WHERE id = ?')->execute([$note, $existing['id']]);
            }

            return ['id' => (string) $existing['id'], 'created' => false];
        }
        $id ??= Uuid::v7();
        $stmt = $this->pdo->prepare(
            'INSERT INTO relationships (id, from_id, to_id, kind, note, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $fromId, $toId, $kind, $note, $source, $createdAt ?? Time::toDb(Time::now())]);

        return ['id' => $id, 'created' => true];
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM relationships WHERE id = ?')->execute([$id]);
    }

    /**
     * Vytvořil by vztah "from contains to" cyklus? Tj. obsahuje už "to" (i nepřímo) objekt "from"?
     */
    public function wouldCreateContainsCycle(string $fromId, string $toId): bool
    {
        if ($fromId === $toId) {
            return true;
        }
        $stmt = $this->pdo->prepare(
            "WITH RECURSIVE inside(id) AS (
                SELECT to_id FROM relationships WHERE from_id = ? AND kind = 'contains'
                UNION
                SELECT r.to_id FROM relationships r JOIN inside i ON r.from_id = i.id WHERE r.kind = 'contains'
            )
            SELECT 1 FROM inside WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$toId, $fromId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Odchozí vztahy objektu z daného zdroje.
     *
     * @return list<array<string, mixed>>
     */
    public function outgoingFromSource(string $objectId, string $source): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM relationships WHERE from_id = ? AND source = ?');
        $stmt->execute([$objectId, $source]);

        return $stmt->fetchAll();
    }

    /**
     * Vztahy objektu v obou směrech včetně názvu a typu druhé strany.
     *
     * @return array{outgoing: list<array<string, mixed>>, incoming: list<array<string, mixed>>}
     */
    public function forObject(string $objectId): array
    {
        $out = $this->pdo->prepare(
            'SELECT r.*, o.name AS other_name, o.type AS other_type, o.id AS other_id FROM relationships r
             JOIN objects o ON o.id = r.to_id WHERE r.from_id = ? ORDER BY r.kind, lower(o.name)'
        );
        $out->execute([$objectId]);
        $in = $this->pdo->prepare(
            'SELECT r.*, o.name AS other_name, o.type AS other_type, o.id AS other_id FROM relationships r
             JOIN objects o ON o.id = r.from_id WHERE r.to_id = ? ORDER BY r.kind, lower(o.name)'
        );
        $in->execute([$objectId]);

        return ['outgoing' => $out->fetchAll(), 'incoming' => $in->fetchAll()];
    }

    /**
     * Id přímých sousedů (oba směry, oba druhy vztahů).
     *
     * @return list<string>
     */
    public function neighborIds(string $objectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT to_id FROM relationships WHERE from_id = ? UNION SELECT from_id FROM relationships WHERE to_id = ?'
        );
        $stmt->execute([$objectId, $objectId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM relationships ORDER BY created_at, id')->fetchAll();
    }
}
