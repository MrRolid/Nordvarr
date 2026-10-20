<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class ReleaseBuildTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    public function testZipContainsAppWithoutConfigDataOrDevFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $version = trim((string) file_get_contents($root . '/VERSION'));
        $cmd = 'DIST_DIR=' . escapeshellarg($this->tmp) . ' ' . escapeshellarg($root . '/bin/build-release.sh') . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        $zip = $this->tmp . '/nordvarr-' . $version . '.zip';
        self::assertFileExists($zip);
        exec('unzip -Z1 ' . escapeshellarg($zip), $files, $code);
        self::assertSame(0, $code);

        foreach ([
            'nordvarr/public/index.php',
            'nordvarr/public/install.php',
            'nordvarr/public/.htaccess',
            'nordvarr/.htaccess',
            'nordvarr/config.example.php',
            'nordvarr/VERSION',
            'nordvarr/bin/nordvarr.php',
            'nordvarr/migrations/0001_zaklad.sql',
            'nordvarr/lang/en.php',
        ] as $expected) {
            self::assertContains($expected, $files);
        }
        foreach ($files as $file) {
            self::assertStringStartsWith('nordvarr/', $file);
            self::assertDoesNotMatchRegularExpression('#(^|/)config\.php$|\.sqlite|^nordvarr/(vendor|node_modules|tests)/#', $file);
        }
    }
}
