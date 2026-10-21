<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Inventory\Vocabulary;
use PHPUnit\Framework\TestCase;

final class VocabularyTest extends TestCase
{
    public function testSlugify(): void
    {
        self::assertSame('zluty-kun-shop-example-com', Vocabulary::slugify('Žlutý kůň / shop.example.com'));
        self::assertSame('objekt', Vocabulary::slugify('!!!'));
        self::assertSame(80, strlen(Vocabulary::slugify(str_repeat('a', 200))));
    }

    public function testNormalizeTag(): void
    {
        self::assertSame('eshop', Vocabulary::normalizeTag('  EShop '));
        self::assertSame('kritické', Vocabulary::normalizeTag('Kritické'));
        self::assertSame('a.b_c-1', Vocabulary::normalizeTag('a.b_c-1'));
        self::assertNull(Vocabulary::normalizeTag(''));
        self::assertNull(Vocabulary::normalizeTag('dva slova'));
        self::assertNull(Vocabulary::normalizeTag('<script>'));
        self::assertNull(Vocabulary::normalizeTag(str_repeat('a', 51)));
    }

    public function testSourceAndAttributeKey(): void
    {
        self::assertTrue(Vocabulary::isValidSource('deploy-ci'));
        self::assertFalse(Vocabulary::isValidSource('Deploy CI'));
        self::assertTrue(Vocabulary::isValidAttributeKey('net/ip.v4'));
        self::assertFalse(Vocabulary::isValidAttributeKey('má mezeru'));
    }
}
