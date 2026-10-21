<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Kódy důvodů stavu kontroly (SPEC kap. 6).
 */
final class Reason
{
    public const DNS_FAILURE = 'dns_failure';
    public const TCP_REFUSED = 'tcp_refused';
    public const TCP_TIMEOUT = 'tcp_timeout';
    public const TLS_ERROR = 'tls_error';
    public const HTTP_4XX = 'http_4xx';
    public const HTTP_5XX = 'http_5xx';
    public const CONTENT_MISMATCH = 'content_mismatch';
    public const LATENCY_DEGRADED = 'latency_degraded';
    public const CERT_EXPIRING = 'cert_expiring';
    public const CERT_EXPIRED = 'cert_expired';
    public const DOMAIN_EXPIRING = 'domain_expiring';
    public const DOMAIN_EXPIRED = 'domain_expired';
    public const RDAP_NO_EXPIRY = 'rdap_no_expiry';
    public const HEARTBEAT_MISSED = 'heartbeat_missed';
    public const CHECK_TIMEOUT = 'check_timeout';
    public const TOOL_MISSING = 'tool_missing';
    public const DEPENDENCY_UNHEALTHY = 'dependency_unhealthy';
    public const UNKNOWN_ERROR = 'unknown_error';

    public const ALL = [
        self::DNS_FAILURE, self::TCP_REFUSED, self::TCP_TIMEOUT, self::TLS_ERROR, self::HTTP_4XX, self::HTTP_5XX,
        self::CONTENT_MISMATCH, self::LATENCY_DEGRADED, self::CERT_EXPIRING, self::CERT_EXPIRED, self::DOMAIN_EXPIRING,
        self::DOMAIN_EXPIRED, self::RDAP_NO_EXPIRY, self::HEARTBEAT_MISSED, self::CHECK_TIMEOUT, self::TOOL_MISSING,
        self::DEPENDENCY_UNHEALTHY, self::UNKNOWN_ERROR,
    ];
}
