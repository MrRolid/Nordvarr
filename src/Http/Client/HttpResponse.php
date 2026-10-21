<?php

declare(strict_types=1);

namespace Nordvarr\Http\Client;

final class HttpResponse
{
    /**
     * @param int $status HTTP stav, 0 při chybě spojení
     * @param string $error popis chyby spojení
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $error = '',
        public readonly bool $dnsFailure = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
