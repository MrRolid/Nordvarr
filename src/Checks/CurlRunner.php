<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use CurlHandle;
use Nordvarr\Time;

/**
 * Paralelní běh kontrol http, tcp a tls_expiry přes curl_multi.
 *
 * tcp a tls_expiry používají CURLOPT_CONNECT_ONLY: curl jen naváže spojení
 * (u tls_expiry včetně TLS handshake) a nic neposílá. Proxy z prostředí se
 * nepoužívá, kontroly jdou vždy přímo na cíl.
 */
final class CurlRunner
{
    public const MAX_PARALLEL = 20;
    public const MAX_BODY = 1048576;

    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /**
     * @param array<int, mixed> $extraOptions doplňkové volby curl (testy: vlastní CA)
     */
    public function __construct(
        private readonly string $userAgent = 'Nordvarr',
        private readonly array $extraOptions = [],
    ) {
    }

    /**
     * @param list<array<string, mixed>> $checks řádky kontrol (id, type, config, timeout_s)
     * @param callable(string, CheckResult): void $onResult volá se pro každý dokončený výsledek
     */
    public function run(array $checks, callable $onResult, float $deadline = INF): void
    {
        $queue = $checks;
        $multi = curl_multi_init();
        /** @var array<int, array{check: array<string, mixed>, started: string, body: string, truncated: bool}> $active */
        $active = [];

        try {
            while ($queue !== [] || $active !== []) {
                while ($queue !== [] && count($active) < self::MAX_PARALLEL && microtime(true) < $deadline) {
                    $check = array_shift($queue);
                    $job = ['check' => $check, 'started' => Time::toDb(Time::now()), 'body' => '', 'truncated' => false];
                    $handle = $this->handle($check, $job);
                    curl_multi_add_handle($multi, $handle);
                    $active[spl_object_id($handle)] = &$job;
                    unset($job);
                }
                if ($active === []) {
                    break;
                }
                curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 0.5);
                }
                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $id = spl_object_id($handle);
                    $job = $active[$id];
                    unset($active[$id]);
                    $result = $this->evaluate($job, $handle, (int) $info['result']);
                    curl_multi_remove_handle($multi, $handle);
                    $onResult((string) $job['check']['id'], $result);
                }
            }
        } finally {
            curl_multi_close($multi);
        }
    }

    /**
     * @param array<string, mixed> $check
     * @param array<string, mixed> $job
     */
    private function handle(array $check, array &$job): CurlHandle
    {
        $config = $check['config'];
        $timeout = max(1, (int) $check['timeout_s']);
        $type = (string) $check['type'];
        $url = match ($type) {
            'http' => (string) $config['url'],
            'tcp' => 'http://' . self::hostForUrl((string) $config['host']) . ':' . (int) $config['port'] . '/',
            'tls_expiry' => 'https://' . self::hostForUrl((string) $config['host']) . ':' . (int) $config['port'] . '/',
            default => '',
        };
        $handle = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT_MS => $timeout * 1000,
            CURLOPT_CONNECTTIMEOUT_MS => $timeout * 1000,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS_STR => 'http,https',
        ];
        if ($type === 'http') {
            $options += [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_ENCODING => '',
                CURLOPT_NOBODY => ($config['method'] ?? 'GET') === 'HEAD',
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $h, string $chunk) use (&$job): int {
                    $room = self::MAX_BODY - strlen($job['body']);
                    if (strlen($chunk) > $room) {
                        // Odpověď nad 1 MB se dál nečte; vyhodnotí se dosud přijatá část.
                        $job['body'] .= substr($chunk, 0, max(0, $room));
                        $job['truncated'] = true;

                        return 0;
                    }
                    $job['body'] .= $chunk;

                    return strlen($chunk);
                },
            ];
        } else {
            $options[CURLOPT_CONNECT_ONLY] = true;
            if ($type === 'tls_expiry') {
                // Ověřuje se jen platnost certifikátu v čase, ne důvěra; proto bez ověření řetězce.
                $options += [CURLOPT_CERTINFO => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
            }
        }
        curl_setopt_array($handle, $this->extraOptions + $options);

        return $handle;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function evaluate(array $job, CurlHandle $handle, int $errno): CheckResult
    {
        $check = $job['check'];
        $type = (string) $check['type'];
        $started = (string) $job['started'];
        $finished = Time::toDb(Time::now());
        $latency = (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000);

        if ($errno === CURLE_WRITE_ERROR && $job['truncated']) {
            $errno = 0;
        }
        if ($errno !== 0) {
            [$reason, $detail] = $this->errorReason($handle, $errno);
            // U expirace certifikátu nejde při nedostupnosti stav zjistit.
            $status = $type === 'tls_expiry' ? Status::UNKNOWN : Status::UNHEALTHY;

            return new CheckResult($status, $reason, $detail, null, $started, $finished);
        }

        return match ($type) {
            'http' => $this->evaluateHttp($check['config'], $handle, (string) $job['body'], $latency, $started, $finished),
            'tls_expiry' => $this->evaluateTls($check['config'], $handle, $latency, $started, $finished),
            default => CheckResult::healthy($started, $finished, $latency),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function evaluateHttp(array $config, CurlHandle $handle, string $body, int $latency, string $started, string $finished): CheckResult
    {
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $expected = (string) ($config['expected_status'] ?? '200-399');
        if (!CheckTypes::statusMatches($code, $expected)) {
            $reason = $code >= 500 ? Reason::HTTP_5XX : ($code >= 400 ? Reason::HTTP_4XX : Reason::UNKNOWN_ERROR);

            return new CheckResult(Status::UNHEALTHY, $reason, "HTTP $code (očekáváno $expected)", $latency, $started, $finished);
        }
        $contains = (string) ($config['contains'] ?? '');
        if ($contains !== '' && !str_contains($body, $contains)) {
            return new CheckResult(Status::UNHEALTHY, Reason::CONTENT_MISMATCH, "HTTP $code, odpověď neobsahuje očekávaný text", $latency, $started, $finished);
        }
        $limit = (int) ($config['latency_degraded_ms'] ?? 0);
        if ($limit > 0 && $latency > $limit) {
            return new CheckResult(Status::DEGRADED, Reason::LATENCY_DEGRADED, "HTTP $code za $latency ms (limit $limit ms)", $latency, $started, $finished);
        }

        return CheckResult::healthy($started, $finished, $latency, "HTTP $code");
    }

    /**
     * @param array<string, mixed> $config
     */
    private function evaluateTls(array $config, CurlHandle $handle, int $latency, string $started, string $finished): CheckResult
    {
        $info = curl_getinfo($handle, CURLINFO_CERTINFO);
        $expiry = null;
        if (is_array($info) && isset($info[0]) && is_array($info[0])) {
            $parsed = isset($info[0]['Cert']) ? openssl_x509_parse((string) $info[0]['Cert']) : false;
            if (is_array($parsed) && isset($parsed['validTo_time_t'])) {
                $expiry = (int) $parsed['validTo_time_t'];
            } elseif (isset($info[0]['Expire date'])) {
                $expiry = strtotime((string) $info[0]['Expire date']) ?: null;
            }
        }
        if ($expiry === null) {
            return new CheckResult(Status::UNKNOWN, Reason::TLS_ERROR, 'Certifikát se nepodařilo přečíst', $latency, $started, $finished);
        }

        return self::expiryResult(
            $expiry,
            (int) ($config['degraded_days'] ?? 21),
            (int) ($config['unhealthy_days'] ?? 7),
            Reason::CERT_EXPIRING,
            Reason::CERT_EXPIRED,
            'Certifikát',
            $latency,
            $started,
            $finished,
        );
    }

    /**
     * Společné vyhodnocení expirace certifikátu i domény.
     */
    public static function expiryResult(int $expiry, int $degradedDays, int $unhealthyDays, string $expiringReason, string $expiredReason, string $what, ?int $latency, string $started, string $finished): CheckResult
    {
        $expiresAt = Time::toDb((new \DateTimeImmutable('@' . $expiry)));
        $now = Time::fromDb($finished)->getTimestamp();
        $days = (int) floor(($expiry - $now) / 86400);
        $date = gmdate('Y-m-d', $expiry);
        if ($expiry <= $now) {
            return new CheckResult(Status::UNHEALTHY, $expiredReason, "$what vypršel $date", $latency, $started, $finished, $expiresAt);
        }
        if ($days < $unhealthyDays) {
            return new CheckResult(Status::UNHEALTHY, $expiringReason, "$what vyprší $date (za $days dní)", $latency, $started, $finished, $expiresAt);
        }
        if ($days < $degradedDays) {
            return new CheckResult(Status::DEGRADED, $expiringReason, "$what vyprší $date (za $days dní)", $latency, $started, $finished, $expiresAt);
        }

        return CheckResult::healthy($started, $finished, $latency, "$what platí do $date", $expiresAt);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function errorReason(CurlHandle $handle, int $errno): array
    {
        $message = curl_error($handle) ?: curl_strerror($errno) ?: 'chyba ' . $errno;
        $reason = match (true) {
            $errno === CURLE_COULDNT_RESOLVE_HOST => Reason::DNS_FAILURE,
            $errno === CURLE_COULDNT_CONNECT => Reason::TCP_REFUSED,
            $errno === CURLE_OPERATION_TIMEOUTED => curl_getinfo($handle, CURLINFO_CONNECT_TIME) > 0 ? Reason::CHECK_TIMEOUT : Reason::TCP_TIMEOUT,
            in_array($errno, self::TLS_ERRORS, true) => Reason::TLS_ERROR,
            $errno === CURLE_TOO_MANY_REDIRECTS => Reason::UNKNOWN_ERROR,
            default => Reason::UNKNOWN_ERROR,
        };

        return [$reason, $message];
    }

    private static function hostForUrl(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    }
}
