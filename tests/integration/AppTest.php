<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\App;
use Nordvarr\Config;
use Nordvarr\Db\Database;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;
use Nordvarr\Installer;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\UserRepository;
use Nordvarr\Security\Passwords;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Průchod aplikací v jednom procesu nad skutečnou instalací v dočasném adresáři.
 */
final class AppTest extends TestCase
{
    private const EMAIL = 'admin@example.com';
    private const PASSWORD = 'tajne-heslo-1';

    private string $tmp;
    private string $app;
    private Session $session;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        $this->app = TempDir::copyApp($this->tmp);
        (new Installer($this->app))->install([
            'data_dir' => $this->tmp . '/nordvarr-data',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'timezone' => 'Europe/Prague',
        ]);
        $this->session = Session::inMemory();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function request(string $method, string $path, array $post = [], array $headers = [], array $cookies = [], bool $pretty = true): Response
    {
        $app = new App($this->app, Config::load($this->app . '/config.php'));

        return $app->handle(new Request($method, $path, [], $post, $cookies, $headers, '', $pretty, false, '192.0.2.50'), $this->session);
    }

    private function csrf(): string
    {
        $html = $this->request('GET', '/login')->body;
        self::assertMatchesRegularExpression('/name="_csrf" value="([0-9a-f]{64})"/', $html);
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m);

        return $m[1];
    }

    private function login(string $password = self::PASSWORD): Response
    {
        return $this->request('POST', '/login', ['_csrf' => $this->csrf(), 'email' => self::EMAIL, 'password' => $password]);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $response = $this->request('GET', '/');
        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location']);

        $fallback = $this->request('GET', '/settings', pretty: false);
        self::assertSame('/index.php?r=/login', $fallback->headers['Location']);
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        foreach ([$this->request('GET', '/login'), $this->request('GET', '/'), $this->request('GET', '/nic')] as $response) {
            self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);
            self::assertStringNotContainsString('unsafe-inline', $response->headers['Content-Security-Policy']);
            self::assertSame('DENY', $response->headers['X-Frame-Options']);
            self::assertSame('same-origin', $response->headers['Referrer-Policy']);
            self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        }
    }

    public function testPagesHaveNoInlineScriptsOrHandlers(): void
    {
        $this->login();
        foreach (['/', '/inventory', '/topology', '/alerts', '/settings'] as $path) {
            $html = $this->request('GET', $path)->body;
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $path);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, $path);
        }
    }

    public function testLoginAndLogout(): void
    {
        $failed = $this->login('spatne-heslo');
        self::assertSame(401, $failed->status);
        self::assertStringContainsString('Nesprávný e-mail nebo heslo.', $failed->body);

        $ok = $this->login();
        self::assertSame(303, $ok->status);
        self::assertSame('/', $ok->headers['Location']);
        self::assertGreaterThan(0, $this->session->get('_regenerated'));

        $home = $this->request('GET', '/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('<h1>Přehled</h1>', $home->body);
        self::assertStringContainsString(self::EMAIL, $home->body);

        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $home->body, $m);
        $logout = $this->request('POST', '/logout', ['_csrf' => $m[1]]);
        self::assertSame(303, $logout->status);
        self::assertSame(303, $this->request('GET', '/')->status);

        $actions = array_column((new AuditLogRepository($this->pdo()))->latest(), 'action');
        self::assertContains('login.failed', $actions);
        self::assertContains('login.success', $actions);
        self::assertContains('logout', $actions);
    }

    public function testUnknownEmailGivesSameGenericMessage(): void
    {
        $response = $this->request('POST', '/login', ['_csrf' => $this->csrf(), 'email' => 'nikdo@example.com', 'password' => 'x']);
        self::assertSame(401, $response->status);
        self::assertStringContainsString('Nesprávný e-mail nebo heslo.', $response->body);
    }

    public function testPostWithoutValidCsrfIsRejected(): void
    {
        $this->csrf();
        $response = $this->request('POST', '/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertSame(403, $response->status);
        $response = $this->request('POST', '/login', ['_csrf' => str_repeat('0', 64), 'email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertSame(403, $response->status);
        self::assertNull($this->session->get('user_id'));
    }

    public function testEveryUiPostRouteRequiresCsrf(): void
    {
        $this->login();
        $paths = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/{App.php,Ui/*.php}', GLOB_BRACE) ?: [] as $file) {
            preg_match_all("/->post\\('([^']+)'/", (string) file_get_contents($file), $m);
            array_push($paths, ...$m[1]);
        }
        self::assertGreaterThan(25, count($paths), 'Test našel POST adresy UI.');
        foreach (array_unique($paths) as $pattern) {
            $path = preg_replace('/\{[a-z]+\}/', '01a15b85-582e-7a65-b77a-24a0065da59a', $pattern);
            self::assertSame(403, $this->request('POST', $path, ['confirm' => '1'])->status, $pattern);
            self::assertSame(403, $this->request('POST', $path, ['_csrf' => str_repeat('a', 64), 'confirm' => '1'])->status, $pattern);
        }
        self::assertNotNull($this->session->get('user_id'), 'Odmítnutý POST nikoho neodhlásí.');
    }

    public function testLockAfterTenFailures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $this->login('spatne-' . $i)->status);
        }
        // Do databáze doplníme dalších 6 selhání, aby test nemusel čekat na postupné zpoždění.
        $stmt = $this->pdo()->prepare("INSERT INTO login_attempts (id, email, ip, success, attempted_at) VALUES (?, ?, '192.0.2.50', 0, ?)");
        for ($i = 0; $i < 6; $i++) {
            $stmt->execute(['x' . $i, self::EMAIL, gmdate('Y-m-d\TH:i:s\Z')]);
        }
        $response = $this->login();
        self::assertSame(429, $response->status);
        self::assertStringContainsString('Příliš mnoho neúspěšných pokusů', $response->body);
        self::assertNull($this->session->get('user_id'));
    }

    public function testBearerTokenIsRejectedByUi(): void
    {
        $response = $this->request('GET', '/', [], ['authorization' => 'Bearer abc']);
        self::assertSame(401, $response->status);
    }

    public function testPasswordChangeInvalidatesSession(): void
    {
        $this->login();
        self::assertSame(200, $this->request('GET', '/')->status);
        $users = new UserRepository($this->pdo());
        $admin = $users->findByEmail(self::EMAIL);
        $users->updatePassword((string) $admin['id'], Passwords::hash('nove-heslo-123'));
        self::assertSame(303, $this->request('GET', '/')->status);
    }

    public function testLanguageSwitchSetsCookieAndEnglishIsRendered(): void
    {
        $response = $this->request('POST', '/lang', ['_csrf' => $this->csrf(), 'lang' => 'en', 'back' => '//example.com/x']);
        self::assertSame(303, $response->status);
        self::assertSame('/example.com/x', $response->headers['Location']);
        self::assertSame('nordvarr_lang', $response->cookies[0]['name']);
        self::assertSame('en', $response->cookies[0]['value']);
        self::assertTrue($response->cookies[0]['options']['httponly']);

        $html = $this->request('GET', '/login', cookies: ['nordvarr_lang' => 'en'])->body;
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Sign in', $html);
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $this->login();
        self::assertSame(404, $this->request('GET', '/neexistuje')->status);
        $response = $this->request('GET', '/logout');
        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->headers['Allow']);
    }

    public function testPendingMigrationsRunForAdminWithBackup(): void
    {
        file_put_contents($this->app . '/migrations/9001_test_aktualizace.sql', 'CREATE TABLE aktualizace_test (id TEXT);');
        // Anonymní požadavek migraci nespustí.
        $this->request('GET', '/login');
        self::assertSame([], glob($this->tmp . '/nordvarr-data/backups/*.sqlite'));

        $this->login();
        $home = $this->request('GET', '/');
        self::assertStringContainsString('Databáze byla aktualizována', $home->body);

        $backups = glob($this->tmp . '/nordvarr-data/backups/*.sqlite') ?: [];
        self::assertCount(1, $backups);
        $tables = $this->pdo()->query("SELECT name FROM sqlite_master WHERE name = 'aktualizace_test'")->fetchAll();
        self::assertCount(1, $tables);
        $backupTables = Database::connectSqlite($backups[0])->query("SELECT name FROM sqlite_master WHERE name = 'aktualizace_test'")->fetchAll();
        self::assertCount(0, $backupTables);
    }

    private function pdo(): \PDO
    {
        return Database::connectSqlite($this->tmp . '/nordvarr-data/nordvarr.sqlite');
    }
}
