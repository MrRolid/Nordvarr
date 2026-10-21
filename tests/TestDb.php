<?php

declare(strict_types=1);

namespace Nordvarr\Tests;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use PDO;

/**
 * Databáze v paměti s provedenými migracemi.
 */
final class TestDb
{
    public static function memory(): PDO
    {
        $pdo = Database::connectSqlite(':memory:');
        (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();

        return $pdo;
    }
}
