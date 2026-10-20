<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Dokumentace u objektu: aktuální text a neměnné revize.
 */
final class DocRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(string $objectId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM object_docs WHERE object_id = ?');
        $stmt->execute([$objectId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Uloží nový text. Když se neliší od aktuálního, nic nezapíše a vrátí false.
     */
    public function save(string $objectId, string $markdown, string $author): bool
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $current = $this->current($objectId);
        if ($current !== null && (string) $current['markdown'] === $markdown) {
            return false;
        }
        if ($current === null && $markdown === '') {
            return false;
        }
        $now = Time::toDb(Time::now());
        $stmt = $this->pdo->prepare(
            'INSERT INTO object_docs (object_id, markdown, updated_at, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (object_id) DO UPDATE SET markdown = excluded.markdown, updated_at = excluded.updated_at,
             updated_by = excluded.updated_by'
        );
        $stmt->execute([$objectId, $markdown, $now, $author]);
        $this->addRevision($objectId, $author, $now, $markdown);

        return true;
    }

    public function addRevision(string $objectId, string $author, string $createdAt, string $markdown, ?string $id = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO doc_revisions (id, object_id, author, created_at, markdown) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id ?? Uuid::v7(), $objectId, $author, $createdAt, $markdown]);
    }

    /**
     * Zapíše aktuální text bez vytvoření revize (import).
     */
    public function restoreCurrent(string $objectId, string $markdown, string $updatedAt, string $updatedBy): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO object_docs (object_id, markdown, updated_at, updated_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$objectId, $markdown, $updatedAt, $updatedBy]);
    }

    /**
     * @return list<array<string, mixed>> nejnovější první
     */
    public function revisions(string $objectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doc_revisions WHERE object_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$objectId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function revision(string $objectId, string $revisionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doc_revisions WHERE object_id = ? AND id = ?');
        $stmt->execute([$objectId, $revisionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
