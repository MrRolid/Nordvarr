<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Installer;
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

        file_put_contents($this->app . '/migrations/0002_cli_test.sql', 'CREATE TABLE cli_test (id TEXT);');
        [$code, $out] = $this->cli('migrate');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('0002', $out);
        self::assertCount(1, glob($this->tmp . '/nordvarr-data/backups/*.sqlite') ?: []);
    }

    public function testUnknownCommand(): void
    {
        [$code] = $this->cli('neznamy');
        self::assertSame(2, $code);
    }
}
