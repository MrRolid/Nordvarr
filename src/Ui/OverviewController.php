<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Status;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\EventRepository;
use Nordvarr\Repository\ObjectRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Time;

/**
 * Přehled: co nefunguje, změny za 24 h, co brzy vyprší, souhrn stavů a varování
 * o neběžícím workeru. Obnovuje se dotazem na JSON s ETag.
 */
final class OverviewController
{
    public const WORKER_STALE_SECONDS = 180;
    public const EXPIRY_DAYS = 30;

    public function __construct(private readonly Ctx $ctx)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/', fn (): Response => $this->ctx->page('dashboard', [
            'title' => 'nav.overview',
            'overviewHtml' => $this->ctx->view->render('partials/overview', $this->data()),
        ]));
        $router->get('/overview/data', fn (): Response => $this->json());
    }

    private function json(): Response
    {
        $html = $this->ctx->view->render('partials/overview', $this->data());
        $etag = '"' . sha1($html) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-cache, private'];
        $sent = (string) $this->ctx->request->header('If-None-Match');
        if ($sent !== '' && in_array($etag, array_map('trim', explode(',', $sent)), true)) {
            return new Response('', 304, $headers);
        }
        $body = json_encode(['html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($body, 200, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $pdo = $this->ctx->pdo;
        $now = Time::now();
        $checks = (new CheckRepository($pdo))->all();
        $objects = (new ObjectRepository($pdo))->search(['lifecycle' => 'active'], 100000);
        $paused = $this->ctx->pausedObjects();
        $statuses = ObjectStatus::forObjects($checks, $paused);

        $summary = array_fill_keys([Status::UNHEALTHY, Status::DEGRADED, Status::UNKNOWN, Status::HEALTHY, Status::PAUSED, Status::NONE], 0);
        foreach ($objects as $object) {
            $summary[$statuses[(string) $object['id']]['status'] ?? Status::NONE]++;
        }

        $failing = array_values(array_filter($checks, static fn (array $c): bool => in_array(
            ObjectStatus::checkStatus($c, $paused),
            [Status::UNHEALTHY, Status::DEGRADED],
            true
        )));
        usort($failing, static fn (array $a, array $b): int => [Status::severity((string) $b['status']), $a['since']]
            <=> [Status::severity((string) $a['status']), $b['since']]);

        $limit = Time::toDb($now->modify('+' . self::EXPIRY_DAYS . ' days'));
        $expiring = array_values(array_filter($checks, static fn (array $c): bool => in_array($c['type'], ['tls_expiry', 'domain_expiry'], true)
            && ObjectStatus::checkStatus($c, $paused) !== Status::PAUSED && $c['expires_at'] !== null && strcmp((string) $c['expires_at'], $limit) <= 0));
        usort($expiring, static fn (array $a, array $b): int => strcmp((string) $a['expires_at'], (string) $b['expires_at']));

        $events = (new EventRepository($pdo))->recent(Time::toDb($now->modify('-24 hours')), 50);
        $eventObjectIds = [];
        foreach ($events as $event) {
            array_push($eventObjectIds, ...$event['object_ids']);
        }

        $lastRun = (new SettingsRepository($pdo))->get('worker_last_run');
        $workerStale = $lastRun === null || $now->getTimestamp() - Time::fromDb($lastRun)->getTimestamp() > self::WORKER_STALE_SECONDS;

        return [
            'summary' => $summary,
            'paused' => $paused,
            'openAlerts' => array_column((new AlertRepository($pdo))->openAlerts(), null, 'check_id'),
            'failing' => $failing,
            'expiring' => $expiring,
            'events' => $events,
            'eventObjects' => (new ObjectRepository($pdo))->findMany(array_values(array_unique($eventObjectIds))),
            'workerLastRun' => $lastRun,
            'workerStale' => $workerStale,
            'hasChecks' => $checks !== [],
        ];
    }
}
