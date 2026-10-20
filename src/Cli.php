<?php

declare(strict_types=1);

namespace Nordvarr;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Checks\CurlRunner;
use Nordvarr\Checks\PingRunner;
use Nordvarr\Checks\RdapRunner;
use Nordvarr\Checks\Worker;
use Nordvarr\Http\Client\CurlHttpClient;
use Nordvarr\Inventory\DemoData;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\HttpCacheRepository;
use Throwable;

/**
 * Příkazy pro bin/nordvarr.php.
 */
final class Cli
{
    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * @param list<string> $args
     * @param resource $out
     * @param resource $err
     */
    public function run(array $args, $out, $err): int
    {
        $command = $args[0] ?? 'help';
        try {
            return match ($command) {
                'version' => $this->version($out),
                'migrate' => $this->migrate($out),
                'worker' => $this->worker($err),
                'demo-data' => $this->demoData($out, $err, in_array('--force', $args, true)),
                default => $this->help($out, $command !== 'help'),
            };
        } catch (Throwable $e) {
            fwrite($err, 'Chyba: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param resource $out
     */
    private function version($out): int
    {
        fwrite($out, 'Nordvarr ' . Installer::appVersion($this->appRoot) . "\n");

        return 0;
    }

    /**
     * @param resource $out
     */
    private function migrate($out): int
    {
        $config = Config::load($this->appRoot . '/config.php');
        $migrator = new Migrator(Database::connectSqlite($config->databasePath()), $this->appRoot . '/migrations');
        $pending = $migrator->pending();
        if ($pending === []) {
            fwrite($out, "Žádné čekající migrace.\n");

            return 0;
        }
        $backup = $config->dataDir() . '/backups/nordvarr-' . gmdate('Ymd-His') . '-pred-' . array_key_first($pending) . '.sqlite';
        $migrator->backup($backup);
        $done = $migrator->migrate();
        fwrite($out, 'Provedené migrace: ' . implode(', ', $done) . "\nZáloha: $backup\n");

        return 0;
    }

    /**
     * Worker: kontroly na řadě, agregace a retence. Zámek brání souběhu,
     * celková doba běhu je omezená na 50 s.
     *
     * @param resource $err
     */
    private function worker($err): int
    {
        $start = microtime(true);
        $config = Config::load($this->appRoot . '/config.php');
        $lockDir = $config->dataDir() . '/locks';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0750, true);
        }
        $lock = fopen($lockDir . '/worker.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        try {
            $pdo = Database::connectSqlite($config->databasePath());
            if ((new Migrator($pdo, $this->appRoot . '/migrations'))->pending() !== []) {
                // Migrace spouští přihlášený admin (nebo příkaz migrate); do té doby worker čeká.
                fwrite($err, "Databáze čeká na migrace, worker nic nespustil.\n");

                return 0;
            }
            $userAgent = 'Nordvarr/' . Installer::appVersion($this->appRoot);
            $worker = new Worker(
                $pdo,
                new CurlRunner($userAgent),
                new PingRunner((string) $config->get('ping_binary', 'ping')),
                new RdapRunner(
                    new CurlHttpClient($userAgent),
                    new HttpCacheRepository($pdo),
                    (string) $config->get('rdap_bootstrap_url', RdapRunner::BOOTSTRAP_URL),
                ),
            );
            $worker->run($start + Worker::MAX_RUNTIME);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return 0;
    }

    /**
     * Nahraje ukázková data. Do inventáře s jinými daty jen s --force.
     *
     * @param resource $out
     * @param resource $err
     */
    private function demoData($out, $err, bool $force): int
    {
        $config = Config::load($this->appRoot . '/config.php');
        $pdo = Database::connectSqlite($config->databasePath());
        if ((new Migrator($pdo, $this->appRoot . '/migrations'))->pending() !== []) {
            fwrite($err, "Databáze čeká na migrace. Spusťte nejdřív: php bin/nordvarr.php migrate\n");

            return 1;
        }
        $inventory = new Inventory($pdo);
        $foreign = (int) $pdo->query(
            "SELECT COUNT(*) FROM objects WHERE id NOT IN (SELECT object_id FROM object_external_ids WHERE source = 'demo')"
        )->fetchColumn();
        if ($foreign > 0 && !$force) {
            fwrite($err, "Inventář už obsahuje jiné objekty ($foreign). Ukázková data se přidají jen s volbou --force.\n");

            return 1;
        }
        $result = (new DemoData($inventory))->load();
        fwrite($out, "Ukázková data nahrána: objektů {$result['objects']}, událostí {$result['events']}.\n");

        return 0;
    }

    /**
     * @param resource $out
     */
    private function help($out, bool $unknown): int
    {
        fwrite($out, "Použití: php bin/nordvarr.php <worker|migrate|demo-data [--force]|version>\n");

        return $unknown ? 2 : 0;
    }
}
