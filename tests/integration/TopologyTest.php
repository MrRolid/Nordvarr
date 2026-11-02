<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Alerts\Notifier;
use Nordvarr\App;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Http\Request;
use Nordvarr\I18n;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\DocRepository;
use Nordvarr\Repository\TagRepository;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Time;
use Nordvarr\Topology\Topology;
use PHPUnit\Framework\TestCase;

final class TopologyTest extends TestCase
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

    private function topology(): Topology
    {
        return new Topology($this->pdo(), new I18n(dirname(__DIR__, 2) . '/lang', 'cs'));
    }

    private function object(string $name, string $type = 'service'): string
    {
        return $this->inventory->objects->create(['type' => $type, 'name' => $name]);
    }

    private function check(string $objectId, string $status, string $reason = '', ?int $latency = null): void
    {
        $service = new CheckService($this->pdo());
        $valid = CheckTypes::validate('tcp', ['host' => '127.0.0.1', 'port' => '9', 'failure_threshold' => '1', 'notify_on_degraded' => '1']);
        $checkId = $service->checks->create($objectId, 'tcp', $valid['common'], $valid['config']);
        $at = Time::toDb(Time::now());
        $result = $status === 'healthy' ? CheckResult::healthy($at, $at, $latency, 'ok') : new CheckResult($status, $reason, 'detail', $latency, $at, $at);
        $service->record((array) $service->checks->find($checkId), $result);
    }

    /**
     * Web závisí na databázi, obě kontroly selhávají, alerty jsou otevřené.
     *
     * @return array{web: string, db: string}
     */
    private function databaseOutage(): array
    {
        $db = $this->object('db1.example.com', 'database');
        $web = $this->object('shop.example.com', 'website');
        $this->inventory->relate($web, $db, 'depends_on', '', 'manual');
        $this->check($db, 'unhealthy', 'tcp_refused');
        $this->check($web, 'unhealthy', 'tcp_refused');
        Notifier::fromConfig($this->pdo(), $this->app, $this->config())->run();

        return ['web' => $web, 'db' => $db];
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, array $query = [], array $headers = []): \Nordvarr\Http\Response
    {
        $app = new App($this->app, $this->config());

        return $app->handle(new Request('GET', $path, $query, [], [], $headers, '', true, false, '192.0.2.50'), $this->session);
    }

    public function testNeighborhoodFollowsBothDirectionsUpToDepth(): void
    {
        // a -> b -> c -> d (závisí na), host obsahuje a; f nesouvisí.
        $a = $this->object('a');
        $b = $this->object('b');
        $c = $this->object('c');
        $d = $this->object('d');
        $host = $this->object('host', 'server');
        $this->object('f');
        $this->inventory->relate($a, $b, 'depends_on', '', 'manual');
        $this->inventory->relate($b, $c, 'depends_on', '', 'manual');
        $this->inventory->relate($c, $d, 'depends_on', '', 'manual');
        $this->inventory->relate($host, $a, 'contains', '', 'manual');

        $one = $this->topology()->neighborhoodIds($b, 1);
        self::assertSame($b, $one[0], 'Centrum je první.');
        self::assertEqualsCanonicalizing([$a, $b, $c], $one);
        self::assertEqualsCanonicalizing([$a, $b, $c, $d, $host], $this->topology()->neighborhoodIds($b, 2));
        self::assertEqualsCanonicalizing([$a, $b, $c, $d, $host], $this->topology()->neighborhoodIds($b, 9), 'Hloubka je nejvýš 2.');

        $graph = $this->topology()->neighborhood($b, 1);
        self::assertCount(3, $graph['nodes']);
        self::assertCount(2, $graph['edges'], 'Jen hrany mezi zobrazenými uzly.');
        self::assertFalse($graph['truncated']);
    }

    public function testGraphMarksCauseAndCausalEdge(): void
    {
        ['web' => $web, 'db' => $db] = $this->databaseOutage();
        $this->check($db, 'healthy', '', 12);
        $graph = $this->topology()->neighborhood($web, 1);

        $nodes = array_column(array_column($graph['nodes'], 'data'), null, 'id');
        self::assertSame('unhealthy', $nodes[$web]['status']);
        self::assertSame('TCP: tcp_refused', $nodes[$web]['reason']);
        self::assertSame($db, $nodes[$web]['cause']);
        self::assertTrue($nodes[$web]['center']);
        self::assertSame(1, $nodes[$web]['alerts']);
        self::assertSame(['healthy' => 0, 'degraded' => 0, 'unhealthy' => 1, 'unknown' => 0, 'paused' => 0], $nodes[$web]['ring']);
        self::assertFalse($nodes[$db]['center']);
        self::assertSame(['healthy' => 1, 'degraded' => 0, 'unhealthy' => 1, 'unknown' => 0, 'paused' => 0], $nodes[$db]['ring']);
        self::assertSame('Databáze', $nodes[$db]['typeLabel']);

        self::assertCount(1, $graph['edges']);
        $edge = $graph['edges'][0]['data'];
        self::assertSame([$web, $db, 'depends_on', 'závisí na', true], [$edge['source'], $edge['target'], $edge['kind'], $edge['label'], $edge['causal']]);
    }

    public function testGraphHasFloorsAndLayerOverride(): void
    {
        $host = $this->object('hv1.example.net', 'server');
        $app = $this->object('shop-app', 'service');
        $web = $this->object('shop.example.com', 'website');
        $this->inventory->relate($host, $app, 'contains', '', 'manual');
        $this->inventory->relate($web, $app, 'depends_on', '', 'manual');
        $this->inventory->attributes->set($app, 'Vrstva', 'containers', false, 'manual');
        // Hodnota z importu v rozporu s ruční: platí ruční.
        $this->inventory->attributes->set($app, 'Vrstva', 'services', false, 'import');

        $graph = $this->topology()->neighborhood($app, 1);
        $nodes = array_column(array_column($graph['nodes'], 'data'), null, 'id');
        self::assertSame(['hardware', 'containers', 'web'], [$nodes[$host]['floor'], $nodes[$app]['floor'], $nodes[$web]['floor']]);
        self::assertSame(
            [['web', 0, 'Weby a API'], ['containers', 1, 'Kontejnery (Docker, Kubernetes)'], ['hardware', 2, 'Hardware a síť']],
            array_map(static fn (array $f): array => [$f['floor'], $f['y'], $f['label']], $graph['floors'])
        );
        self::assertSame([0, 1, 2], [$nodes[$web]['fy'], $nodes[$app]['fy'], $nodes[$host]['fy']]);
        self::assertEqualsWithDelta($nodes[$host]['fx'], $nodes[$app]['fx'], 0.01, 'Sloupec: služba nad svým serverem.');

        self::assertSame(['nodes' => [], 'edges' => [], 'floors' => [], 'truncated' => false, 'total' => 0], $this->topology()->filtered(['type' => 'domain']));

        $page = $this->get('/topology', ['center' => $app, 'layout' => 'floors'])->body;
        self::assertStringContainsString('data-layout="floors"', $page);
        self::assertStringContainsString('name="layout" value="floors"', $page);
        self::assertStringContainsString('data-layout="floors" aria-pressed="true"', $page);
        self::assertStringContainsString('data-layout="auto" aria-pressed="true"', $this->get('/topology', ['center' => $app, 'layout' => 'x'])->body, 'Neznámá hodnota -> automaticky.');

        // 3D: modul s verzí v adrese (kvůli mezipaměti po aktualizaci), ovládání jen pro 3D skryté.
        $page = $this->get('/topology', ['center' => $app, 'layout' => '3d'])->body;
        self::assertStringContainsString('data-layout="3d" aria-pressed="true"', $page);
        self::assertMatchesRegularExpression('#data-module="[^"]*/assets/js/topology3d\.js\?v=#', $page);
        self::assertStringContainsString('data-3d="top" data-3d-only hidden', $page);
        self::assertStringContainsString('data-webgl-missing data-test="webgl-missing" hidden', $page);
    }

    public function testDefaultCenterPrefersUnsuppressedUnhealthyAlert(): void
    {
        $hub = $this->object('hub');
        $this->inventory->relate($this->object('x'), $hub, 'depends_on', '', 'manual');
        $this->inventory->relate($this->object('y'), $hub, 'depends_on', '', 'manual');
        self::assertSame($hub, $this->topology()->defaultCenter(), 'Bez alertů objekt s nejvíce vztahy.');

        $expiring = $this->object('example.net', 'domain');
        $this->check($expiring, 'degraded', 'domain_expiring');
        Notifier::fromConfig($this->pdo(), $this->app, $this->config())->run();
        self::assertSame($expiring, $this->topology()->defaultCenter());

        ['db' => $db] = $this->databaseOutage();
        self::assertSame($db, $this->topology()->defaultCenter(), 'Nefunkční kořen má přednost, potlačený web ne.');
    }

    public function testFilterByStatusAndLimit(): void
    {
        ['web' => $web, 'db' => $db] = $this->databaseOutage();
        $this->object('bez kontrol');
        $graph = $this->topology()->filtered(['status' => 'unhealthy']);
        self::assertEqualsCanonicalizing([$web, $db], array_column(array_column($graph['nodes'], 'data'), 'id'));
        self::assertCount(1, $graph['edges']);

        for ($i = 0; $i <= Topology::MAX_NODES; $i++) {
            $this->object('vm' . $i . '.example.com', 'vm');
        }
        $many = $this->topology()->filtered(['type' => 'vm']);
        self::assertTrue($many['truncated']);
        self::assertSame(Topology::MAX_NODES + 1, $many['total']);
        self::assertSame([], $many['nodes']);

        $page = $this->get('/topology', ['mode' => 'filter', 'type' => 'vm'])->body;
        self::assertStringContainsString('data-test="too-many"', $page);
        self::assertStringContainsString('Filtru odpovídá 301 objektů, graf jich zobrazí nejvýš 300.', $page);
        self::assertStringNotContainsString('id="cy"', $page);
    }

    public function testLargeNeighborhoodIsTruncatedWithNotice(): void
    {
        $hub = $this->object('sw1.example.net', 'network_device');
        for ($i = 0; $i <= Topology::MAX_NODES; $i++) {
            $this->inventory->relate($this->object('vm' . $i . '.example.com', 'vm'), $hub, 'depends_on', '', 'manual');
        }
        $graph = $this->topology()->neighborhood($hub, 1);
        self::assertTrue($graph['truncated']);
        self::assertSame(Topology::MAX_NODES + 2, $graph['total']);
        self::assertCount(Topology::MAX_NODES, $graph['nodes']);
        self::assertSame($hub, $graph['nodes'][0]['data']['id'], 'Střed se při ořezu neztratí.');

        $page = $this->get('/topology', ['center' => $hub, 'depth' => '1'])->body;
        self::assertStringContainsString('Zobrazeno 300 z 302 objektů okolí', $page);
        self::assertStringContainsString('id="cy"', $page);
    }

    public function testTopologyPageAndJsonEndpoints(): void
    {
        ['web' => $web, 'db' => $db] = $this->databaseOutage();
        (new DocRepository($this->pdo()))->save($db, "# Obnova\n\nRestart přes **systemctl**.", 'admin');
        (new TagRepository($this->pdo()))->attach($db, 'kriticke');
        $this->inventory->addManualEvent($db, 'deploy', 'migrace schématu 7', '', Time::toDb(Time::now()->modify('-10 minutes')));

        $page = $this->get('/topology', ['center' => $web, 'depth' => '9', 'status' => '<script>']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data-test="graph"', $page->body);
        self::assertStringContainsString('okolí: shop.example.com', $page->body);
        self::assertStringContainsString('vendor/cytoscape-3.34.2/cytoscape.min.js', $page->body);
        self::assertStringNotContainsString('<script>', str_replace(['<script src=', '<script type="module"'], '', $page->body));

        // Data grafu s ETag.
        $data = $this->get('/topology/data', ['center' => $web]);
        self::assertSame(200, $data->status);
        $json = json_decode($data->body, true);
        self::assertCount(2, $json['nodes']);
        $etag = $data->headers['ETag'];
        self::assertSame(304, $this->get('/topology/data', ['center' => $web], ['if-none-match' => $etag])->status);
        $this->check($web, 'healthy');
        self::assertSame(200, $this->get('/topology/data', ['center' => $web], ['if-none-match' => $etag])->status, 'Po změně stavu se data liší.');

        // Karta uzlu: stav, kontroly, incidenty, změny a dokumentace.
        $node = $this->get('/topology/node/' . $db);
        self::assertSame(200, $node->status);
        $html = json_decode($node->body, true)['html'];
        self::assertStringContainsString('data-test="node-card"', $html);
        self::assertStringContainsString('db1.example.com', $html);
        self::assertStringContainsString('Incidenty za 90 dní (1)', $html);
        self::assertStringContainsString('migrace schématu 7', $html);
        self::assertStringContainsString('<strong>systemctl</strong>', $html);
        self::assertStringContainsString('kriticke', $html);
        self::assertSame(404, $this->get('/topology/node/00000000-0000-4000-8000-000000000000')->status);
    }

    public function testObjectCardDiagnosisHeader(): void
    {
        ['web' => $web, 'db' => $db] = $this->databaseOutage();
        $this->inventory->addManualEvent($db, 'deploy', 'migrace schématu 7', '', Time::toDb(Time::now()->modify('-10 minutes')));
        $this->inventory->addManualEvent($db, 'note', 'stará poznámka', '', Time::toDb(Time::now()->modify('-30 hours')));

        $page = $this->get('/objects/' . $web)->body;
        self::assertMatchesRegularExpression('#data-test="object-status">.*nefunguje.*TCP: <code>tcp_refused</code>#s', $page);
        self::assertMatchesRegularExpression('#data-test="probable-cause"><a[^>]*>db1\.example\.com#', $page);
        self::assertMatchesRegularExpression('#data-test="last-change">.*nasazení.*migrace schématu 7.*u souseda:.*db1\.example\.com#s', $page);
        self::assertMatchesRegularExpression('#data-test="dependencies">.*závisí na.*db1\.example\.com.*nefunguje#s', $page);
        self::assertMatchesRegularExpression('#data-test="alerts-90"><a href="\#incidents">1</a>#', $page);
        self::assertStringContainsString('data-test="incidents"', $page);
        self::assertStringContainsString('data-test="show-topology"', $page);

        // Diagnóza jako JSON pro obnovování, s ETag.
        $diag = $this->get('/objects/' . $web . '/diagnosis');
        self::assertSame(200, $diag->status);
        self::assertStringContainsString('migrace schématu 7', json_decode($diag->body, true)['html']);
        self::assertSame(304, $this->get('/objects/' . $web . '/diagnosis', [], ['if-none-match' => $diag->headers['ETag']])->status);

        // Bez změny za 24 h (stará poznámka se nepočítá).
        $db2 = $this->object('db2.example.com', 'database');
        $this->inventory->addManualEvent($db2, 'note', 'stará poznámka', '', Time::toDb(Time::now()->modify('-30 hours')));
        self::assertMatchesRegularExpression('#data-test="last-change">\s*<span class="muted">Za 24 h žádná změna\.#', $this->get('/objects/' . $db2)->body);
    }
}
