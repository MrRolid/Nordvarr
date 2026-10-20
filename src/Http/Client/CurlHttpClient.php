<?php

declare(strict_types=1);

namespace Nordvarr\Http\Client;

use CurlHandle;

/**
 * HTTP klient nad curl s limitem velikosti odpovědi a přesměrování.
 */
final class CurlHttpClient implements HttpClient
{
    private const MAX_BODY = 2097152;

    public function __construct(private readonly string $userAgent = 'Nordvarr')
    {
    }

    public function get(string $url, int $timeoutS): HttpResponse
    {
        return $this->request($url, $timeoutS, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json, application/json'],
        ]);
    }

    public function post(string $url, string $body, array $headers, int $timeoutS): HttpResponse
    {
        // POST nesleduje přesměrování, aby se tělo s podpisem neposílalo jinam.
        return $this->request($url, $timeoutS, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
    }

    /**
     * @param array<int, mixed> $options
     */
    private function request(string $url, int $timeoutS, array $options): HttpResponse
    {
        $body = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, $options + [
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_TIMEOUT => max(1, $timeoutS),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $h, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $errno === 0 ? '' : (curl_error($handle) ?: curl_strerror($errno) ?: 'chyba ' . $errno);

        return new HttpResponse($errno === 0 ? $status : 0, $body, (string) $error, $errno === CURLE_COULDNT_RESOLVE_HOST);
    }
}
