<?php

declare(strict_types=1);

namespace Nordvarr\Http\Client;

/**
 * Odchozí HTTP GET. Rozhraní kvůli vyměnitelnosti v testech (RDAP z fixture JSON).
 */
interface HttpClient
{
    public function get(string $url, int $timeoutS): HttpResponse;
}
