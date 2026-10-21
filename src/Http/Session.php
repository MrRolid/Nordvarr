<?php

declare(strict_types=1);

namespace Nordvarr\Http;

/**
 * Tenká vrstva nad session. V produkci pracuje s nativní PHP session,
 * v testech (native = false) jen s polem v paměti.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $memory = [];

    private function __construct(private readonly bool $native)
    {
    }

    /**
     * Spustí nativní session s bezpečným nastavením cookie.
     */
    public static function startNative(string $savePath, bool $secure): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if ($savePath !== '') {
                if (!is_dir($savePath)) {
                    mkdir($savePath, 0700, true);
                }
                session_save_path($savePath);
            }
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', '43200');
            session_name('NORDVARRSID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        return new self(true);
    }

    public static function inMemory(): self
    {
        return new self(false);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->native ? ($_SESSION[$key] ?? $default) : ($this->memory[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->native) {
            $_SESSION[$key] = $value;
        } else {
            $this->memory[$key] = $value;
        }
    }

    public function remove(string $key): void
    {
        if ($this->native) {
            unset($_SESSION[$key]);
        } else {
            unset($this->memory[$key]);
        }
    }

    /**
     * Vytáhne a smaže jednorázovou hodnotu (flash zprávu).
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * Nové id session (po přihlášení a odhlášení), obsah se volitelně smaže.
     */
    public function regenerate(bool $clear = false): void
    {
        if ($clear) {
            if ($this->native) {
                $_SESSION = [];
            } else {
                $this->memory = [];
            }
        }
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        } elseif (!$this->native) {
            $this->memory['_regenerated'] = ($this->memory['_regenerated'] ?? 0) + 1;
        }
    }
}
