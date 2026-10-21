<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use DateTimeImmutable;
use Nordvarr\App;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\CurlRunner;
use Nordvarr\Checks\PingRunner;
use Nordvarr\Checks\RdapRunner;
use Nordvarr\Checks\Worker;
use Nordvarr\Http\Request;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Transfer;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\HttpCacheRepository;
use Nordvarr\Repository\LoginAttemptRepository;
use Nordvarr\Security\Passwords;
use Nordvarr\Tests\FixtureHttpClient;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

/**
 * Opravy z bezpečnostního průchodu F5 (SPEC kap. 11), viz docs/STAV.md.
 */
final class SecurityPassTest extends TestCase
{
    use InstalledApp;

    protected function setUp(): void
    {
        $this->install();
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    public function testLanguageSwitchDoesNotRedirectOffSite(): void
    {
        foreach (["\\evil.example", "/\\evil.example", "/\t/evil.example", '//evil.example', 'https://evil.example/'] as $back) {
            $response = $this->post('/lang', ['lang' => 'en', 'back' => $back]);
            self::assertSame(303, $response->status);
            $location = $response->headers['Location'];
            // Jen cesta v rámci aplikace: začíná jedním "/" a neobsahuje "\" ani řídicí znaky.
            self::assertMatchesRegularExpression('#^/(?![/\\\\])[^\\\\\x00-\x1f]*$#', $location, $back);
        }
        self::assertSame('/inventory', $this->post('/lang', ['lang' => 'cs', 'back' => '/inventory'])->headers['Location']);
    }

    public function testInstalledFilesAreNotWorldReadable(): void
    {
        $dataDir = $this->tmp . '/nordvarr-data';
        foreach ([$this->app . '/config.php', $dataDir, $dataDir . '/nordvarr.sqlite', $dataDir . '/backups'] as $path) {
            clearstatcache();
            self::assertSame(0, fileperms($path) & 0007, sprintf('%s má práva %o', basename($path), fileperms($path) & 0777));
        }
    }

    public function testImportStoresValidatedCheckConfig(): void
    {
        $inventory = new Inventory($this->pdo());
        $id = $inventory->objects->create(['type' => 'server', 'name' => 'srv1.example.com']);
        $valid = CheckTypes::validate('tcp', ['host' => '192.0.2.10', 'port' => '22']);
        (new CheckRepository($this->pdo()))->create($id, 'tcp', $valid['common'], $valid['config']);
        $data = (new Transfer($inventory))->export();
        $data['checks'][0]['config']['host'] = '  192.0.2.11  ';
        $data['checks'][0]['config']['navic'] = 'nečekaný klíč';
        (new Transfer($inventory))->import($data);

        $config = (new CheckRepository($this->pdo()))->forObject($id)[0]['config'];
        self::assertSame('192.0.2.11', $config['host']);
        self::assertArrayNotHasKey('navic', $config);
    }

    public function testArchiveAndTagRemovalAreAudited(): void
    {
        $this->loginAdmin();
        $id = (new Inventory($this->pdo()))->objects->create(['type' => 'server', 'name' => 'srv2.example.com']);
        $this->post('/objects/' . $id . '/tags', ['tag' => 'kriticke']);
        $this->post('/objects/' . $id . '/tags/delete', ['tag' => 'kriticke']);
        $this->post('/objects/' . $id . '/lifecycle');
        $this->post('/objects/' . $id . '/lifecycle');
        $actions = array_column((new AuditLogRepository($this->pdo()))->latest(), 'action');
        foreach (['tag.remove', 'object.archive', 'object.restore'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function testOversizedUploadGetsClearErrorInsteadOfCsrf(): void
    {
        $this->loginAdmin();
        $app = new App($this->app, $this->config());
        // Tělo nad post_max_size: PHP zahodí $_POST i $_FILES, zůstane jen Content-Length.
        $request = new Request('POST', '/settings/import', [], [], [], ['content-length' => '99999999'], '', true, false, '192.0.2.50');
        $response = $app->handle($request, $this->session);
        self::assertSame(413, $response->status);
        self::assertStringContainsString('příliš velký', $response->body);
        self::assertSame(403, $this->ui('POST', '/settings/import', ['x' => '1'])->status, 'Bez tokenu je to dál chyba CSRF.');
    }

    public function testWorkerPurgesOldLoginAttempts(): void
    {
        $attempts = new LoginAttemptRepository($this->pdo());
        $attempts->record('admin@example.com', '192.0.2.9', false, new DateTimeImmutable('-5 days'));
        $attempts->record('admin@example.com', '192.0.2.9', false, Time::now());
        $worker = new Worker(
            $this->pdo(),
            new CurlRunner('Nordvarr-test'),
            new PingRunner(dirname(__DIR__) . '/fixtures/fake-ping'),
            new RdapRunner(new FixtureHttpClient(), new HttpCacheRepository($this->pdo()), 'https://bootstrap.example.net/dns.json'),
        );
        $worker->maintenance(true);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function testOverlongPasswordIsRejectedWithoutHashing(): void
    {
        $hash = Passwords::hash('tajne-heslo-1');
        $start = microtime(true);
        self::assertFalse(Passwords::verify(str_repeat('a', Passwords::MAX_BYTES + 1), $hash));
        self::assertLessThan(0.05, microtime(true) - $start);
        self::assertSame(401, $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => str_repeat('x', 100000)])->status);
    }
}
