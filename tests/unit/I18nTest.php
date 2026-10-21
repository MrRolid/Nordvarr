<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = dirname(__DIR__, 2) . '/lang';
    }

    public function testEnglishTranslationIsComplete(): void
    {
        $cs = I18n::loadFile($this->langDir, 'cs');
        $en = I18n::loadFile($this->langDir, 'en');
        self::assertSame([], array_values(array_diff(array_keys($cs), array_keys($en))), 'V en.php chybí klíče');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($cs))), 'V en.php jsou navíc klíče');
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        $cs = I18n::loadFile($this->langDir, 'cs');
        $en = I18n::loadFile($this->langDir, 'en');
        foreach ($cs as $key => $text) {
            preg_match_all('/\{[a-z_]+\}/', $text, $a);
            preg_match_all('/\{[a-z_]+\}/', $en[$key] ?? '', $b);
            sort($a[0]);
            sort($b[0]);
            self::assertSame($a[0], $b[0], "Parametry u klíče $key");
        }
    }

    public function testResolveAndFallback(): void
    {
        self::assertSame('cs', I18n::resolve('de'));
        self::assertSame('cs', I18n::resolve(null));
        self::assertSame('en', I18n::resolve('en'));
        $i18n = new I18n($this->langDir, 'en');
        self::assertSame('Sign in', $i18n->t('login.title'));
        self::assertSame('neznamy.klic', $i18n->t('neznamy.klic'));
        self::assertSame('Version 1.2', $i18n->t('footer.version', ['version' => '1.2']));
    }
}
