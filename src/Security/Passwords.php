<?php

declare(strict_types=1);

namespace Nordvarr\Security;

/**
 * Hashování hesel: Argon2id, pokud ho PHP podporuje, jinak bcrypt.
 */
final class Passwords
{
    public const MIN_LENGTH = 10;

    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Hash bez skutečného hesla pro vyrovnání času odpovědi u neexistujícího účtu.
     */
    public static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= self::hash(bin2hex(random_bytes(16)));
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }
}
