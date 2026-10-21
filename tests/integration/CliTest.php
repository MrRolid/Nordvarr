<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Checks\CheckTypes;
use Nordvarr\Config;
use Nordvarr\Db\Database;
use Nordvarr\Installer;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Notify\ChannelTypes;
use Nordvarr\Repository\ChannelRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Security\SecretBox;
use Nordvarr\Tests\FixtureServers;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $tmp;
    private string $app;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        $this->app = TempDir::copyApp($this->tmp);
        (new Installer($this->app))->install([
            'data_dir' => $this->tmp . '/nordvarr-data',
            'email' => 'admin@example.com',
            'password' => 'tajne-heslo-1',
            'password_confirm' => 'tajne-heslo-1',
            'timezone' => 'UTC',
        ]);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /**
     * @return array{int, string}
     */
    private function cli(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->app . '/bin/nordvarr.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    public function testVersion(): void
    {
        [$code, $out] = $this->cli('version');
        self::assertSame(0, $code);
        self::assertSame('Nordvarr ' . Installer::appVersion($this->app), $out);
    }

    public function testWorkerRunsQuietlyAndRecordsLastRun(): void
    {
        [$code, $out] = $this->cli('worker');
        self::assertSame(0, $code);
        self::assertSame('', $out);
        $pdo = new \PDO('sqlite:' . $this->tmp . '/nordvarr-data/nordvarr.sqlite');
        $value = $pdo->query("SELECT value FROM settings WHERE key = 'worker_last_run'")->fetchColumn();
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $value);
    }

    public function testMigrateCreatesBackupWhenSomethingIsPending(): void
    {
        [$code, $out] = $this->cli('migrate');
        self::assertSame(0, $code);
        self::assertStringContainsString('Žádné čekající migrace', $out);

        file_put_contents($this->app . '/migrations/9001_cli_test.sql', 'CREATE TABLE cli_test (id TEXT);');
        [$code, $out] = $this->cli('migrate');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('9001', $out);
        self::assertCount(1, glob($this->tmp . '/nordvarr-data/backups/*.sqlite') ?: []);
    }

    public function testWorkerRunsChecksAgainstFixtureServer(): void
    {
        $servers = new FixtureServers();
        try {
            $base = $servers->http();
            $pdo = Database::connectSqlite($this->tmp . '/nordvarr-data/nordvarr.sqlite');
            $object = (new Inventory($pdo))->objects->create(['type' => 'website', 'name' => 'www.example.com']);
            $checks = new CheckRepository($pdo);
            $ok = CheckTypes::validate('http', ['url' => $base . '/ok']);
            $okId = $checks->create($object, 'http', $ok['common'], $ok['config']);
            $bad = CheckTypes::validate('http', ['url' => $base . '/status/503', 'failure_threshold' => '1']);
            $badId = $checks->create($object, 'http', $bad['common'], $bad['config']);

            [$code, $out] = $this->cli('worker');
            self::assertSame(0, $code, $out);
            self::assertSame('healthy', $checks->find($okId)['status']);
            self::assertSame(['unhealthy', 'http_5xx'], [$checks->find($badId)['status'], $checks->find($badId)['reason_code']]);
        } finally {
            $servers->stop();
        }
    }

    public function testWorkerSendsAlertEmailThroughFakeSmtp(): void
    {
        $servers = new FixtureServers();
        $mailDir = TempDir::create('nordvarr-mail-');
        try {
            $base = $servers->http();
            $smtpPort = $servers->smtp($mailDir);
            $pdo = Database::connectSqlite($this->tmp . '/nordvarr-data/nordvarr.sqlite');
            $object = (new Inventory($pdo))->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
            $valid = CheckTypes::validate('http', ['url' => $base . '/status/503', 'failure_threshold' => '1']);
            (new CheckRepository($pdo))->create($object, 'http', $valid['common'], $valid['config']);
            $box = new SecretBox(Config::load($this->app . '/config.php')->appSecret());
            $channel = ChannelTypes::validate('email', ['from' => 'nordvarr@example.com', 'to' => 'admin@example.com',
                'primary_host' => '127.0.0.1', 'primary_port' => (string) $smtpPort, 'primary_security' => 'none'], null, $box);
            self::assertSame([], $channel['errors']);
            (new ChannelRepository($pdo))->create('E-mail', 'email', $channel['config'], true, []);

            [$code, $out] = $this->cli('worker');
            self::assertSame(0, $code, $out);
            $mails = glob($mailDir . '/*.json') ?: [];
            self::assertCount(1, $mails);
            $mail = json_decode((string) file_get_contents($mails[0]), true);
            self::assertStringContainsString('Subject: [Nordvarr] NEFUNGUJE: shop.example.com (HTTP: http_5xx)', $mail['data']);
            self::assertSame('sent', $pdo->query('SELECT status FROM deliveries')->fetchColumn());

            // Další běh nic nepošle (alert je otevřený, připomínka až za 24 h).
            $this->cli('worker');
            self::assertCount(1, glob($mailDir . '/*.json') ?: []);
        } finally {
            $servers->stop();
            TempDir::remove($mailDir);
        }
    }

    public function testWorkerWaitsForPendingMigrations(): void
    {
        file_put_contents($this->app . '/migrations/9002_cekajici.sql', 'CREATE TABLE cekajici (id TEXT);');
        [$code, $out] = $this->cli('worker');
        self::assertSame(0, $code);
        self::assertStringContainsString('čeká na migrace', $out);
    }

    public function testUnknownCommand(): void
    {
        [$code] = $this->cli('neznamy');
        self::assertSame(2, $code);
    }
}
