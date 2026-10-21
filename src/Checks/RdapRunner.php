<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use Nordvarr\Http\Client\HttpClient;
use Nordvarr\Repository\HttpCacheRepository;
use Nordvarr\Time;

/**
 * Kontrola expirace domény přes RDAP. Server pro TLD se zjistí z bootstrapu IANA,
 * který se drží v cache 7 dní.
 */
final class RdapRunner
{
    public const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';
    private const CACHE_DAYS = 7;

    public function __construct(
        private readonly HttpClient $http,
        private readonly HttpCacheRepository $cache,
        private readonly string $bootstrapUrl = self::BOOTSTRAP_URL,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param callable(string, CheckResult): void $onResult
     */
    public function run(array $checks, callable $onResult, float $deadline = INF): void
    {
        foreach ($checks as $check) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $onResult((string) $check['id'], $this->check($check));
        }
    }

    /**
     * @param array<string, mixed> $check
     */
    public function check(array $check): CheckResult
    {
        $started = Time::toDb(Time::now());
        $config = $check['config'];
        $domain = (string) $config['domain'];
        $timeout = max(1, (int) $check['timeout_s']);
        $unknown = static fn (string $reason, string $detail): CheckResult => new CheckResult(
            Status::UNKNOWN, $reason, $detail, null, $started, Time::toDb(Time::now())
        );

        $base = $this->serverFor($domain, $timeout, $error);
        if ($base === null) {
            return $unknown(Reason::UNKNOWN_ERROR, $error);
        }
        $start = microtime(true);
        $response = $this->http->get(rtrim($base, '/') . '/domain/' . rawurlencode($domain), $timeout);
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($response->status === 0) {
            return $unknown($response->dnsFailure ? Reason::DNS_FAILURE : Reason::UNKNOWN_ERROR, 'RDAP nedostupný: ' . $response->error);
        }
        if ($response->status === 404) {
            return $unknown(Reason::UNKNOWN_ERROR, 'Doména v RDAP nenalezena');
        }
        if (!$response->ok()) {
            return $unknown(Reason::UNKNOWN_ERROR, 'RDAP vrátil HTTP ' . $response->status);
        }
        $data = json_decode($response->body, true);
        $expiry = null;
        foreach (is_array($data) && is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
            if (is_array($event) && ($event['eventAction'] ?? null) === 'expiration' && is_string($event['eventDate'] ?? null)) {
                $expiry = strtotime($event['eventDate']) ?: null;
                break;
            }
        }
        if ($expiry === null) {
            return $unknown(Reason::RDAP_NO_EXPIRY, 'RDAP nevrátil datum expirace');
        }

        return CurlRunner::expiryResult(
            $expiry,
            (int) ($config['degraded_days'] ?? 30),
            (int) ($config['unhealthy_days'] ?? 7),
            Reason::DOMAIN_EXPIRING,
            Reason::DOMAIN_EXPIRED,
            'Doména',
            $latency,
            $started,
            Time::toDb(Time::now()),
        );
    }

    /**
     * Základní URL RDAP serveru pro TLD domény podle bootstrapu IANA.
     */
    private function serverFor(string $domain, int $timeout, ?string &$error): ?string
    {
        $error = '';
        $notBefore = Time::toDb(Time::now()->modify('-' . self::CACHE_DAYS . ' days'));
        $body = $this->cache->get($this->bootstrapUrl, $notBefore);
        if ($body === null) {
            $response = $this->http->get($this->bootstrapUrl, $timeout);
            if (!$response->ok() || !is_array(json_decode($response->body, true))) {
                $error = 'Bootstrap RDAP nelze stáhnout: ' . ($response->error !== '' ? $response->error : 'HTTP ' . $response->status);

                return null;
            }
            $body = $response->body;
            $this->cache->put($this->bootstrapUrl, $body, Time::toDb(Time::now()));
        }
        $bootstrap = json_decode($body, true);
        $labels = explode('.', $domain);
        // Nejdelší shoda přípony (např. co.uk před uk).
        for ($i = 1; $i < count($labels); $i++) {
            $suffix = implode('.', array_slice($labels, $i));
            foreach (is_array($bootstrap['services'] ?? null) ? $bootstrap['services'] : [] as $service) {
                if (!is_array($service) || !is_array($service[0] ?? null) || !is_array($service[1] ?? null)) {
                    continue;
                }
                if (in_array($suffix, array_map('strtolower', $service[0]), true)) {
                    foreach ($service[1] as $url) {
                        if (is_string($url) && str_starts_with($url, 'https://')) {
                            return $url;
                        }
                    }
                    foreach ($service[1] as $url) {
                        if (is_string($url) && str_starts_with($url, 'http://')) {
                            return $url;
                        }
                    }
                }
            }
        }
        $error = 'Pro doménu není v bootstrapu IANA žádný RDAP server';

        return null;
    }
}
