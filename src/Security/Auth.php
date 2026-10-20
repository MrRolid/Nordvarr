<?php

declare(strict_types=1);

namespace Nordvarr\Security;

use DateTimeImmutable;
use Nordvarr\Http\Session;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\UserRepository;

/**
 * Přihlášení, odhlášení a aktuální uživatel podle session.
 */
final class Auth
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const THROTTLED = 'throttled';

    /** @var array<string, mixed>|null|false false = ještě nenačteno */
    private array|null|false $user = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogRepository $audit,
        private readonly Session $session,
    ) {
    }

    /**
     * @return self::OK|self::INVALID|self::THROTTLED
     */
    public function attempt(string $email, string $password, string $ip, DateTimeImmutable $now): string
    {
        $email = UserRepository::normalizeEmail($email);
        if ($this->throttle->retryAfter($email, $ip, $now) > 0) {
            $this->audit->log('login.throttled', null, $ip, ['email' => $email]);

            return self::THROTTLED;
        }

        $user = $email === '' ? null : $this->users->findByEmail($email);
        // Ověření proti náhradnímu hashi, aby neexistující účet nešel poznat podle času odpovědi.
        $hash = $user['password_hash'] ?? Passwords::dummyHash();
        $valid = Passwords::verify($password, (string) $hash) && $user !== null;

        if (!$valid) {
            $this->throttle->record($email, $ip, false, $now);
            $this->audit->log('login.failed', $user['id'] ?? null, $ip, ['email' => $email]);

            return self::INVALID;
        }

        if (Passwords::needsRehash((string) $user['password_hash'])) {
            $this->users->updatePassword((string) $user['id'], Passwords::hash($password), $now);
            $user = $this->users->findById((string) $user['id']) ?? $user;
        }

        $this->session->regenerate(true);
        $this->session->set('user_id', $user['id']);
        $this->session->set('session_version', (int) $user['session_version']);
        $this->users->touchLogin((string) $user['id'], $now);
        $this->throttle->record($email, $ip, true, $now);
        $this->audit->log('login.success', (string) $user['id'], $ip);
        $this->user = $user;

        return self::OK;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->user !== false) {
            return $this->user;
        }
        $this->user = null;
        $id = $this->session->get('user_id');
        if (!is_string($id)) {
            return null;
        }
        $user = $this->users->findById($id);
        if ($user === null || (int) $user['session_version'] !== (int) $this->session->get('session_version')) {
            // Účet zmizel nebo se změnilo heslo: session už neplatí.
            $this->session->regenerate(true);

            return null;
        }
        $this->user = $user;

        return $user;
    }

    public function logout(string $ip): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->audit->log('logout', (string) $user['id'], $ip);
        }
        $this->session->regenerate(true);
        $this->user = null;
    }
}
