<?php

declare(strict_types=1);

namespace Nordvarr\Tests;

/**
 * Dočasné adresáře pro testy, po testu se celé smažou.
 */
final class TempDir
{
    public static function create(string $prefix = 'nordvarr-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return realpath($dir) ?: $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Zkopíruje kód aplikace (bez config.php a dat) do dočasného adresáře,
     * aby instalace v testu nezapisovala do repozitáře.
     */
    public static function copyApp(string $target): string
    {
        $root = dirname(__DIR__);
        $app = $target . '/nordvarr';
        mkdir($app . '/bin', 0700, true);
        foreach (['public', 'src', 'templates', 'lang', 'migrations', 'agent'] as $dir) {
            self::copyDir($root . '/' . $dir, $app . '/' . $dir);
        }
        copy($root . '/bin/nordvarr.php', $app . '/bin/nordvarr.php');
        copy($root . '/VERSION', $app . '/VERSION');

        return $app;
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $from . '/' . $name;
            if (is_dir($src)) {
                self::copyDir($src, $to . '/' . $name);
            } else {
                copy($src, $to . '/' . $name);
            }
        }
    }
}
