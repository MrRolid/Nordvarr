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
            'nordvarr/public/assets/js/topology.js',
            'nordvarr/agent/nordvarr-agent.sh',
            'nordvarr/agent/nordvarr-agent.service',
            'nordvarr/agent/nordvarr-agent.timer',
            'nordvarr/agent/nordvarr-agent.conf.example',
            'nordvarr/public/assets/vendor/VERSIONS.md',
            'nordvarr/public/assets/vendor/cytoscape-3.34.2/cytoscape.min.js',
            'nordvarr/public/assets/vendor/cytoscape-3.34.2/LICENSE',
            'nordvarr/public/assets/vendor/dagre-0.8.5/dagre.min.js',
            'nordvarr/public/assets/vendor/cytoscape-dagre-2.5.0/cytoscape-dagre.js',
            'nordvarr/public/assets/vendor/three-0.170.0/three.module.min.js',
            'nordvarr/public/assets/vendor/three-0.170.0/LICENSE',
            'nordvarr/public/assets/js/topology3d.js',
        ] as $expected) {
            self::assertContains($expected, $files);
        }
        foreach ($files as $file) {
            self::assertStringStartsWith('nordvarr/', $file);
            self::assertDoesNotMatchRegularExpression('#(^|/)config\.php$|\.sqlite|^nordvarr/(vendor|node_modules|tests)/#', $file);
        }
    }
}
