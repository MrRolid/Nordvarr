<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\DemoData;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Transfer;
use Nordvarr\Inventory\Upsert;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class TransferTest extends TestCase
{
    private const CANARIES = ['kanarek-heslo-91f3', 'kanarek-token-7c2e', 'kanarek-dsn-55aa', 'kanarek-docs-0b9d'];

    private function filled(): Inventory
    {
        $inventory = new Inventory(TestDb::memory());
        (new DemoData($inventory))->load();
        $upsert = new Upsert($inventory);
        $upsert->run(['external_id' => 'svc:tajne', 'type' => 'service', 'name' => 'služba s nastraženými tajemstvími',
            'attributes' => [
                'db_password' => self::CANARIES[0],
                'api_token' => self::CANARIES[1],
                'dsn' => 'pgsql:host=db.example.com;password=' . self::CANARIES[2],
                'admin_url' => 'https://admin:' . self::CANARIES[3] . '@example.com/',
            ],
            'docs_markdown' => 'Připojení: postgres://app:' . self::CANARIES[0] . '@db.example.com/shop',
        ], 'test');
        $id = (string) $inventory->externalIds->objectId('test', 'svc:tajne');
        $inventory->docs->save($id, "Druhá revize\n\nbez tajemství", 'admin@example.com');
        $inventory->addManualEvent($id, 'note', 'ruční poznámka', 'podrobnosti', '2026-10-20T10:00:00Z');

        return $inventory;
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function withoutTimestamp(array $export): array
    {
        unset($export['exported_at']);

        return $export;
    }

    public function testExportImportExportGivesSameResult(): void
    {
        $source = $this->filled();
        $first = (new Transfer($source))->export();
        self::assertSame(41, count($first['objects']));

        $target = new Inventory(TestDb::memory());
        $counts = (new Transfer($target))->import(json_decode(json_encode($first, JSON_THROW_ON_ERROR), true));
        self::assertSame(['objects' => 41, 'relationships' => count($first['relationships']), 'events' => count($first['events'])], $counts);

        $second = (new Transfer($target))->export();
        self::assertSame($this->withoutTimestamp($first), $this->withoutTimestamp($second));

        // Import přes existující data nahradí celý inventář, nic se nezdvojí.
        (new Transfer($target))->import($first);
        self::assertSame($this->withoutTimestamp($first), $this->withoutTimestamp((new Transfer($target))->export()));
    }

    public function testExportContainsNoCanarySecrets(): void
    {
        $json = json_encode((new Transfer($this->filled()))->export(), JSON_THROW_ON_ERROR);
        foreach (self::CANARIES as $canary) {
            self::assertStringNotContainsString($canary, $json);
        }
        self::assertStringContainsString('"value":"***"', $json);
    }

    public function testInvalidImportLeavesInventoryUntouched(): void
    {
        $inventory = $this->filled();
        $transfer = new Transfer($inventory);
        $before = $transfer->export();

        foreach ([['format' => 'neco'], ['format' => 'nordvarr-inventory', 'version' => 1, 'objects' => 'x', 'relationships' => [], 'events' => []]] as $bad) {
            try {
                $transfer->import($bad);
                self::fail('Import měl selhat.');
            } catch (ValidationException $e) {
                self::assertSame('invalid_format', $e->errors['file']);
            }
        }

        $broken = $before;
        $broken['relationships'][0]['kind'] = 'loves';
        try {
            $transfer->import($broken);
            self::fail('Import měl selhat.');
        } catch (ValidationException $e) {
            self::assertSame('invalid_content', $e->errors['file']);
        }
        self::assertSame($this->withoutTimestamp($before), $this->withoutTimestamp($transfer->export()));
    }
}
