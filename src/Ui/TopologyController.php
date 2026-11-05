<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Status;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\I18n;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Time;
use Nordvarr\Topology\Topology;
use Nordvarr\View\Markdown;

/**
 * Topologie: graf okolí objektu nebo objektů podle filtru, karta vybraného uzlu.
 */
final class TopologyController
{
    public function __construct(private readonly Ctx $ctx, private readonly string $appRoot)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/topology', fn (): Response => $this->page());
        $router->get('/topology/data', fn (): Response => JsonFragment::json($this->ctx, $this->graph($this->params())));
        $router->get('/topology/node/{id}', fn (array $p): Response => $this->node($p['id']));
    }

    private function topology(): Topology
    {
        return new Topology($this->ctx->pdo, new I18n($this->appRoot . '/lang', $this->ctx->view->lang()));
    }

    /**
     * Parametry pohledu z dotazu (neplatné hodnoty se nahradí výchozími).
     *
     * @return array{mode: string, center: string, depth: int, type: string, tag: string, status: string, layout: string}
     */
    private function params(): array
    {
        $r = $this->ctx->request;
        $inventory = new Inventory($this->ctx->pdo);
        $center = $r->queryString('center');
        if ($center === '' || $inventory->objects->find($center) === null) {
            $center = (string) $this->topology()->defaultCenter();
        }

        // Topologie z hlavní navigace (bez parametrů): všechny objekty ve 3D.
        if (array_diff_key($r->query, ['r' => true]) === []) {
            return ['mode' => 'filter', 'center' => $center, 'depth' => 2, 'type' => '', 'tag' => '', 'status' => '', 'layout' => '3d'];
        }

        return [
            'mode' => $r->queryString('mode') === 'filter' ? 'filter' : 'neighborhood',
            'center' => $center,
            'depth' => $r->queryString('depth') === '1' ? 1 : 2,
            'type' => in_array($r->queryString('type'), Vocabulary::OBJECT_TYPES, true) ? $r->queryString('type') : '',
            'tag' => Vocabulary::normalizeTag($r->queryString('tag')) ?? '',
            'status' => in_array($r->queryString('status'), [...Status::ALL, Status::NONE], true) ? $r->queryString('status') : '',
            'layout' => in_array($r->queryString('layout'), ['floors', '3d'], true) ? $r->queryString('layout') : 'auto',
        ];
    }

    /**
     * @param array{mode: string, center: string, depth: int, type: string, tag: string, status: string, layout: string} $params
     * @return array<string, mixed>
     */
    private function graph(array $params): array
    {
        $topology = $this->topology();
        if ($params['mode'] === 'filter') {
            return $topology->filtered(array_filter(['type' => $params['type'], 'tag' => $params['tag'], 'status' => $params['status']]));
        }
        if ($params['center'] === '') {
            return ['nodes' => [], 'edges' => [], 'floors' => [], 'truncated' => false, 'total' => 0];
        }

        return $topology->neighborhood($params['center'], $params['depth']);
    }

    private function page(): Response
    {
        $params = $this->params();
        $graph = $this->graph($params);
        $query = array_filter([
            'mode' => $params['mode'],
            'center' => $params['mode'] === 'neighborhood' ? $params['center'] : '',
            'depth' => $params['mode'] === 'neighborhood' ? (string) $params['depth'] : '',
            'type' => $params['type'],
            'tag' => $params['tag'],
            'status' => $params['status'],
        ]);
        $inventory = new Inventory($this->ctx->pdo);

        return $this->ctx->page('topology', [
            'title' => 'nav.topology',
            'params' => $params,
            'graph' => $graph,
            'dataUrl' => $this->ctx->urls->route('/topology/data', $query),
            'nodeUrl' => $this->ctx->urls->route('/topology/node/__ID__'),
            'objectUrl' => $this->ctx->urls->route('/objects/__ID__'),
            'centerUrl' => $this->ctx->urls->route('/topology', array_filter(['center' => '__ID__', 'depth' => (string) $params['depth'], 'layout' => $params['layout'] === 'auto' ? '' : $params['layout']])),
            'objectNames' => $inventory->objects->names(),
            'allTags' => $inventory->tags->all(),
            'center' => $params['center'] !== '' ? $inventory->objects->find($params['center']) : null,
        ]);
    }

    /**
     * Karta uzlu pro boční panel: stav, kontroly, alerty, události, dokumentace.
     */
    private function node(string $id): Response
    {
        $inventory = new Inventory($this->ctx->pdo);
        $object = $inventory->objects->find($id);
        if ($object === null) {
            return $this->ctx->notFound();
        }
        $paused = $this->ctx->pausedObjects();
        $checks = (new CheckRepository($this->ctx->pdo))->forObject($id);
        $alerts = new AlertRepository($this->ctx->pdo);
        $docs = $inventory->docs->current($id);
        $timeline = $inventory->timeline($id, 5);
        $related = [];
        foreach ($timeline as $event) {
            array_push($related, ...$event['object_ids']);
        }

        return JsonFragment::response($this->ctx, $this->ctx->view->render('partials/topology_node', [
            'host' => \Nordvarr\Agent\HostView::forObject($this->ctx->pdo, $id, $checks, false),
            'object' => $object,
            'status' => ObjectStatus::forObject($object, $checks, $paused),
            'checks' => $checks,
            'paused' => $paused,
            'openAlerts' => $alerts->openForObject($id),
            'incidents' => $alerts->forObject($id, Time::toDb(Time::now()->modify('-90 days')), 5),
            'incidentCount' => $alerts->countForObject($id, Time::toDb(Time::now()->modify('-90 days'))),
            'timeline' => $timeline,
            'timelineObjects' => $inventory->objects->findMany(array_values(array_unique($related))),
            'docsHtml' => $docs === null ? null : (new Markdown(2))->render((string) $docs['markdown']),
            'tags' => $inventory->tags->forObject($id),
        ]));
    }
}
