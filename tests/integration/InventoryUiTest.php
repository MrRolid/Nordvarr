<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\DemoData;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

final class InventoryUiTest extends TestCase
{
    use InstalledApp;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    private function createObject(string $name, string $type = 'server'): string
    {
        $response = $this->post('/objects', ['type' => $type, 'name' => $name, 'description' => 'popis']);
        self::assertSame(303, $response->status, $response->body);
        preg_match('#/objects/([0-9a-f-]{36})$#', $response->headers['Location'], $m);

        return $m[1];
    }

    private function show(string $id): string
    {
        $response = $this->ui('GET', '/objects/' . $id);
        self::assertSame(200, $response->status);

        return $response->body;
    }

    private function flash(): string
    {
        $flash = $this->session->get('flash');

        return is_array($flash) ? (string) $flash[0] : (string) $flash;
    }

    public function testCreateEditArchiveDelete(): void
    {
        $invalid = $this->post('/objects', ['type' => 'toaster', 'name' => '']);
        self::assertSame(422, $invalid->status);
        self::assertStringContainsString('Neplatný typ.', $invalid->body);

        $id = $this->createObject('hv1.example.net');
        self::assertStringContainsString('hv1.example.net', $this->show($id));

        $this->post('/objects/' . $id . '/edit', ['type' => 'server', 'name' => 'hv1 <b>nový</b>', 'description' => '']);
        $html = $this->show($id);
        self::assertStringContainsString('hv1 &lt;b&gt;nový&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>nový</b>', $html);

        $this->post('/objects/' . $id . '/lifecycle');
        self::assertStringContainsString('Archivované', $this->show($id));
        self::assertStringNotContainsString('/objects/' . $id, $this->ui('GET', '/inventory')->body);
        self::assertStringContainsString('/objects/' . $id, $this->ui('GET', '/inventory', [], ['lifecycle' => 'archived'])->body);

        $this->post('/objects/' . $id . '/delete');
        self::assertSame('object.delete_confirm_missing', $this->flash());
        self::assertSame(200, $this->ui('GET', '/objects/' . $id)->status);
        $this->post('/objects/' . $id . '/delete', ['confirm' => '1']);
        self::assertSame(404, $this->ui('GET', '/objects/' . $id)->status);
        self::assertContains('object.delete', array_column((new AuditLogRepository($this->pdo()))->latest(), 'action'));
    }

    public function testAttributesTagsRelationshipsDocsAndEvents(): void
    {
        $hv = $this->createObject('hv1.example.net');
        $vm = $this->createObject('db1.example.com', 'vm');

        $this->post('/objects/' . $vm . '/attributes', ['key' => 'ram', 'value' => '16 GB']);
        $this->post('/objects/' . $vm . '/attributes', ['key' => 'má mezeru', 'value' => 'x']);
        self::assertSame('err.invalid_attribute_key', $this->flash());
        (new Inventory($this->pdo()))->attributes->set($vm, 'ram', '8 GB', false, 'ansible');
        $html = $this->show($vm);
        self::assertStringContainsString('data-test="conflict"', $html);

        $this->post('/objects/' . $vm . '/tags', ['tag' => 'Databaze']);
        self::assertStringContainsString('>databaze</a>', $this->show($vm));
        $this->post('/objects/' . $vm . '/tags/delete', ['tag' => 'databaze']);
        self::assertStringNotContainsString('>databaze</a>', $this->show($vm));

        // hv obsahuje vm; opačný směr by vytvořil cyklus.
        $this->post('/objects/' . $vm . '/relationships', ['relation' => 'in:contains', 'target' => $hv]);
        self::assertSame('object.relationship_added', $this->flash());
        $this->post('/objects/' . $vm . '/relationships', ['relation' => 'out:contains', 'target' => $hv]);
        self::assertSame('err.contains_cycle', $this->flash());
        self::assertStringContainsString('je obsažen v', $this->show($vm));
        self::assertStringContainsString('obsahuje', $this->show($hv));

        $this->post('/objects/' . $vm . '/docs', ['markdown' => "# Obnova\n\n<script>alert(1)</script>"]);
        $this->post('/objects/' . $vm . '/docs', ['markdown' => "# Obnova\n\n<script>alert(1)</script>"]);
        self::assertSame('docs.unchanged', $this->flash());
        $this->post('/objects/' . $vm . '/docs', ['markdown' => 'Druhá verze']);
        $html = $this->show($vm);
        self::assertStringContainsString('Druhá verze', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        $revisions = $this->pdo()->query('SELECT id FROM doc_revisions ORDER BY created_at, id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(2, $revisions);
        $old = $this->ui('GET', '/objects/' . $vm . '/docs/revisions/' . $revisions[0]);
        self::assertStringContainsString('<h2>Obnova</h2>', $old->body);
        self::assertStringContainsString('&lt;script&gt;', $old->body);

        // Událost na vm se ukáže i u souseda hv.
        $this->post('/objects/' . $vm . '/events', ['type' => 'restart', 'summary' => 'restart po aktualizaci', 'details' => '', 'occurred_at' => '2026-10-20T15:00']);
        self::assertSame('event.added', $this->flash());
        self::assertSame('2026-10-20T13:00:00Z', $this->pdo()->query('SELECT occurred_at FROM events')->fetchColumn());
        $hvHtml = $this->show($hv);
        self::assertStringContainsString('restart po aktualizaci', $hvHtml);
        self::assertStringContainsString('data-test="neighbor-event"', $hvHtml);
    }

    public function testInventoryFilterAndBulkTag(): void
    {
        (new DemoData(new Inventory($this->pdo())))->load();
        $all = $this->ui('GET', '/inventory');
        self::assertStringContainsString('Nalezeno objektů: 43', $all->body);
        self::assertStringContainsString('Nalezeno objektů: 6', $this->ui('GET', '/inventory', [], ['type' => 'website'])->body);
        self::assertStringContainsString('Nalezeno objektů: 1', $this->ui('GET', '/inventory', [], ['q' => '192.0.2.35'])->body);
        $eshop = $this->ui('GET', '/inventory', [], ['tag' => 'eshop', 'type' => 'website'])->body;
        self::assertStringContainsString('Nalezeno objektů: 2', $eshop);

        $ids = $this->pdo()->query("SELECT id FROM objects WHERE type = 'domain'")->fetchAll(\PDO::FETCH_COLUMN);
        $response = $this->post('/inventory/tags', ['ids' => $ids, 'tag' => 'registrace', 'back' => 'type=domain&evil=1']);
        self::assertSame('/inventory?type=domain', $response->headers['Location']);
        self::assertSame(['inventory.bulk_done', ['tag' => 'registrace', 'count' => 2]], $this->session->get('flash'));
        self::assertStringContainsString('Nalezeno objektů: 2', $this->ui('GET', '/inventory', [], ['tag' => 'registrace'])->body);
    }

    public function testTokensAreShownOnceAndAudited(): void
    {
        $bad = $this->post('/settings/tokens', ['name' => 'X', 'source' => 'manual', 'scopes' => ['events:write']]);
        self::assertSame(422, $bad->status);

        $response = $this->post('/settings/tokens', ['name' => 'Deploy', 'source' => 'deploy-ci', 'scopes' => ['events:write']]);
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertMatchesRegularExpression('/data-test="new-token">(nv_[0-9a-f_]+)</', $response->body);
        preg_match('/data-test="new-token">(nv_[0-9a-f_]+)</', $response->body, $m);
        $token = $m[1];

        $page = $this->ui('GET', '/settings')->body;
        self::assertStringNotContainsString($token, $page);
        self::assertStringContainsString('deploy-ci', $page);
        $event = ['event_id' => 'e1', 'type' => 'deploy', 'occurred_at' => '2026-10-20T13:58:00Z', 'summary' => 's', 'objects' => [['id' => 'x']]];
        self::assertSame(422, $this->api('POST', '/api/v1/events', $event, $token)->status);

        $id = $this->pdo()->query('SELECT id FROM api_tokens')->fetchColumn();
        $this->post('/settings/tokens/' . $id . '/revoke');
        self::assertSame(401, $this->api('POST', '/api/v1/events', $event, $token)->status);

        $actions = array_column((new AuditLogRepository($this->pdo()))->latest(), 'action');
        self::assertContains('token.create', $actions);
        self::assertContains('token.revoke', $actions);
        self::assertStringNotContainsString($token, json_encode((new AuditLogRepository($this->pdo()))->latest()) ?: '');
    }

    public function testExportAndImportThroughUi(): void
    {
        (new DemoData(new Inventory($this->pdo())))->load();
        $export = $this->ui('GET', '/settings/export');
        self::assertSame(200, $export->status);
        self::assertStringStartsWith('attachment; filename="nordvarr-inventar-', $export->headers['Content-Disposition']);
        $data = self::json($export);
        self::assertCount(43, $data['objects']);

        $file = $this->tmp . '/export.json';
        file_put_contents($file, $export->body);
        $this->pdo()->exec('DELETE FROM objects');

        $upload = ['file' => ['name' => 'export.json', 'type' => 'application/json', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]];
        $this->post('/settings/import', [], $upload);
        self::assertSame('settings.import_confirm_missing', $this->flash());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM objects')->fetchColumn());

        $this->post('/settings/import', ['confirm' => '1'], $upload);
        self::assertSame('settings.imported', $this->flash());
        self::assertSame(43, (int) $this->pdo()->query('SELECT COUNT(*) FROM objects')->fetchColumn());
        self::assertCount(1, glob($this->tmp . '/nordvarr-data/backups/*-pred-importem-*.sqlite') ?: []);

        $again = self::json($this->ui('GET', '/settings/export'));
        unset($again['exported_at'], $data['exported_at']);
        self::assertSame($data, $again);

        file_put_contents($file, '{"format":"jine"}');
        $this->post('/settings/import', ['confirm' => '1'], $upload);
        self::assertSame('err.invalid_format', $this->flash());
        self::assertSame(43, (int) $this->pdo()->query('SELECT COUNT(*) FROM objects')->fetchColumn());
    }

    public function testTimezoneSetting(): void
    {
        $this->post('/settings/timezone', ['timezone' => 'Mars/Olympus']);
        self::assertSame('err.invalid_timezone', $this->flash());
        $this->post('/settings/timezone', ['timezone' => 'UTC']);
        self::assertSame('UTC', $this->pdo()->query("SELECT value FROM settings WHERE key = 'timezone'")->fetchColumn());
        self::assertContains('settings.timezone', array_column((new AuditLogRepository($this->pdo()))->latest(), 'action'));
    }

    public function testUnknownObjectIs404(): void
    {
        self::assertSame(404, $this->ui('GET', '/objects/neexistuje')->status);
        self::assertSame(404, $this->post('/objects/neexistuje/attributes', ['key' => 'a', 'value' => 'b'])->status);
    }

    public function testViewerCannotPost(): void
    {
        $this->pdo()->exec("UPDATE users SET role = 'viewer'");
        $response = $this->post('/objects', ['type' => 'server', 'name' => 'x']);
        self::assertSame(403, $response->status);
        self::assertSame(200, $this->ui('GET', '/inventory')->status);
    }
}
