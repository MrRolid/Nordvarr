<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

/**
 * Zpráva k odeslání: předmět a text pro lidi, payload pro webhook.
 */
final class Notification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $text,
        public readonly array $payload,
    ) {
    }
}
