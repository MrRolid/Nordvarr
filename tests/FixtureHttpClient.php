<?php

declare(strict_types=1);

namespace Nordvarr\Tests;

use Nordvarr\Http\Client\HttpClient;
use Nordvarr\Http\Client\HttpResponse;

/**
 * HTTP klient pro testy: odpovědi z mapy URL -> odpověď, žádná síť.
 */
final class FixtureHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $requests = [];

    /**
     * @param array<string, HttpResponse> $responses
     */
    public function __construct(private array $responses = [])
    {
    }

    public function set(string $url, HttpResponse $response): void
    {
        $this->responses[$url] = $response;
    }

    public function get(string $url, int $timeoutS): HttpResponse
    {
        $this->requests[] = $url;

        return $this->responses[$url] ?? new HttpResponse(0, '', 'Could not resolve host', true);
    }

    public static function file(string $name, array $replace = [], int $status = 200): HttpResponse
    {
        $body = (string) file_get_contents(__DIR__ . '/fixtures/files/' . $name);

        return new HttpResponse($status, strtr($body, $replace));
    }
}
