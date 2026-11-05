<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 0011 přestaví tabulku events (důležitost, nové typy). Události a jejich vazby zůstanou.
 */
final class EventSeverityMigrationTest extends TestCase
{
    public function testRebuildKeepsEvents(): void
    {
        $dir = TempDir::create();
        try {
            mkdir($dir . '/migrations');
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0011') < 0) {
                    copy($file, $dir . '/migrations/' . basename($file));
                }
            }
            $pdo = Database::connectSqlite($dir . '/db.sqlite');
            (new Migrator($pdo, $dir . '/migrations'))->migrate();
            $inventory = new Inventory($pdo);
            $id = $inventory->objects->create(['type' => 'server', 'name' => 'hv1.example.net']);
            $insert = $pdo->prepare("INSERT INTO events (id, source, source_event_id, type, occurred_at, summary, created_at) VALUES (?, 'manual', ?, ?, '2026-11-01T10:00:00Z', ?, '2026-11-01T10:00:00Z')");
            $insert->execute(['e1', 's1', 'deploy', 'nasazení 1.2']);
            $insert->execute(['e2', 's2', 'incident', 'výpadek']);
            $pdo->exec("INSERT INTO event_objects (event_id, object_id) VALUES ('e1', '$id'), ('e2', '$id')");

            copy(dirname(__DIR__, 2) . '/migrations/0011_dulezitost_udalosti.sql', $dir . '/migrations/0011_dulezitost_udalosti.sql');
            self::assertSame(['0011'], (new Migrator($pdo, $dir . '/migrations'))->migrate());
            self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
            self::assertSame([['e1', 'info'], ['e2', 'warning']], $pdo->query('SELECT id, severity FROM events ORDER BY id')->fetchAll(\PDO::FETCH_NUM));
            self::assertCount(2, $inventory->timeline($id));

            $inventory->addManualEvent($id, 'security', 'fail2ban', '', '2026-11-02T10:00:00Z', 'critical');
            $pdo->prepare('DELETE FROM objects WHERE id = ?')->execute([$id]);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM event_objects')->fetchColumn(), 'Vazby se dál mažou kaskádově.');
        } finally {
            TempDir::remove($dir);
        }
    }
}
