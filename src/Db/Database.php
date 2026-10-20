<?php

declare(strict_types=1);

namespace Nordvarr\Db;

use PDO;

/**
 * Připojení k SQLite v režimu WAL. K databázi se přistupuje jen přes
 * repozitáře v Nordvarr\Repository.
 */
final class Database
{
    public static function connectSqlite(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        return $pdo;
    }
}
