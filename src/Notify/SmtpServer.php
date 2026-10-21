<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

/**
 * Parametry jednoho SMTP serveru.
 */
final class SmtpServer
{
    public const SECURITY = ['ssl', 'starttls', 'none'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $security = 'starttls',
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly bool $verifyTls = true,
    ) {
    }

    public function label(): string
    {
        return $this->host . ':' . $this->port;
    }
}
