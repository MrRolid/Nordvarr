<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Security\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase
{
    public function testSecretKeys(): void
    {
        foreach (['password', 'db_pass', 'PWD', 'client_secret', 'api_token', 'ssh_key', 'private_cert'] as $key) {
            self::assertTrue(SecretMasker::isSecretKey($key), $key);
            self::assertSame('***', SecretMasker::maskAttribute($key, 'kanarek-tajne-1'));
        }
        self::assertFalse(SecretMasker::isSecretKey('ip'));
        self::assertSame('192.0.2.1', SecretMasker::maskAttribute('ip', '192.0.2.1'));
    }

    public function testUrlAndDsnCredentials(): void
    {
        self::assertSame('https://admin:***@example.com/x', SecretMasker::maskText('https://admin:kanarek-heslo@example.com/x'));
        self::assertSame('postgres://app:***@db.example.com/shop', SecretMasker::maskText('postgres://app:kanarek@db.example.com/shop'));
        self::assertSame('host=db.example.com;password=***;user=app', SecretMasker::maskText('host=db.example.com;password=kanarek;user=app'));
        self::assertSame('https://example.com/a:b@c', SecretMasker::maskText('https://example.com/a:b@c'));
    }

    public function testStrictMaskingOfSecretsInText(): void
    {
        $cases = [
            'password: kanarek-1' => 'password: ***',
            'export DB_PASSWORD="kanarek 2"' => 'export DB_PASSWORD=***',
            "client_secret='kanarek 3' dál" => 'client_secret=*** dál',
            'heslo: Zahrada2026!' => 'heslo: ***',
            'Authorization: Bearer kanarek-4-abcdef' => 'Authorization: Bearer ***',
            'aws_secret_access_key = kanarek5' => 'aws_secret_access_key = ***',
            'postgres://app:kanarek6@db.example.com/shop' => 'postgres://app:***@db.example.com/shop',
            'host=db;password=kanarek7;user=app' => 'host=db;password=***;user=app',
            'klíč xK9mQ2vL7pR4tN8wZ3bC6yH1 ve větě' => 'klíč *** ve větě',
            'sha256 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08' => 'sha256 ***',
            "-----BEGIN RSA PRIVATE KEY-----\nMIIEkanarek8\n-----END RSA PRIVATE KEY-----\nkonec" => "***\nkonec",
        ];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, SecretMasker::maskStrict($input), $input);
        }
        self::assertSame('***', SecretMasker::maskAttributeStrict('api_token', 'cokoli'));
        self::assertSame('deploy ***', SecretMasker::maskAttributeStrict('note', 'deploy aB3dE5gH7jK9mN1pQ3sT5vW7'));
    }

    public function testStrictMaskingKeepsOrdinaryText(): void
    {
        foreach ([
            'Restart přes systemctl restart shop-api.',
            'Heslo k databázi je v trezoru.',
            'objekt 01a15b85-582e-7a65-b77a-24a0065da59a',
            'commit 3f2a9c1d4e5b6a7f8091a2b3c4d5e6f7a8b9c0d1',
            'cesta /srv/www/shop/releases/2026-10-20',
            'very-long-hostname-web01.example.com 192.0.2.10 2001:db8::1',
            'url: https://shop.example.com/health?check=1',
            'NGINX_WORKER_PROCESSES_AUTO_TUNING_ENABLED',
        ] as $text) {
            self::assertSame($text, SecretMasker::maskStrict($text), $text);
        }
    }

    public function testEntropy(): void
    {
        self::assertSame(0.0, SecretMasker::entropy('aaaa'));
        self::assertEqualsWithDelta(2.0, SecretMasker::entropy('abcd'), 0.0001);
        self::assertTrue(SecretMasker::looksRandom('xK9mQ2vL7pR4tN8wZ3bC6yH1'));
        self::assertFalse(SecretMasker::looksRandom('Kanarek01Atribut'), 'Malá entropie, slovo s číslem.');
        self::assertFalse(SecretMasker::looksRandom('abc'));
    }
}
