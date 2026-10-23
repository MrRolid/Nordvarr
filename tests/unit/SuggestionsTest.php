<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\Suggestions;
use PHPUnit\Framework\TestCase;

final class SuggestionsTest extends TestCase
{
    /**
     * @param array<string, string> $attributes
     * @param list<array<string, mixed>> $checks
     * @return list<string>
     */
    private static function keys(string $type, string $name, array $attributes = [], array $checks = []): array
    {
        return array_column(Suggestions::forObject(['type' => $type, 'name' => $name], $attributes, $checks), 'key');
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    private static function check(string $type, array $input): array
    {
        return ['type' => $type, 'config' => CheckTypes::validate($type, $input)['config']];
    }

    public function testWebsite(): void
    {
        self::assertSame(['http:GET https://shop.example.com/', 'tls_expiry:shop.example.com:443'], self::keys('website', 'shop.example.com'));
        self::assertSame(['http:GET https://www.example.com/obchod', 'tls_expiry:www.example.com:443'],
            self::keys('website', 'E-shop', ['url' => 'https://www.example.com/obchod']), 'Atribut url má přednost před názvem.');
        self::assertSame(['http:GET http://intranet.example.net/'], self::keys('website', 'intranet', ['URL' => 'http://intranet.example.net/']), 'Bez https není kontrola certifikátu.');
        self::assertSame([], self::keys('website', 'Hlavní web'), 'Název není adresa a url chybí.');
        self::assertSame([], self::keys('website', '192.0.2.10'), 'IP adresa není web s certifikátem.');
    }

    public function testDomain(): void
    {
        self::assertSame(['domain_expiry:example.com'], self::keys('domain', 'Example.com'));
        self::assertSame([], self::keys('domain', 'firemní doména'));
    }

    public function testServers(): void
    {
        self::assertSame(['ping:192.0.2.10'], self::keys('server', '192.0.2.10'));
        self::assertSame(['ping:hv1.example.net'], self::keys('vm', 'hv1.example.net'));
        self::assertSame(['ping:198.51.100.7'], self::keys('server', 'Databázový server', ['ip' => '198.51.100.7']), 'Atribut ip má přednost.');
        self::assertSame(['ping:2001:db8::1'], self::keys('network_device', 'router', ['ipv6' => '2001:db8::1']));
        self::assertSame([], self::keys('server', 'server v racku'));
    }

    public function testServicesAndDatabases(): void
    {
        self::assertSame(['tcp:192.0.2.20:5432'], self::keys('database', 'shop db', ['host' => '192.0.2.20', 'port' => '5432']));
        self::assertSame(['http:GET https://api.example.com/health', 'tls_expiry:api.example.com:443'],
            self::keys('service', 'api', ['url' => 'https://api.example.com/health', 'port' => '8080', 'host' => '192.0.2.5']), 'S url se TCP nenavrhuje.');
        self::assertSame([], self::keys('service', 'shop-api.example.com'), 'U služby se z názvu nic nehádá.');
    }

    public function testExistingChecksAreNotSuggestedAgain(): void
    {
        $existing = [self::check('http', ['url' => 'https://shop.example.com/'])];
        self::assertSame(['tls_expiry:shop.example.com:443'], self::keys('website', 'shop.example.com', [], $existing));
        $existing[] = self::check('tls_expiry', ['host' => 'shop.example.com']);
        self::assertSame([], self::keys('website', 'shop.example.com', [], $existing));
        self::assertSame(['ping:192.0.2.10'], self::keys('server', '192.0.2.10', [], [self::check('tcp', ['host' => '192.0.2.10', 'port' => '22'])]));
    }

    public function testInvalidTargetsAreDropped(): void
    {
        self::assertSame([], self::keys('website', 'x', ['url' => 'ftp://example.com/']));
        self::assertSame([], self::keys('server', 'x', ['ip' => '-oProxyCommand=evil']));
        self::assertSame([], self::keys('database', 'x', ['host' => '192.0.2.1', 'port' => '99999']));
    }
}
