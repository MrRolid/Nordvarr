<?php

declare(strict_types=1);

namespace Nordvarr;

/**
 * Generátor identifikátorů UUIDv7 (RFC 9562) v textové podobě.
 */
final class Uuid
{
    public static function v7(?int $unixMs = null): string
    {
        $ms = $unixMs ?? (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $ms);
        $bytes = substr($bytes, 2, 6) . random_bytes(10);
        // Verze 7 v horních 4 bitech 7. bajtu, varianta 10xx v 9. bajtu.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }
}
