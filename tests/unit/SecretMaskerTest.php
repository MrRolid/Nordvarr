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
}
