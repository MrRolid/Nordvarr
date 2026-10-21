<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Stavy kontrol a objektů a jejich závažnost.
 */
final class Status
{
    public const HEALTHY = 'healthy';
    public const DEGRADED = 'degraded';
    public const UNHEALTHY = 'unhealthy';
    public const UNKNOWN = 'unknown';
    public const PAUSED = 'paused';

    /** Objekt bez kontrol nemá stav. */
    public const NONE = 'none';

    public const ALL = [self::HEALTHY, self::DEGRADED, self::UNHEALTHY, self::UNKNOWN, self::PAUSED];

    private const SEVERITY = [
        self::NONE => -1,
        self::PAUSED => 0,
        self::HEALTHY => 1,
        self::UNKNOWN => 2,
        self::DEGRADED => 3,
        self::UNHEALTHY => 4,
    ];

    public static function severity(string $status): int
    {
        return self::SEVERITY[$status] ?? 2;
    }

    /**
     * Nejhorší ze stavů; prázdný seznam = objekt bez kontrol.
     *
     * @param list<string> $statuses
     */
    public static function worst(array $statuses): string
    {
        $worst = self::NONE;
        foreach ($statuses as $status) {
            if (self::severity($status) > self::severity($worst)) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
