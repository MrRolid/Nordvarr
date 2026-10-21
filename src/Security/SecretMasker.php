<?php

declare(strict_types=1);

namespace Nordvarr\Security;

/**
 * Maskování tajných údajů při exportu. Tajné údaje do inventáře nepatří,
 * ale export je nesmí propustit ani omylem.
 *
 * Fáze F1 maskuje podle klíče atributu, přihlašovací údaje v URL a hesla v DSN.
 * Maskování řetězců s vysokou entropií přidá fáze F5 (export pro LLM).
 */
final class SecretMasker
{
    public const MASK = '***';
    private const SECRET_KEY_PARTS = ['pass', 'pwd', 'secret', 'token', 'key', 'private'];

    public static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zamaskuje heslo v URL (uzivatel:heslo@) a v DSN (password=..., pwd=...).
     */
    public static function maskText(string $text): string
    {
        $text = preg_replace('#([a-z][a-z0-9+.\-]*://[^\s:/@]+):[^\s@/]+@#i', '$1:' . self::MASK . '@', $text) ?? $text;

        return preg_replace('/\b(password|passwd|pwd)=([^;\s&]+)/i', '$1=' . self::MASK, $text) ?? $text;
    }

    public static function maskAttribute(string $key, string $value): string
    {
        return self::isSecretKey($key) ? self::MASK : self::maskText($value);
    }
}
