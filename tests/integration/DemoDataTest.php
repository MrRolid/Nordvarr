<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\DemoData;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class DemoDataTest extends TestCase
{
    public function testLoadsFortyObjectsIdempotently(): void
    {
        $pdo = TestDb::memory();
        $inventory = new Inventory($pdo);
        self::assertSame(['objects' => 43, 'events' => 8], (new DemoData($inventory))->load());
        $counts = static fn (): array => array_map(
            static fn (string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(),
            ['objects' => 'objects', 'relationships' => 'relationships', 'events' => 'events', 'attrs' => 'object_attributes', 'revs' => 'doc_revisions']
        );
        $before = $counts();
        self::assertSame(43, $before['objects']);
        (new DemoData($inventory))->load();
        self::assertSame($before, $counts());
    }

    public function testDemoDataUsesOnlyFictitiousNamesAndDocumentationRanges(): void
    {
        $json = json_encode([DemoData::objects(), DemoData::events()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $json, $ips);
        foreach ($ips[0] as $ip) {
            self::assertMatchesRegularExpression('/^(192\.0\.2|198\.51\.100|203\.0\.113)\./', $ip);
        }
        preg_match_all('/[a-z0-9-]+\.(?:[a-z0-9-]+\.)*(com|net|org|cz|eu|io)\b/', $json, $hosts);
        foreach ($hosts[0] as $host) {
            self::assertMatchesRegularExpression('/(^|\.)example\.(com|net)$/', $host);
        }
    }
}
