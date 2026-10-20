<?php

declare(strict_types=1);

namespace Nordvarr;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Práce s časem: v databázi vždy UTC v ISO 8601, v UI časová zóna z nastavení.
 */
final class Time
{
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function toDb(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    public static function fromDb(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
