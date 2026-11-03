<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Checks\CheckTypes;
use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 0006 přestaví tabulku objects kvůli novým typům. Data navázaná na objekty
 * (atributy, vztahy, kontroly) musí zůstat a cizí klíče musí dál fungovat.
 */
final class ContainerTypesMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        mkdir($this->dir . '/migrations');
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            if (strcmp(basename($file), '0006') < 0) {
                copy($file, $this->dir . '/migrations/' . basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testRebuildKeepsDataAndForeignKeys(): void
    {
        $pdo = Database::connectSqlite($this->dir . '/db.sqlite');
        (new Migrator($pdo, $this->dir . '/migrations'))->migrate();
        $inventory = new Inventory($pdo);
        $host = $inventory->objects->create(['type' => 'server', 'name' => 'hv1.example.net']);
        $app = $inventory->objects->create(['type' => 'service', 'name' => 'shop-api']);
        $inventory->relate($host, $app, 'contains', 'docker', 'manual');
        $inventory->attributes->set($app, 'port', '8080', false, 'manual');
        $valid = CheckTypes::validate('tcp', ['host' => '192.0.2.10', 'port' => '8080']);
        (new CheckRepository($pdo))->create($app, 'tcp', $valid['common'], $valid['config']);

        try {
            $inventory->objects->create(['type' => 'container', 'name' => 'před migrací']);
            self::fail('Před migrací 0006 typ kontejner databáze nepřijme.');
        } catch (\PDOException) {
            // Očekáváno: omezení CHECK.
        }

        copy(dirname(__DIR__, 2) . '/migrations/0006_kontejnery.sql', $this->dir . '/migrations/0006_kontejnery.sql');
        self::assertSame(['0006'], (new Migrator($pdo, $this->dir . '/migrations'))->migrate());

        self::assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame('hv1.example.net', $inventory->objects->find($host)['name'] ?? null);
        self::assertSame(['shop-api'], array_column($inventory->relationships->forObject($host)['outgoing'], 'other_name'));
        self::assertSame('8080', $inventory->attributes->forObject($app)[0]['value'] ?? null);
        self::assertCount(1, (new CheckRepository($pdo))->forObject($app));
        self::assertSame(['idx_objects_name', 'idx_objects_type'], $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'objects' AND name LIKE 'idx_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN));

        // Nové typy projdou a smazání objektu dál kaskádově maže navázaná data.
        $cluster = $inventory->objects->create(['type' => 'cluster', 'name' => 'k3s']);
        $container = $inventory->objects->create(['type' => 'container', 'name' => 'grafana']);
        $inventory->relate($cluster, $container, 'contains', '', 'manual');
        $pdo->prepare('DELETE FROM objects WHERE id = ?')->execute([$app]);
        self::assertSame([], $inventory->attributes->forObject($app));
        self::assertSame([], (new CheckRepository($pdo))->forObject($app));
        $relations = $pdo->prepare('SELECT COUNT(*) FROM relationships WHERE to_id = ?');
        $relations->execute([$app]);
        self::assertSame(0, (int) $relations->fetchColumn());
    }
}
