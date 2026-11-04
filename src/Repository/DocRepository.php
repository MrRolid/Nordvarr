<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Dokumentace u objektu: aktuální text v každé kategorii a neměnné revize.
 */
final class DocRepository
{
    /** Hlavní kategorie, do které patří dokumentace bez určení kategorie (API, topologie). */
    public const MAIN = 'provoz';

    /** Pořadí kategorií na kartě objektu. */
    public const CATEGORIES = ['provoz', 'runbook', 'dr', 'incidenty', 'ostatni'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function isCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(string $objectId, string $category = self::MAIN): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM object_docs WHERE object_id = ? AND category = ?');
        $stmt->execute([$objectId, $category]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Všechny neprázdné dokumenty objektu v pořadí CATEGORIES.
     *
     * @return array<string, array<string, mixed>> kategorie => řádek
     */
    public function all(string $objectId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM object_docs WHERE object_id = ? AND markdown <> ''");
        $stmt->execute([$objectId]);
        $byCategory = [];
        foreach ($stmt->fetchAll() as $row) {
            $byCategory[(string) $row['category']] = $row;
        }
        $out = [];
        foreach (self::CATEGORIES as $category) {
            if (isset($byCategory[$category])) {
                $out[$category] = $byCategory[$category];
            }
        }

        return $out;
    }

    /**
     * Uloží nový text. Když se neliší od aktuálního, nic nezapíše a vrátí false.
     */
    public function save(string $objectId, string $markdown, string $author, string $category = self::MAIN): bool
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $current = $this->current($objectId, $category);
        if ($current !== null && (string) $current['markdown'] === $markdown) {
            return false;
        }
        if ($current === null && $markdown === '') {
            return false;
        }
        $now = Time::toDb(Time::now());
        $stmt = $this->pdo->prepare(
            'INSERT INTO object_docs (object_id, category, markdown, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (object_id, category) DO UPDATE SET markdown = excluded.markdown, updated_at = excluded.updated_at,
             updated_by = excluded.updated_by'
        );
        $stmt->execute([$objectId, $category, $markdown, $now, $author]);
        $this->addRevision($objectId, $author, $now, $markdown, null, $category);

        return true;
    }

    public function addRevision(
        string $objectId,
        string $author,
        string $createdAt,
        string $markdown,
        ?string $id = null,
        string $category = self::MAIN,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO doc_revisions (id, object_id, category, author, created_at, markdown) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id ?? Uuid::v7(), $objectId, $category, $author, $createdAt, $markdown]);
    }

    /**
     * Zapíše aktuální text bez vytvoření revize (import).
     */
    public function restoreCurrent(string $objectId, string $markdown, string $updatedAt, string $updatedBy, string $category = self::MAIN): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO object_docs (object_id, category, markdown, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$objectId, $category, $markdown, $updatedAt, $updatedBy]);
    }

    /**
     * @return list<array<string, mixed>> nejnovější první; bez kategorie všechny revize objektu
     */
    public function revisions(string $objectId, ?string $category = null): array
    {
        if ($category === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM doc_revisions WHERE object_id = ? ORDER BY created_at DESC, id DESC');
            $stmt->execute([$objectId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM doc_revisions WHERE object_id = ? AND category = ? ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute([$objectId, $category]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Počet revizí podle kategorie.
     *
     * @return array<string, int>
     */
    public function revisionCounts(string $objectId): array
    {
        $stmt = $this->pdo->prepare('SELECT category, COUNT(*) AS n FROM doc_revisions WHERE object_id = ? GROUP BY category');
        $stmt->execute([$objectId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['category']] = (int) $row['n'];
        }

        return $out;
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
