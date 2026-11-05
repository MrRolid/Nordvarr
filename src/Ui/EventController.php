<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Repository\EventRepository;
use Nordvarr\Repository\ObjectRepository;
use Nordvarr\Time;

/**
 * Přehled událostí ze všech zdrojů (servery, aplikace, ruční) s barvou podle důležitosti.
 */
final class EventController
{
    /** Období filtru => počet hodin. */
    public const PERIODS = ['24h' => 24, '7d' => 168, '30d' => 720];

    public function __construct(private readonly Ctx $ctx)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/events', fn (): Response => $this->index());
    }

    private function index(): Response
    {
        $r = $this->ctx->request;
        $period = array_key_exists($r->queryString('period'), self::PERIODS) ? $r->queryString('period') : '7d';
        $severity = in_array($r->queryString('severity'), Vocabulary::EVENT_SEVERITIES, true) ? $r->queryString('severity') : 'info';
        $type = in_array($r->queryString('type'), Vocabulary::EVENT_TYPES, true) ? $r->queryString('type') : null;
        $since = Time::toDb(Time::now()->modify('-' . self::PERIODS[$period] . ' hours'));
        $repo = new EventRepository($this->ctx->pdo);
        $events = $repo->feed($since, $severity, $type);
        $objectIds = [];
        foreach ($events as $event) {
            array_push($objectIds, ...$event['object_ids']);
        }

        return $this->ctx->page('events', [
            'title' => 'nav.events',
            'events' => $events,
            'objects' => (new ObjectRepository($this->ctx->pdo))->findMany(array_values(array_unique($objectIds))),
            'counts' => $repo->countsBySeverity($since),
            'filter' => ['period' => $period, 'severity' => $severity, 'type' => $type ?? ''],
        ]);
    }
}
