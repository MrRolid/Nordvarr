<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Upsert;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Tests\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class UpsertTest extends TestCase
{
    private PDO $pdo;
    private Inventory $inventory;
    private Upsert $upsert;

    protected function setUp(): void
    {
        $this->pdo = TestDb::memory();
        $this->inventory = new Inventory($this->pdo);
        $this->upsert = new Upsert($this->inventory);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $result = [];
        foreach (['objects', 'object_external_ids', 'object_attributes', 'relationships', 'tags', 'object_tags', 'object_docs', 'doc_revisions', 'events'] as $table) {
            $result[$table] = (int) $this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function shop(): array
    {
        return [
            'external_id' => 'web:shop.example.com',
            'type' => 'website',
            'name' => 'shop.example.com',
            'attributes' => ['document_root' => '/srv/www/shop', 'ports' => [80, 443]],
            'tags' => ['eshop', 'Kritické'],
            'relationships' => [['kind' => 'depends_on', 'to_external_id' => 'vm:app1']],
            'docs_markdown' => 'Volitelný text dokumentace',
        ];
    }

    private function app1(): void
    {
        $this->upsert->run(['external_id' => 'vm:app1', 'type' => 'vm', 'name' => 'app1.example.com'], 'ansible');
    }

    public function testRepeatedUpsertDuplicatesNothing(): void
    {
        $this->app1();
        $first = $this->upsert->run($this->shop(), 'ansible');
        self::assertTrue($first['created']);
        self::assertTrue($first['changed']);
        self::assertSame(['document_root', 'ports'], $first['changes']['attributes']['added']);
        self::assertSame(['eshop', 'kritické'], $first['changes']['tags']['added']);
        self::assertSame(['depends_on:vm:app1'], $first['changes']['relationships']['added']);
        self::assertTrue($first['changes']['docs']);
        $before = $this->counts();
        $updatedAt = $this->pdo->query('SELECT updated_at FROM objects WHERE id = ' . $this->pdo->quote($first['id']))->fetchColumn();

        for ($i = 0; $i < 3; $i++) {
            $again = $this->upsert->run($this->shop(), 'ansible');
            self::assertSame($first['id'], $again['id']);
            self::assertFalse($again['created']);
            self::assertFalse($again['changed'], json_encode($again['changes']) ?: '');
        }
        self::assertSame($before, $this->counts());
        self::assertSame($updatedAt, $this->pdo->query('SELECT updated_at FROM objects WHERE id = ' . $this->pdo->quote($first['id']))->fetchColumn());
        self::assertSame('[80,443]', $this->pdo->query("SELECT value FROM object_attributes WHERE key = 'ports'")->fetchColumn());
    }

    public function testChangesAreReportedAndSourceStateIsReplaced(): void
    {
        $this->app1();
        $this->upsert->run(['external_id' => 'vm:app2', 'type' => 'vm', 'name' => 'app2.example.com'], 'ansible');
        $first = $this->upsert->run($this->shop(), 'ansible');
        // Ruční atribut a vztah se při upsertu ze zdroje nemažou.
        $this->inventory->attributes->set($first['id'], 'owner', 'tým e-shop', false, 'manual');

        $input = $this->shop();
        $input['name'] = 'Shop';
        $input['attributes'] = ['document_root' => '/srv/www/shop2'];
        $input['relationships'] = [['kind' => 'depends_on', 'to_external_id' => 'vm:app2']];
        $input['docs_markdown'] = 'Nový text';
        $result = $this->upsert->run($input, 'ansible');

        self::assertSame(['name'], $result['changes']['object']);
        self::assertSame(['document_root'], $result['changes']['attributes']['updated']);
        self::assertSame(['ports'], $result['changes']['attributes']['removed']);
        self::assertSame(['depends_on:vm:app2'], $result['changes']['relationships']['added']);
        self::assertCount(1, $result['changes']['relationships']['removed']);
        self::assertTrue($result['changes']['docs']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM doc_revisions')->fetchColumn());
        self::assertSame('tým e-shop', $this->pdo->query("SELECT value FROM object_attributes WHERE key = 'owner'")->fetchColumn());
    }

    public function testMissingTargetsReturnListAndWriteNothing(): void
    {
        $before = $this->counts();
        $input = $this->shop();
        $input['relationships'][] = ['kind' => 'contains', 'to_external_id' => 'vm:neni'];
        try {
            $this->upsert->run($input, 'ansible');
            self::fail('Upsert měl selhat.');
        } catch (ValidationException $e) {
            self::assertSame(['vm:app1', 'vm:neni'], $e->missing);
        }
        self::assertSame($before, $this->counts());
    }

    public function testSourcesAreSeparated(): void
    {
        $this->upsert->run(['external_id' => 'srv:1', 'type' => 'server', 'name' => 'a.example.com', 'attributes' => ['ram' => '16 GB']], 'ansible');
        $other = $this->upsert->run(['external_id' => 'srv:1', 'type' => 'server', 'name' => 'a.example.com', 'attributes' => ['ram' => '32 GB']], 'cmdb');
        self::assertTrue($other['created'], 'Stejné external_id z jiného zdroje je jiný objekt.');
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM objects')->fetchColumn());
        self::assertSame('a-example-com-2', $this->pdo->query("SELECT slug FROM objects WHERE id = " . $this->pdo->quote($other['id']))->fetchColumn());
    }

    public function testValidationErrors(): void
    {
        $cases = [
            [['type' => 'vm', 'name' => 'x'], 'external_id'],
            [['external_id' => 'a', 'type' => 'toaster', 'name' => 'x'], 'type'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => ''], 'name'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => 'x', 'attributes' => ['má mezeru' => 'x']], 'attributes'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => 'x', 'attributes' => ['a', 'b']], 'attributes'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => 'x', 'tags' => ['dva slova']], 'tags'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => 'x', 'relationships' => [['kind' => 'loves', 'to_external_id' => 'b']]], 'relationships'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => 'x', 'relationships' => [['kind' => 'depends_on', 'to_external_id' => 'a']]], 'relationships'],
            [['external_id' => 'a', 'type' => 'vm', 'name' => 'x', 'docs_markdown' => 5], 'docs_markdown'],
        ];
        foreach ($cases as [$input, $field]) {
            try {
                $this->upsert->run($input, 'ansible');
                self::fail('Měl selhat vstup ' . json_encode($input));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors, json_encode($input) ?: '');
            }
        }
    }

    public function testContainsCycleIsRejected(): void
    {
        $this->upsert->run(['external_id' => 'vm:1', 'type' => 'vm', 'name' => 'vm1'], 's');
        $this->upsert->run(['external_id' => 'srv:1', 'type' => 'server', 'name' => 'srv1',
            'relationships' => [['kind' => 'contains', 'to_external_id' => 'vm:1']]], 's');
        try {
            $this->upsert->run(['external_id' => 'vm:1', 'type' => 'vm', 'name' => 'vm1',
                'relationships' => [['kind' => 'contains', 'to_external_id' => 'srv:1']]], 's');
            self::fail('Cyklus contains měl být odmítnut.');
        } catch (ValidationException $e) {
            self::assertSame(['relationships' => 'contains_cycle'], $e->errors);
        }
        // depends_on cykly nevadí.
        $result = $this->upsert->run(['external_id' => 'vm:1', 'type' => 'vm', 'name' => 'vm1',
            'relationships' => [['kind' => 'depends_on', 'to_external_id' => 'srv:1']]], 's');
        self::assertSame(['depends_on:srv:1'], $result['changes']['relationships']['added']);
    }

    public function testAttributeConflictGrouping(): void
    {
        $id = $this->upsert->run(['external_id' => 'srv:1', 'type' => 'server', 'name' => 'a', 'attributes' => ['ram' => '16 GB', 'os' => 'Debian 13']], 'ansible')['id'];
        $this->inventory->attributes->set($id, 'ram', '32 GB', false, 'manual');
        $this->inventory->attributes->set($id, 'os', 'Debian 13', false, 'cmdb');
        $groups = array_column($this->inventory->attributes->grouped($id), null, 'key');
        self::assertTrue($groups['ram']['conflict']);
        self::assertSame('32 GB', $groups['ram']['valid']);
        self::assertFalse($groups['os']['conflict']);
        self::assertSame('Debian 13', $groups['os']['valid']);
    }
}
