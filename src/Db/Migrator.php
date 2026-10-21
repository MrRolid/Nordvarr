<?php

declare(strict_types=1);

namespace Nordvarr\Db;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Spouští číslované SQL migrace z adresáře migrations/ (NNNN_popis.sql).
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * @return array<string, string> verze => cesta k souboru, seřazené vzestupně
     */
    public function available(): array
    {
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        $result = [];
        foreach ($files as $file) {
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', basename($file), $m) === 1) {
                $result[$m[1]] = $file;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version');

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, string>
     */
    public function pending(): array
    {
        return array_diff_key($this->available(), array_flip($this->applied()));
    }

    /**
     * Vytvoří konzistentní kopii databáze (VACUUM INTO) do zadaného souboru.
     */
    public function backup(string $targetFile): void
    {
        if (file_exists($targetFile)) {
            throw new RuntimeException('Soubor zálohy už existuje: ' . $targetFile);
        }
        $stmt = $this->pdo->prepare('VACUUM INTO ?');
        $stmt->execute([$targetFile]);
    }

    /**
     * Provede čekající migrace, každou ve vlastní transakci.
     *
     * @return list<string> provedené verze
     */
    public function migrate(): array
    {
        $done = [];
        foreach ($this->pending() as $version => $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Migraci nelze načíst: ' . $file);
            }
            // Postup podle dokumentace SQLite pro přestavbu tabulek: cizí klíče se vypínají mimo
            // transakci (jinak by DROP TABLE spustil kaskádové mazání) a po migraci se ověří.
            $foreignKeys = (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $violations = $this->pdo->query('PRAGMA foreign_key_check')->fetchAll();
                if ($violations !== []) {
                    throw new RuntimeException('porušené cizí klíče: ' . count($violations));
                }
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, gmdate('Y-m-d\TH:i:s\Z')]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw new RuntimeException("Migrace $version selhala: " . $e->getMessage(), 0, $e);
            } finally {
                $this->pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeys === 1 ? 'ON' : 'OFF'));
            }
            $done[] = (string) $version;
        }

        return $done;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );
    }
}
