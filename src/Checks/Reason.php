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
    // Hostový agent (F6).
    public const AGENT_MISSING = 'agent_missing';
    public const CPU_HIGH = 'cpu_high';
    public const MEMORY_HIGH = 'memory_high';
    public const DISK_FULL = 'disk_full';
    public const TEMPERATURE_HIGH = 'temperature_high';
    public const DISK_FAILING = 'disk_failing';
    public const DISK_WEAR = 'disk_wear';
    public const DISK_ERRORS = 'disk_errors';
    // Kontejnery Dockeru hlášené agentem (F12).
    public const CONTAINER_DOWN = 'container_down';

    public const ALL = [
        self::DNS_FAILURE, self::TCP_REFUSED, self::TCP_TIMEOUT, self::TLS_ERROR, self::HTTP_4XX, self::HTTP_5XX,
        self::CONTENT_MISMATCH, self::LATENCY_DEGRADED, self::CERT_EXPIRING, self::CERT_EXPIRED, self::DOMAIN_EXPIRING,
        self::DOMAIN_EXPIRED, self::RDAP_NO_EXPIRY, self::HEARTBEAT_MISSED, self::CHECK_TIMEOUT, self::TOOL_MISSING,
        self::DEPENDENCY_UNHEALTHY, self::UNKNOWN_ERROR, self::AGENT_MISSING, self::CPU_HIGH, self::MEMORY_HIGH,
        self::DISK_FULL, self::TEMPERATURE_HIGH, self::DISK_FAILING, self::DISK_WEAR, self::DISK_ERRORS,
        self::CONTAINER_DOWN,
    ];
}
