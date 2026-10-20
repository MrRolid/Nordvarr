<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        mkdir($this->dir . '/migrations');
        file_put_contents($this->dir . '/migrations/0001_a.sql', 'CREATE TABLE a (id TEXT PRIMARY KEY);');
        file_put_contents($this->dir . '/migrations/0002_b.sql', "CREATE TABLE b (id TEXT);\nINSERT INTO b VALUES ('x');");
        file_put_contents($this->dir . '/migrations/README.txt', 'neni migrace');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testMigratesInOrderOnlyOnce(): void
    {
        $pdo = Database::connectSqlite($this->dir . '/db.sqlite');
        $migrator = new Migrator($pdo, $this->dir . '/migrations');
        self::assertSame(['0001', '0002'], array_keys($migrator->pending()));
        self::assertSame(['0001', '0002'], $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate());
        self::assertSame('x', $pdo->query('SELECT id FROM b')->fetchColumn());
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
    }

    public function testFailedMigrationIsRolledBack(): void
    {
        file_put_contents($this->dir . '/migrations/0003_bad.sql', "CREATE TABLE c (id TEXT);\nINSERT INTO neexistuje VALUES (1);");
        $pdo = Database::connectSqlite($this->dir . '/db.sqlite');
        $migrator = new Migrator($pdo, $this->dir . '/migrations');
        try {
            $migrator->migrate();
            self::fail('Migrace měla selhat.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('0003', $e->getMessage());
        }
        self::assertSame(['0001', '0002'], $migrator->applied());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'c'")->fetchAll();
        self::assertSame([], $tables);
    }

    public function testBackupCreatesReadableCopy(): void
    {
        $pdo = Database::connectSqlite($this->dir . '/db.sqlite');
        $migrator = new Migrator($pdo, $this->dir . '/migrations');
        $migrator->migrate();
        $migrator->backup($this->dir . '/backup.sqlite');

        $copy = Database::connectSqlite($this->dir . '/backup.sqlite');
        self::assertSame('x', $copy->query('SELECT id FROM b')->fetchColumn());

        $this->expectException(RuntimeException::class);
        $migrator->backup($this->dir . '/backup.sqlite');
    }
}
