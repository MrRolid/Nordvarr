<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Export\LlmContext;
use Nordvarr\I18n;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\DocRepository;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * F16: u objektu víc dokumentů podle kategorie (provozní, runbook, DR, incidenty, ostatní).
 */
final class DocCategoriesTest extends TestCase
{
    use InstalledApp;

    private Inventory $inventory;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
        $this->inventory = new Inventory($this->pdo());
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    public function testRepositoryKeepsCategoriesApart(): void
    {
        $docs = $this->inventory->docs;
        $id = $this->inventory->objects->create(['type' => 'service', 'name' => 'shop-api']);
        self::assertTrue($docs->save($id, 'Postup obnovy', 'admin', 'dr'));
        self::assertTrue($docs->save($id, 'Popis služby', 'admin'));
        self::assertTrue($docs->save($id, '1. restart', 'admin', 'runbook'));
        self::assertFalse($docs->save($id, '1. restart', 'admin', 'runbook'), 'Beze změny nevznikne revize.');
        self::assertTrue($docs->save($id, '1. restart\n2. ověřit', 'admin', 'runbook'));
        self::assertFalse($docs->save($id, '', 'admin', 'incidenty'), 'Prázdná nová kategorie se nezakládá.');

        self::assertSame(['provoz', 'runbook', 'dr'], array_keys($docs->all($id)), 'Pořadí podle CATEGORIES.');
        self::assertSame('Popis služby', $docs->current($id)['markdown'] ?? null);
        self::assertSame(['dr' => 1, 'provoz' => 1, 'runbook' => 2], $docs->revisionCounts($id));
        self::assertCount(2, $docs->revisions($id, 'runbook'));
        self::assertCount(4, $docs->revisions($id));

        // Vyprázdněný dokument se na kartě nezobrazuje, revize zůstávají.
        self::assertTrue($docs->save($id, '', 'admin', 'dr'));
        self::assertSame(['provoz', 'runbook'], array_keys($docs->all($id)));
        self::assertCount(2, $docs->revisions($id, 'dr'));
    }

    public function testObjectCardAndEditFormPerCategory(): void
    {
        $id = $this->inventory->objects->create(['type' => 'service', 'name' => 'shop-api']);
        $page = $this->ui('GET', '/objects/' . $id)->body;
        self::assertStringContainsString('Dokumentace zatím chybí.', $page);
        self::assertStringContainsString('data-test="docs-add"', $page);
        self::assertStringContainsString('/objects/' . $id . '/docs?cat=runbook', $page);

        $form = $this->ui('GET', '/objects/' . $id . '/docs', [], ['cat' => 'runbook']);
        self::assertSame(200, $form->status);
        self::assertStringContainsString('Upravit dokumentaci: Runbook', $form->body);
        self::assertStringContainsString('name="category" value="runbook"', $form->body);
        self::assertStringContainsString('aria-current="page">Runbook', $form->body);

        $response = $this->post('/objects/' . $id . '/docs', ['category' => 'runbook', 'markdown' => "## Restart\n\n1. systemctl restart shop-api"]);
        self::assertSame(303, $response->status);
        self::assertStringEndsWith('#docs-runbook', $response->headers['Location']);
        $this->post('/objects/' . $id . '/docs', ['category' => 'dr', 'markdown' => 'Záloha je na zálohovacím serveru.']);
        $this->post('/objects/' . $id . '/docs', ['markdown' => 'Provozní popis bez kategorie']);

        $page = $this->ui('GET', '/objects/' . $id)->body;
        self::assertStringNotContainsString('Dokumentace zatím chybí.', $page);
        foreach (['provoz', 'runbook', 'dr'] as $cat) {
            self::assertStringContainsString('data-test="docs-' . $cat . '"', $page);
        }
        self::assertLessThan(strpos($page, 'data-test="docs-dr"'), strpos($page, 'data-test="docs-runbook"'), 'Runbook je hned za provozní dokumentací.');
        self::assertStringContainsString('<h3>Obnova po havárii (DR)</h3>', $page);
        self::assertStringContainsString('<h4>Restart</h4>', $page);
        self::assertStringContainsString('data-test="docs">', $page, 'Provozní dokumentace má dál data-test="docs".');
        self::assertStringContainsString('/objects/' . $id . '/docs?cat=incidenty', $page, 'Prázdné kategorie lze přidat.');
        self::assertStringNotContainsString('docs?cat=runbook">Runbook', $page);

        // Revize jsou oddělené po kategoriích a revize zná svou kategorii.
        $form = $this->ui('GET', '/objects/' . $id . '/docs', [], ['cat' => 'dr'])->body;
        self::assertSame(1, substr_count($form, '/docs/revisions/'));
        self::assertStringContainsString('Záloha je na zálohovacím serveru.', $form);
        $revisionId = (string) $this->inventory->docs->revisions($id, 'dr')[0]['id'];
        $revision = $this->ui('GET', '/objects/' . $id . '/docs/revisions/' . $revisionId)->body;
        self::assertStringContainsString('data-test="revision-category">Obnova po havárii (DR)', $revision);
        self::assertStringContainsString('/objects/' . $id . '/docs?cat=dr', $revision);

        // Neznámá kategorie.
        self::assertSame(404, $this->ui('GET', '/objects/' . $id . '/docs', [], ['cat' => 'sklep'])->status);
        self::assertSame(404, $this->post('/objects/' . $id . '/docs', ['category' => 'sklep', 'markdown' => 'x'])->status);
        self::assertSame([], $this->inventory->docs->revisions($id, 'sklep'));
    }

    public function testLlmContextContainsAllCategories(): void
    {
        $id = $this->inventory->objects->create(['type' => 'service', 'name' => 'shop-api']);
        $context = new LlmContext($this->pdo(), new I18n(dirname(__DIR__, 2) . '/lang', 'cs'), 'Europe/Prague');

        $this->inventory->docs->save($id, '## Popis', 'admin');
        $data = $context->build($id);
        self::assertSame('## Popis', $data['docs']['markdown'] ?? null, 'Jen provozní dokumentace zůstává beze změny.');

        $this->inventory->docs->save($id, "## Restart\n\npassword: TajneHeslo123", 'admin', 'runbook');
        $data = $context->build($id);
        self::assertNotNull($data);
        self::assertSame("# Provozní dokumentace\n\n### Popis\n\n# Runbook\n\n### Restart\n\npassword: ***", $data['docs']['markdown']);
        $markdown = $context->markdown($data);
        self::assertStringContainsString("### Runbook\n\n##### Restart", $markdown);
        self::assertStringNotContainsString('TajneHeslo123', $markdown);
    }

    public function testMigrationMovesExistingDocsToOperations(): void
    {
        $dir = TempDir::create();
        try {
            mkdir($dir . '/migrations');
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
                if (strcmp(basename($file), '0010') < 0) {
                    copy($file, $dir . '/migrations/' . basename($file));
                }
            }
            $pdo = Database::connectSqlite($dir . '/db.sqlite');
            (new Migrator($pdo, $dir . '/migrations'))->migrate();
            $inventory = new Inventory($pdo);
            $id = $inventory->objects->create(['type' => 'server', 'name' => 'hv1.example.net']);
            $pdo->prepare("INSERT INTO object_docs (object_id, markdown, updated_at, updated_by) VALUES (?, 'Starý text', '2026-10-01T10:00:00Z', 'admin')")->execute([$id]);
            $pdo->prepare("INSERT INTO doc_revisions (id, object_id, author, created_at, markdown) VALUES ('r1', ?, 'admin', '2026-10-01T10:00:00Z', 'Starý text')")->execute([$id]);

            copy(dirname(__DIR__, 2) . '/migrations/0010_kategorie_dokumentace.sql', $dir . '/migrations/0010_kategorie_dokumentace.sql');
            self::assertSame(['0010'], (new Migrator($pdo, $dir . '/migrations'))->migrate());
            self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());

            $docs = new DocRepository($pdo);
            self::assertSame('Starý text', $docs->current($id)['markdown'] ?? null);
            self::assertSame('provoz', $docs->revisions($id)[0]['category'] ?? null);
            self::assertTrue($docs->save($id, 'Runbook', 'admin', 'runbook'));

            try {
                $pdo->prepare("INSERT INTO object_docs (object_id, category, markdown, updated_at, updated_by) VALUES (?, 'sklep', 'x', 'x', 'x')")->execute([$id]);
                self::fail('Neznámou kategorii databáze nepřijme.');
            } catch (\PDOException) {
                // Očekáváno: omezení CHECK.
            }

            // Smazání objektu dál kaskádově maže dokumentaci.
            $pdo->prepare('DELETE FROM objects WHERE id = ?')->execute([$id]);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM object_docs')->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM doc_revisions')->fetchColumn());
        } finally {
            TempDir::remove($dir);
        }
    }
}
