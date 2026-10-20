<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Výsledek jednoho běhu kontroly. Časy jsou UTC v ISO 8601.
 */
final class CheckResult
{
    public const MAX_DETAIL = 300;

    public readonly string $detail;

    public function __construct(
        public readonly string $status,
        public readonly ?string $reasonCode,
        string $detail,
        public readonly ?int $latencyMs,
        public readonly string $startedAt,
        public readonly string $finishedAt,
        public readonly ?string $expiresAt = null,
    ) {
        $detail = trim((string) preg_replace('/\s+/', ' ', $detail));
        $this->detail = mb_strlen($detail) > self::MAX_DETAIL ? mb_substr($detail, 0, self::MAX_DETAIL - 3) . '...' : $detail;
    }

    public static function healthy(string $startedAt, string $finishedAt, ?int $latencyMs = null, string $detail = '', ?string $expiresAt = null): self
    {
        return new self(Status::HEALTHY, null, $detail, $latencyMs, $startedAt, $finishedAt, $expiresAt);
    }
}
