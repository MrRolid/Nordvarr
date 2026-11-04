<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 0008 přidá typy poskytovatel a externí služba. Objekty a vztahy musí zůstat.
 */
final class ProviderTypesMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        mkdir($this->dir . '/migrations');
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            if (strcmp(basename($file), '0008') < 0) {
                copy($file, $this->dir . '/migrations/' . basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testNewTypesAfterMigration(): void
    {
        $pdo = Database::connectSqlite($this->dir . '/db.sqlite');
        (new Migrator($pdo, $this->dir . '/migrations'))->migrate();
        $inventory = new Inventory($pdo);
        $server = $inventory->objects->create(['type' => 'server', 'name' => 'vps1.example.net']);
        $container = $inventory->objects->create(['type' => 'container', 'name' => 'grafana']);
        $inventory->relate($server, $container, 'contains', '', 'manual');
        try {
            $inventory->objects->create(['type' => 'provider', 'name' => 'před migrací']);
            self::fail('Před migrací 0008 typ poskytovatel databáze nepřijme.');
        } catch (\PDOException) {
            // Očekáváno: omezení CHECK.
        }

        copy(dirname(__DIR__, 2) . '/migrations/0008_poskytovatele.sql', $this->dir . '/migrations/0008_poskytovatele.sql');
        self::assertSame(['0008'], (new Migrator($pdo, $this->dir . '/migrations'))->migrate());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame(['grafana'], array_column($inventory->relationships->forObject($server)['outgoing'], 'other_name'));

        $provider = $inventory->objects->create(['type' => 'provider', 'name' => 'Hosting s.r.o.']);
        $inventory->objects->create(['type' => 'external', 'name' => 'Veřejné DNS']);
        $inventory->relate($provider, $server, 'contains', '', 'manual');
        self::assertSame(['vps1.example.net'], array_column($inventory->relationships->forObject($provider)['outgoing'], 'other_name'));
    }
}
