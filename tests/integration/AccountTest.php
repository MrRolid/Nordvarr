<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Http\Session;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\LoginAttemptRepository;
use Nordvarr\Repository\UserRepository;
use Nordvarr\Security\LoginThrottle;
use Nordvarr\Security\Passwords;
use Nordvarr\Time;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

/**
 * Účet admina: změna hesla v nastavení a obnova zapomenutého hesla z CLI.
 */
final class AccountTest extends TestCase
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

    private function hash(): string
    {
        return (string) (new UserRepository($this->pdo()))->findByEmail(self::ADMIN_EMAIL)['password_hash'];
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        return array_column((new AuditLogRepository($this->pdo()))->latest(), 'action');
    }

    public function testPasswordChangeValidatesAndSignsOutOtherSessions(): void
    {
        // Druhé přihlášení (jiný prohlížeč).
        $this->loginAdmin();
        $other = $this->session;
        $this->session = Session::inMemory();
        $this->loginAdmin();

        $page = $this->ui('GET', '/settings')->body;
        self::assertStringContainsString('data-test="password-form"', $page);
        self::assertStringContainsString('Přihlášený účet: admin@example.com', $page);

        $wrong = $this->post('/settings/password', ['current_password' => 'spatne-heslo', 'new_password' => 'nove-heslo-123', 'new_password_confirm' => 'nove-heslo-123']);
        self::assertSame(422, $wrong->status);
        self::assertStringContainsString('Současné heslo nesouhlasí.', $wrong->body);
        self::assertContains('user.password_failed', $this->auditActions());

        $short = $this->post('/settings/password', ['current_password' => self::ADMIN_PASSWORD, 'new_password' => 'kratke', 'new_password_confirm' => 'kratke']);
        self::assertSame(422, $short->status);
        self::assertStringContainsString('Nové heslo musí mít aspoň 10 znaků.', $short->body);

        $mismatch = $this->post('/settings/password', ['current_password' => self::ADMIN_PASSWORD, 'new_password' => 'nove-heslo-123', 'new_password_confirm' => 'nove-heslo-124']);
        self::assertSame(422, $mismatch->status);
        self::assertStringContainsString('Nová hesla se neshodují.', $mismatch->body);
        self::assertTrue(Passwords::verify(self::ADMIN_PASSWORD, $this->hash()), 'Neúspěšné pokusy heslo nemění.');

        $ok = $this->post('/settings/password', ['current_password' => self::ADMIN_PASSWORD, 'new_password' => 'nove-heslo-123', 'new_password_confirm' => 'nove-heslo-123']);
        self::assertSame(303, $ok->status);
        self::assertStringEndsWith('/settings#account', $ok->headers['Location']);
        self::assertTrue(Passwords::verify('nove-heslo-123', $this->hash()));
        self::assertContains('user.password_change', $this->auditActions());
        self::assertStringNotContainsString('nove-heslo-123', json_encode((new AuditLogRepository($this->pdo()))->latest(), JSON_THROW_ON_ERROR));

        // Aktuální session zůstává, druhá je odhlášená.
        self::assertSame(200, $this->ui('GET', '/settings')->status);
        $current = $this->session;
        $this->session = $other;
        self::assertStringEndsWith('/login', $this->ui('GET', '/settings')->headers['Location'] ?? '', 'Jiná session po změně hesla neplatí.');
        $this->session = $current;

        // Přihlášení novým heslem funguje, starým ne.
        $this->session = Session::inMemory();
        self::assertSame(401, $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])->status);
        self::assertSame(303, $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => 'nove-heslo-123'])->status);
    }

    public function testWrongCurrentPasswordIsThrottled(): void
    {
        $this->loginAdmin();
        // Špatné současné heslo se počítá jako neúspěšné přihlášení.
        $this->post('/settings/password', ['current_password' => 'spatne-0', 'new_password' => 'nove-heslo-123', 'new_password_confirm' => 'nove-heslo-123']);
        $attempts = (new LoginAttemptRepository($this->pdo()))->failuresSinceLastSuccess('email', self::ADMIN_EMAIL, Time::now()->modify('-1 hour'));
        self::assertCount(1, $attempts);
        // Dalších 9 selhání přímo v tabulce (deterministicky, bez závislosti na rychlosti stroje): zámek na 15 minut.
        $throttle = new LoginThrottle(new LoginAttemptRepository($this->pdo()));
        for ($i = 0; $i < 9; $i++) {
            $throttle->record(self::ADMIN_EMAIL, '192.0.2.50', false, Time::now());
        }
        $blocked = $this->post('/settings/password', ['current_password' => self::ADMIN_PASSWORD, 'new_password' => 'nove-heslo-123', 'new_password_confirm' => 'nove-heslo-123']);
        self::assertSame(429, $blocked->status);
        self::assertTrue(Passwords::verify(self::ADMIN_PASSWORD, $this->hash()));
    }

    public function testCliResetPassword(): void
    {
        $this->loginAdmin();
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->app . '/bin/nordvarr.php') . ' reset-password ';
        exec($cmd . escapeshellarg('nikdo@example.com') . ' 2>&1', $out, $code);
        self::assertSame(1, $code);

        $out = [];
        exec($cmd . escapeshellarg('Admin@Example.com') . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertSame(1, preg_match('/^Nové heslo pro admin@example\.com: (\S{20})$/', $out[0], $m), $out[0]);
        self::assertTrue(Passwords::verify($m[1], $this->hash()));
        self::assertContains('user.password_reset', $this->auditActions());
        self::assertStringEndsWith('/login', $this->ui('GET', '/')->headers['Location'] ?? '', 'Stará přihlášení po obnově hesla neplatí.');
    }
}
