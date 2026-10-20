<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Typy kontrol, jejich výchozí hodnoty a ověření konfigurace.
 */
final class CheckTypes
{
    public const TYPES = ['http', 'tcp', 'ping', 'tls_expiry', 'domain_expiry', 'heartbeat'];

    /** Typy, které spouští curl paralelně. */
    public const CURL_TYPES = ['http', 'tcp', 'tls_expiry'];

    /** Doménu stačí kontrolovat jednou denně. */
    public const DOMAIN_INTERVAL = 86400;

    public const MAX_TIMEOUT = 30;

    /**
     * Výchozí společná nastavení podle typu.
     *
     * @return array{interval_s: int, timeout_s: int, failure_threshold: int, notify_on_degraded: bool}
     */
    public static function defaults(string $type): array
    {
        return match ($type) {
            'tls_expiry' => ['interval_s' => 21600, 'timeout_s' => 10, 'failure_threshold' => 1, 'notify_on_degraded' => true],
            'domain_expiry' => ['interval_s' => self::DOMAIN_INTERVAL, 'timeout_s' => 15, 'failure_threshold' => 1, 'notify_on_degraded' => true],
            'heartbeat' => ['interval_s' => 3600, 'timeout_s' => 10, 'failure_threshold' => 1, 'notify_on_degraded' => false],
            default => ['interval_s' => 60, 'timeout_s' => 10, 'failure_threshold' => 2, 'notify_on_degraded' => false],
        };
    }

    /**
     * Výchozí konfigurace podle typu (pro formulář).
     *
     * @return array<string, string|int>
     */
    public static function defaultConfig(string $type): array
    {
        return match ($type) {
            'http' => ['url' => '', 'method' => 'GET', 'expected_status' => '200-399', 'contains' => '', 'latency_degraded_ms' => 0],
            'tcp' => ['host' => '', 'port' => ''],
            'ping' => ['host' => ''],
            'tls_expiry' => ['host' => '', 'port' => 443, 'degraded_days' => 21, 'unhealthy_days' => 7],
            'domain_expiry' => ['domain' => '', 'degraded_days' => 30, 'unhealthy_days' => 7],
            'heartbeat' => ['grace_s' => 300],
            default => [],
        };
    }

    /**
     * Ověří a znormalizuje vstup (z formuláře nebo importu).
     *
     * @param array<string, mixed> $input společná pole + pole konfigurace daného typu
     * @return array{common: array{name: string, interval_s: int, timeout_s: int, failure_threshold: int, enabled: bool, notify_on_degraded: bool}, config: array<string, string|int>, errors: array<string, string>}
     */
    public static function validate(string $type, array $input): array
    {
        $errors = [];
        if (!in_array($type, self::TYPES, true)) {
            return ['common' => self::emptyCommon(), 'config' => [], 'errors' => ['type' => 'invalid_check_type']];
        }
        $defaults = self::defaults($type);

        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) > 100) {
            $errors['name'] = 'invalid_check_name';
        }
        $interval = self::int($input['interval_s'] ?? null, $defaults['interval_s']);
        if ($type === 'domain_expiry') {
            $interval = self::DOMAIN_INTERVAL;
        }
        if ($interval === null || $interval < 60 || $interval > 7 * 86400) {
            $errors['interval_s'] = 'invalid_interval';
        }
        $timeout = self::int($input['timeout_s'] ?? null, $defaults['timeout_s']);
        if ($timeout === null || $timeout < 1 || $timeout > self::MAX_TIMEOUT) {
            $errors['timeout_s'] = 'invalid_timeout';
        }
        $threshold = self::int($input['failure_threshold'] ?? null, $defaults['failure_threshold']);
        if ($threshold === null || $threshold < 1 || $threshold > 10) {
            $errors['failure_threshold'] = 'invalid_threshold';
        }

        $config = [];
        switch ($type) {
            case 'http':
                $url = trim((string) ($input['url'] ?? ''));
                $parts = parse_url($url);
                if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    || ($parts['host'] ?? '') === '' || mb_strlen($url) > 2000 || preg_match('/\s/', $url) === 1) {
                    $errors['url'] = 'invalid_url';
                }
                $method = strtoupper(trim((string) ($input['method'] ?? 'GET')));
                if (!in_array($method, ['GET', 'HEAD'], true)) {
                    $errors['method'] = 'invalid_method';
                }
                $expected = trim((string) ($input['expected_status'] ?? '')) ?: '200-399';
                if (self::parseStatusRanges($expected) === null) {
                    $errors['expected_status'] = 'invalid_expected_status';
                }
                $contains = (string) ($input['contains'] ?? '');
                if (mb_strlen($contains) > 500) {
                    $errors['contains'] = 'invalid_contains';
                } elseif ($contains !== '' && $method === 'HEAD') {
                    $errors['contains'] = 'contains_needs_get';
                }
                $latency = self::int($input['latency_degraded_ms'] ?? null, 0);
                if ($latency === null || $latency < 0 || $latency > 600000) {
                    $errors['latency_degraded_ms'] = 'invalid_latency';
                }
                $config = ['url' => $url, 'method' => $method, 'expected_status' => $expected, 'contains' => $contains, 'latency_degraded_ms' => (int) $latency];
                break;

            case 'tcp':
            case 'ping':
            case 'tls_expiry':
                $host = strtolower(trim((string) ($input['host'] ?? '')));
                if (!self::isValidHost($host)) {
                    $errors['host'] = 'invalid_host';
                }
                $config = ['host' => $host];
                if ($type !== 'ping') {
                    $port = self::int($input['port'] ?? null, $type === 'tls_expiry' ? 443 : null);
                    if ($port === null || $port < 1 || $port > 65535) {
                        $errors['port'] = 'invalid_port';
                    }
                    $config['port'] = (int) $port;
                }
                if ($type === 'tls_expiry') {
                    $config += self::days($input, 21, 7, $errors);
                }
                break;

            case 'domain_expiry':
                $domain = strtolower(rtrim(trim((string) ($input['domain'] ?? '')), '.'));
                if (!self::isValidHost($domain) || filter_var($domain, FILTER_VALIDATE_IP) !== false || !str_contains($domain, '.')) {
                    $errors['domain'] = 'invalid_domain';
                }
                $config = ['domain' => $domain] + self::days($input, 30, 7, $errors);
                break;

            case 'heartbeat':
                $grace = self::int($input['grace_s'] ?? null, 300);
                if ($grace === null || $grace < 0 || $grace > 86400) {
                    $errors['grace_s'] = 'invalid_grace';
                }
                $config = ['grace_s' => (int) $grace];
                break;
        }

        return [
            'common' => [
                'name' => $name,
                'interval_s' => (int) $interval,
                'timeout_s' => (int) $timeout,
                'failure_threshold' => (int) $threshold,
                'enabled' => self::bool($input['enabled'] ?? true),
                'notify_on_degraded' => self::bool($input['notify_on_degraded'] ?? $defaults['notify_on_degraded']),
            ],
            'config' => $config,
            'errors' => $errors,
        ];
    }

    /**
     * "200-299,301" -> [[200, 299], [301, 301]]; null při chybě.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    public static function parseStatusRanges(string $spec): ?array
    {
        $ranges = [];
        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^\s*(\d{3})\s*(?:-\s*(\d{3})\s*)?$/', $part, $m) !== 1) {
                return null;
            }
            $from = (int) $m[1];
            $to = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $from;
            if ($from < 100 || $to > 599 || $from > $to) {
                return null;
            }
            $ranges[] = [$from, $to];
        }

        return $ranges;
    }

    public static function statusMatches(int $code, string $spec): bool
    {
        foreach (self::parseStatusRanges($spec) ?? [] as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return true;
            }
        }

        return false;
    }

    public static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }

    /**
     * Krátký popis cíle kontroly pro výpisy.
     *
     * @param array<string, mixed> $config
     */
    public static function target(string $type, array $config): string
    {
        return match ($type) {
            'http' => ($config['method'] ?? 'GET') . ' ' . ($config['url'] ?? ''),
            'tcp', 'tls_expiry' => ($config['host'] ?? '') . ':' . ($config['port'] ?? ''),
            'ping' => (string) ($config['host'] ?? ''),
            'domain_expiry' => (string) ($config['domain'] ?? ''),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return array{degraded_days: int, unhealthy_days: int}
     */
    private static function days(array $input, int $degradedDefault, int $unhealthyDefault, array &$errors): array
    {
        $degraded = self::int($input['degraded_days'] ?? null, $degradedDefault);
        $unhealthy = self::int($input['unhealthy_days'] ?? null, $unhealthyDefault);
        if ($degraded === null || $unhealthy === null || $unhealthy < 0 || $degraded < $unhealthy || $degraded > 365) {
            $errors['degraded_days'] = 'invalid_days';
        }

        return ['degraded_days' => (int) $degraded, 'unhealthy_days' => (int) $unhealthy];
    }

    private static function int(mixed $value, ?int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\s*\d{1,9}\s*$/', $value) === 1 ? (int) $value : null;
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    /**
     * @return array{name: string, interval_s: int, timeout_s: int, failure_threshold: int, enabled: bool, notify_on_degraded: bool}
     */
    private static function emptyCommon(): array
    {
        return ['name' => '', 'interval_s' => 60, 'timeout_s' => 10, 'failure_threshold' => 2, 'enabled' => true, 'notify_on_degraded' => false];
    }
}
