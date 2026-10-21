<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Texty používají jen znaky z klávesnice: žádné dlouhé pomlčky, typografické
 * uvozovky, výpustky ani šipky (pravidlo z CLAUDE.md).
 */
final class TypographyTest extends TestCase
{
    private const FORBIDDEN = [
        "\u{2014}" => 'dlouhá pomlčka',
        "\u{2013}" => 'pomlčka en',
        "\u{201E}" => 'dolní uvozovka',
        "\u{201C}" => 'horní uvozovka',
        "\u{201D}" => 'horní uvozovka',
        "\u{2018}" => 'apostrof',
        "\u{2019}" => 'apostrof',
        "\u{2026}" => 'výpustka',
        "\u{2192}" => 'šipka',
        "\u{00D7}" => 'znak násobení',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        $root = dirname(__DIR__, 2);
        $dirs = ['lang', 'templates', 'src', 'public', 'migrations', 'bin', 'docs', 'tests'];
        foreach ($dirs as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (preg_match('/\.(php|md|sql|js|css|sh)$/', $file->getFilename()) === 1) {
                    yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
                }
            }
        }
        foreach (['README.md', 'config.example.php'] as $file) {
            if (is_file($root . '/' . $file)) {
                yield $file => [$root . '/' . $file];
            }
        }
    }

    #[DataProvider('files')]
    public function testNoNonKeyboardCharacters(string $file): void
    {
        $content = (string) file_get_contents($file);
        foreach (self::FORBIDDEN as $char => $name) {
            self::assertStringNotContainsString($char, $content, "Soubor obsahuje znak: $name");
        }
    }
}
