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

    public function testDefaultDataDirFallsBackInsideAppWhenParentIsNotUsable(): void
    {
        // Jako na sdíleném hostingu: vedle aplikace zapisovat nejde (tady tam překáží soubor).
        file_put_contents($this->tmp . '/nordvarr-data', '');
        $installer = new Installer($this->app);
        self::assertSame($this->app . '/nordvarr-data', $installer->defaultDataDir($this->app . '/public'));
        self::assertSame([], $installer->validate($this->input(['data_dir' => $this->app . '/nordvarr-data'])));

        $installer->install($this->input(['data_dir' => $this->app . '/nordvarr-data']));
        self::assertFileExists($this->app . '/nordvarr-data/nordvarr.sqlite');
        self::assertFileExists($this->app . '/nordvarr-data/.htaccess');
    }

    public function testDefaultDataDirAvoidsDocumentRoot(): void
    {
        $installer = new Installer($this->app);
        // Celá aplikace ve webrootu: návrh vedle aplikace, ne uvnitř.
        self::assertSame($this->tmp . '/nordvarr-data', $installer->defaultDataDir($this->app));
        // Obě možnosti ve webrootu: návrh vedle aplikace (instalátor pak varuje).
        self::assertSame($this->tmp . '/nordvarr-data', $installer->defaultDataDir($this->tmp));
    }

    public function testOpenBasedir(): void
    {
        self::assertTrue(Installer::allowedByOpenBasedir('/var/www/vhosts/example/data', ''));
        self::assertTrue(Installer::allowedByOpenBasedir('/var/www/vhosts/example/data', '/var/www/vhosts/example/:/tmp/'));
        self::assertFalse(Installer::allowedByOpenBasedir('/var/www/vhosts/data', '/var/www/vhosts/example/:/tmp/'));
        self::assertFalse(Installer::allowedByOpenBasedir('/var/www/vhosts/example2/data', '/var/www/vhosts/example'));
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

        foreach ([$this->app, $this->app . '/', $this->app . '/public/data', $this->app . '/src/x'] as $dir) {
            $errors = $installer->validate($this->input(['data_dir' => $dir]));
            self::assertSame('install.err_data_dir_in_app', $errors['data_dir'], $dir);
        }
        self::assertSame([], $installer->validate($this->input(['data_dir' => $this->app . '/nordvarr-data'])), 'Podadresář aplikace mimo kód je povolený.');

        file_put_contents($this->tmp . '/soubor', '');
        $errors = $installer->validate($this->input(['data_dir' => $this->tmp . '/soubor/data']));
        self::assertSame('install.err_data_dir_create', $errors['data_dir'], 'Adresář nejde vytvořit.');

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
