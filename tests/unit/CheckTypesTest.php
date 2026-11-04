<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Checks\CheckTypes;
use PHPUnit\Framework\TestCase;

final class CheckTypesTest extends TestCase
{
    public function testHttpDefaultsAndValidation(): void
    {
        $ok = CheckTypes::validate('http', ['url' => 'https://www.example.com/zdravi']);
        self::assertSame([], $ok['errors']);
        self::assertSame(['url' => 'https://www.example.com/zdravi', 'method' => 'GET', 'expected_status' => '200-399', 'contains' => '', 'latency_degraded_ms' => 0], $ok['config']);
        self::assertSame(['name' => '', 'interval_s' => 60, 'timeout_s' => 10, 'failure_threshold' => 2, 'enabled' => true, 'notify_on_degraded' => false], $ok['common']);

        foreach (['ftp://example.com/', 'javascript:alert(1)', 'https:///bez-hosta', 'example.com', 'https://example.com/ mezera'] as $url) {
            self::assertArrayHasKey('url', CheckTypes::validate('http', ['url' => $url])['errors'], $url);
        }
        self::assertSame('contains_needs_get', CheckTypes::validate('http', ['url' => 'http://example.com', 'method' => 'HEAD', 'contains' => 'x'])['errors']['contains']);
        self::assertArrayHasKey('expected_status', CheckTypes::validate('http', ['url' => 'http://example.com', 'expected_status' => '200-'])['errors']);
        self::assertArrayHasKey('interval_s', CheckTypes::validate('http', ['url' => 'http://example.com', 'interval_s' => '30'])['errors']);
        self::assertArrayHasKey('timeout_s', CheckTypes::validate('http', ['url' => 'http://example.com', 'timeout_s' => '31'])['errors']);
        self::assertArrayHasKey('failure_threshold', CheckTypes::validate('http', ['url' => 'http://example.com', 'failure_threshold' => '0'])['errors']);
    }

    public function testTypeSpecificDefaults(): void
    {
        $tls = CheckTypes::validate('tls_expiry', ['host' => 'www.example.com']);
        self::assertSame(['host' => 'www.example.com', 'port' => 443, 'degraded_days' => 21, 'unhealthy_days' => 7], $tls['config']);
        self::assertTrue($tls['common']['notify_on_degraded']);
        self::assertSame(1, $tls['common']['failure_threshold']);

        $domain = CheckTypes::validate('domain_expiry', ['domain' => 'Example.COM.', 'interval_s' => '60']);
        self::assertSame([], $domain['errors']);
        self::assertSame('example.com', $domain['config']['domain']);
        self::assertSame(86400, $domain['common']['interval_s'], 'Doména se kontroluje jednou denně.');
        self::assertSame(30, $domain['config']['degraded_days']);
        self::assertArrayNotHasKey('expires_on', $domain['config'], 'Prázdné ruční datum se neukládá.');

        // Ruční datum expirace (registry bez zveřejněné expirace, např. .eu).
        self::assertSame('2027-03-31', CheckTypes::validate('domain_expiry', ['domain' => 'example.eu', 'expires_on' => ' 2027-03-31 '])['config']['expires_on']);
        foreach (['31.3.2027', '2027-02-30', '2027-3-31', '1999-12-31', 'zítra'] as $bad) {
            self::assertSame('invalid_date', CheckTypes::validate('domain_expiry', ['domain' => 'example.eu', 'expires_on' => $bad])['errors']['expires_on'] ?? null, $bad);
        }

        self::assertSame(['grace_s' => 300], CheckTypes::validate('heartbeat', [])['config']);
        self::assertFalse(CheckTypes::validate('ping', ['host' => '192.0.2.1', 'enabled' => '0'])['common']['enabled']);
    }

    public function testHostValidation(): void
    {
        foreach (['example.com', 'a-b.example.net', '192.0.2.10', '2001:db8::1', 'localhost'] as $host) {
            self::assertTrue(CheckTypes::isValidHost($host), $host);
        }
        foreach (['', '-c.example.com', 'host name', 'example..com', 'exa_mple.com', '-W 1'] as $host) {
            self::assertFalse(CheckTypes::isValidHost($host), $host);
        }
        self::assertSame('invalid_port', CheckTypes::validate('tcp', ['host' => 'example.com', 'port' => '70000'])['errors']['port']);
        self::assertSame('invalid_port', CheckTypes::validate('tcp', ['host' => 'example.com'])['errors']['port']);
        self::assertSame('invalid_domain', CheckTypes::validate('domain_expiry', ['domain' => '192.0.2.1'])['errors']['domain']);
        self::assertSame('invalid_days', CheckTypes::validate('tls_expiry', ['host' => 'example.com', 'degraded_days' => '5', 'unhealthy_days' => '7'])['errors']['degraded_days']);
        self::assertSame('invalid_check_type', CheckTypes::validate('smtp', [])['errors']['type']);
    }

    public function testStatusRanges(): void
    {
        self::assertSame([[200, 299], [301, 301]], CheckTypes::parseStatusRanges('200-299, 301'));
        self::assertNull(CheckTypes::parseStatusRanges('99'));
        self::assertNull(CheckTypes::parseStatusRanges('300-200'));
        self::assertTrue(CheckTypes::statusMatches(302, '200-399'));
        self::assertFalse(CheckTypes::statusMatches(404, '200-399'));
        self::assertTrue(CheckTypes::statusMatches(401, '200,401'));
    }
}
