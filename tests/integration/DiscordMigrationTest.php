<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 0012 přestaví tabulku channels (typ discord). Kanály a jejich pravidla zůstanou.
 */
final class DiscordMigrationTest extends TestCase
{
    public function testRebuildKeepsChannels(): void
    {
        $dir = TempDir::create();
        try {
            mkdir($dir . '/migrations');
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0012') < 0) {
                    copy($file, $dir . '/migrations/' . basename($file));
                }
            }
            $pdo = Database::connectSqlite($dir . '/db.sqlite');
            (new Migrator($pdo, $dir . '/migrations'))->migrate();
            $pdo->exec("INSERT INTO channels (id, name, type, config, enabled, created_at, updated_at) VALUES ('c1', 'E-mail', 'email', '{}', 1, '2026-11-01T00:00:00Z', '2026-11-01T00:00:00Z')");
            $pdo->exec("INSERT INTO channel_rules (channel_id, tag) VALUES ('c1', 'produkce')");
            try {
                $pdo->exec("INSERT INTO channels (id, name, type, config, enabled, created_at, updated_at) VALUES ('c2', 'D', 'discord', '{}', 1, 'x', 'x')");
                self::fail('Před migrací typ discord databáze nepřijme.');
            } catch (\PDOException) {
                // Očekáváno.
            }

            copy(dirname(__DIR__, 2) . '/migrations/0012_discord.sql', $dir . '/migrations/0012_discord.sql');
            self::assertSame(['0012'], (new Migrator($pdo, $dir . '/migrations'))->migrate());
            self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
            self::assertSame('E-mail', $pdo->query("SELECT name FROM channels WHERE id = 'c1'")->fetchColumn());
            $pdo->exec("INSERT INTO channels (id, name, type, config, enabled, created_at, updated_at) VALUES ('c2', 'Discord', 'discord', '{}', 1, 'x', 'x')");
            $pdo->exec("DELETE FROM channels WHERE id = 'c1'");
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM channel_rules')->fetchColumn(), 'Pravidla se dál mažou kaskádově.');
        } finally {
            TempDir::remove($dir);
        }
    }
}
