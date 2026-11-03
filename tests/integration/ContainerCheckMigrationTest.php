<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\CheckResultRepository;
use Nordvarr\Tests\TempDir;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 0007 přestaví tabulku checks kvůli typu "container". Výsledky a stav kontrol musí zůstat.
 */
final class ContainerCheckMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
        mkdir($this->dir . '/migrations');
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            if (strcmp(basename($file), '0007') < 0) {
                copy($file, $this->dir . '/migrations/' . basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testRebuildKeepsResultsAndState(): void
    {
        $pdo = Database::connectSqlite($this->dir . '/db.sqlite');
        (new Migrator($pdo, $this->dir . '/migrations'))->migrate();
        $container = (new Inventory($pdo))->objects->create(['type' => 'container', 'name' => 'grafana']);
        $service = new CheckService($pdo);
        $valid = CheckTypes::validate('tcp', ['host' => '192.0.2.38', 'port' => '3000', 'failure_threshold' => '1']);
        $tcp = $service->checks->create($container, 'tcp', $valid['common'], $valid['config']);
        $at = Time::toDb(Time::now());
        $service->record((array) $service->checks->find($tcp), new CheckResult('unhealthy', 'tcp_refused', 'refused', null, $at, $at));

        copy(dirname(__DIR__, 2) . '/migrations/0007_kontrola_kontejneru.sql', $this->dir . '/migrations/0007_kontrola_kontejneru.sql');
        self::assertSame(['0007'], (new Migrator($pdo, $this->dir . '/migrations'))->migrate());
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());

        $check = (array) $service->checks->find($tcp);
        self::assertSame(['tcp', 'unhealthy', 'tcp_refused'], [$check['type'], $check['status'], $check['reason_code']]);
        self::assertCount(1, (new CheckResultRepository($pdo))->recent($tcp));

        $valid = CheckTypes::validate('container', []);
        $id = $service->checks->create($container, 'container', $valid['common'], $valid['config']);
        self::assertSame(['grace_s' => 180], $service->checks->find($id)['config']);

        // Kaskádové mazání výsledků po přestavbě dál funguje.
        $pdo->prepare('DELETE FROM checks WHERE id = ?')->execute([$tcp]);
        self::assertSame([], (new CheckResultRepository($pdo))->recent($tcp));
    }
}
