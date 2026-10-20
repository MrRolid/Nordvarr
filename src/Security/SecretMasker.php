<?php

declare(strict_types=1);

namespace Nordvarr\Security;

/**
 * Maskování tajných údajů při exportu. Tajné údaje do inventáře nepatří,
 * ale export je nesmí propustit ani omylem.
 *
 * Export inventáře (zálohu) maskuje podle klíče atributu, přihlašovací údaje v URL
 * a hesla v DSN. Export pro LLM (maskStrict) navíc maskuje dvojice "klíč: hodnota"
 * s tajným klíčem v textu, bloky privátních klíčů a dlouhé řetězce s vysokou entropií.
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

    /**
     * Přísné maskování pro export, který opouští aplikaci (export pro LLM).
     */
    public static function maskStrict(string $text): string
    {
        // Bloky PEM s privátním klíčem celé.
        $text = preg_replace('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s', self::MASK, $text) ?? $text;
        $text = self::maskText($text);
        // "Authorization: Bearer xyz" a "token: xyz", "api_key = xyz", "heslo: xyz" v libovolném textu.
        $text = preg_replace('/\b(bearer)\s+[^\s"\'<>]{8,}/i', '$1 ' . self::MASK, $text) ?? $text;
        $text = preg_replace(
            '/\b([a-z0-9_.\-]*(?:pass|pwd|secret|token|api[_\-]?key|private[_\-]?key|access[_\-]?key|heslo)[a-z0-9_.\-]*)(\s*[:=]\s*)("[^"\n]*"|\'[^\'\n]*\'|[^\s"\'<>,;]+)/iu',
            '$1$2' . self::MASK,
            $text
        ) ?? $text;

        return preg_replace_callback(
            '/[A-Za-z0-9+\/=_\-]{20,}/',
            static fn (array $m): string => self::looksRandom($m[0]) ? self::MASK : $m[0],
            $text
        ) ?? $text;
    }

    public static function maskAttributeStrict(string $key, string $value): string
    {
        return self::isSecretKey($key) ? self::MASK : self::maskStrict($value);
    }

    /**
     * Vypadá řetězec jako náhodný klíč nebo token? UUID a hash commitu (do 40 hex znaků)
     * se nemaskují, delší hex řetězce ano (typicky 64 znaků tajemství nebo HMAC klíč).
     */
    public static function looksRandom(string $s): bool
    {
        $len = strlen($s);
        if ($len < 20 || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1) {
            return false;
        }
        if (preg_match('/^[0-9a-f]+$/i', $s) === 1) {
            return $len > 40;
        }
        // Kombinace malých a velkých písmen a číslic; čistě textové identifikátory a cesty projdou.
        $classes = (int) preg_match('/[a-z]/', $s) + (int) preg_match('/[A-Z]/', $s) + (int) preg_match('/[0-9]/', $s);
        if ($classes < 3) {
            return false;
        }

        return self::entropy($s) >= 3.5;
    }

    /**
     * Shannonova entropie v bitech na znak.
     */
    public static function entropy(string $s): float
    {
        $len = strlen($s);
        if ($len === 0) {
            return 0.0;
        }
        $h = 0.0;
        foreach (count_chars($s, 1) as $count) {
            $p = $count / $len;
            $h -= $p * log($p, 2);
        }

        return $h;
    }
}
