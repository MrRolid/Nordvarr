<?php

declare(strict_types=1);

namespace Nordvarr\Security;

use Nordvarr\Http\Session;

/**
 * CSRF token svázaný se session. Kontroluje se u každého POST z UI.
 */
final class Csrf
{
    public const FIELD = '_csrf';
    private const KEY = 'csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function isValid(mixed $submitted): bool
    {
        $token = $this->session->get(self::KEY);

        return is_string($token) && $token !== '' && is_string($submitted) && hash_equals($token, $submitted);
    }

    /**
     * Po přihlášení se token vymění, aby nešel přenést z anonymní session.
     */
    public function rotate(): void
    {
        $this->session->remove(self::KEY);
    }
}
