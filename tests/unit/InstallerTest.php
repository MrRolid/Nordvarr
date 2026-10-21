<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Config;
use Nordvarr\Db\Database;
use Nordvarr\Installer;
use Nordvarr\Repository\UserRepository;
use Nordvarr\Security\Passwords;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallerTest extends TestCase
{
    private string $tmp;
    private string $app;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        $this->app = TempDir::copyApp($this->tmp);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /**
     * @return array{data_dir: string, email: string, password: string, password_confirm: string, timezone: string}
     */
    private function input(array $override = []): array
    {
        return $override + [
            'data_dir' => $this->tmp . '/nordvarr-data',
            'email' => 'Admin@Example.com',
            'password' => 'tajne-heslo-1',
            'password_confirm' => 'tajne-heslo-1',
            'timezone' => 'Europe/Prague',
        ];
    }

    public function testDefaultDataDirIsNextToApp(): void
    {
        self::assertSame($this->tmp . '/nordvarr-data', (new Installer($this->app))->defaultDataDir());
    }

    public function testRequirementsAreMetInTestEnvironment(): void
    {
        self::assertTrue((new Installer($this->app))->requirementsMet());
    }

    public function testValidation(): void
    {
        $installer = new Installer($this->app);
        self::assertSame([], $installer->validate($this->input()));

        $errors = $installer->validate($this->input([
            'data_dir' => 'relativni/cesta',
            'email' => 'neni-email',
            'password' => 'kratke',
            'password_confirm' => 'kratke',
            'timezone' => 'Mars/Olympus',
        ]));
        self::assertSame(['data_dir', 'email', 'password', 'timezone'], array_keys($errors));

        $errors = $installer->validate($this->input(['password_confirm' => 'jine-heslo-123']));
        self::assertSame(['password_confirm' => 'install.err_password_confirm'], $errors);

        $errors = $installer->validate($this->input(['data_dir' => $this->app . '/data']));
        self::assertSame('install.err_data_dir_in_app', $errors['data_dir']);

        $errors = $installer->validate($this->input(['data_dir' => $this->tmp . '/../etc']));
        self::assertSame('install.err_data_dir', $errors['data_dir']);
    }

    public function testInstallCreatesConfigDatabaseAdminAndLocks(): void
    {
        $installer = new Installer($this->app);
        $installer->install($this->input(), '192.0.2.10');

        self::assertTrue($installer->isLocked());
        $config = Config::load($this->app . '/config.php');
        self::assertSame($this->tmp . '/nordvarr-data', $config->dataDir());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $config->appSecret());
        self::assertFalse($config->debug());

        foreach (['backups', 'logs', 'sessions', 'locks'] as $sub) {
            self::assertDirectoryExists($config->dataDir() . '/' . $sub);
        }
        self::assertStringContainsString('Require all denied', (string) file_get_contents($config->dataDir() . '/.htaccess'));

        $users = new UserRepository(Database::connectSqlite($config->databasePath()));
        $admin = $users->findByEmail('admin@example.com');
        self::assertNotNull($admin);
        self::assertSame('admin', $admin['role']);
        self::assertTrue(Passwords::verify('tajne-heslo-1', $admin['password_hash']));
        self::assertStringStartsWith('$argon2id$', $admin['password_hash']);

        $this->expectException(RuntimeException::class);
        $installer->install($this->input(['data_dir' => $this->tmp . '/jina-data']));
    }

    public function testSecondInstallIntoUsedDataDirIsRejected(): void
    {
        (new Installer($this->app))->install($this->input());
        $second = TempDir::copyApp($this->tmp . '/druha');
        $errors = (new Installer($second))->validate($this->input());
        self::assertSame('install.err_data_dir_used', $errors['data_dir']);
    }

    public function testCronLineContainsRealPath(): void
    {
        $line = (new Installer($this->app))->cronLine();
        self::assertSame('* * * * * php ' . $this->app . '/bin/nordvarr.php worker >/dev/null 2>&1', $line);

        $odd = new Installer('/srv/moje aplikace');
        self::assertStringContainsString("'/srv/moje aplikace/bin/nordvarr.php'", $odd->cronLine());
    }

    public function testDocumentRootDetection(): void
    {
        self::assertTrue(Installer::isInsideDocumentRoot('/var/www/html/data', '/var/www/html'));
        self::assertFalse(Installer::isInsideDocumentRoot('/var/www/html-data', '/var/www/html'));
        self::assertFalse(Installer::isInsideDocumentRoot('/srv/nordvarr-data', '/srv/nordvarr/public'));
        self::assertFalse(Installer::isInsideDocumentRoot('/srv/nordvarr-data', ''));
    }
}
