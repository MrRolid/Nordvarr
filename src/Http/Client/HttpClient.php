<?php

declare(strict_types=1);

namespace Nordvarr\Http\Client;

/**
 * Odchozí HTTP požadavky. Rozhraní kvůli vyměnitelnosti v testech
 * (RDAP z fixture JSON, Telegram a webhook proti lokálnímu mocku).
 */
interface HttpClient
{
    public function get(string $url, int $timeoutS): HttpResponse;

    /**
     * @param list<string> $headers celé řádky hlaviček, např. "Content-Type: application/json"
     */
    public function post(string $url, string $body, array $headers, int $timeoutS): HttpResponse;
}
