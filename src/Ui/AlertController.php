<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\DeliveryRepository;
use Nordvarr\Repository\MaintenanceRepository;
use Nordvarr\Repository\ObjectRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Repository\TagRepository;
use Nordvarr\Time;

/**
 * Alerty (otevřené a uzavřené, filtr), detail alertu s historií doručení,
 * správa údržbových oken a přehled odesílání.
 */
final class AlertController
{
    public function __construct(private readonly Ctx $ctx)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/alerts', fn (): Response => $this->index());
        $router->get('/alerts/deliveries', fn (): Response => $this->deliveries());
        $router->get('/alerts/{id}', fn (array $p): Response => $this->show($p['id']));
        $router->post('/maintenance', fn (): Response => $this->createWindow());
        $router->post('/maintenance/{id}/end', fn (array $p): Response => $this->endWindow($p['id']));
        $router->post('/maintenance/{id}/delete', fn (array $p): Response => $this->deleteWindow($p['id']));
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $errors
     */
    private function index(array $form = [], array $errors = [], int $status = 200): Response
    {
        $r = $this->ctx->request;
        $filter = [
            'status' => in_array($r->queryString('status', 'open'), ['open', 'resolved', 'all'], true) ? $r->queryString('status', 'open') : 'open',
            'q' => mb_substr(trim($r->queryString('q')), 0, 100),
        ];
        $now = Time::now();
        $tz = $this->timezone();

        return $this->ctx->page('alerts', [
            'title' => 'nav.alerts',
            'filter' => $filter,
            'alerts' => (new AlertRepository($this->ctx->pdo))->search($filter['status'] === 'all' ? '' : $filter['status'], $filter['q']),
            'windows' => (new MaintenanceRepository($this->ctx->pdo))->listSince(Time::toDb($now->modify('-7 days'))),
            'now' => Time::toDb($now),
            'objectNames' => (new ObjectRepository($this->ctx->pdo))->names(),
            'allTags' => (new TagRepository($this->ctx->pdo))->all(),
            'form' => $form + [
                'scope' => 'object',
                'object_id' => '',
                'tag' => '',
                'starts_at' => $now->setTimezone($tz)->format('Y-m-d\TH:i'),
                'ends_at' => $now->modify('+1 hour')->setTimezone($tz)->format('Y-m-d\TH:i'),
                'pause_checks' => false,
                'note' => '',
            ],
            'errors' => $errors,
        ], $status);
    }

    private function show(string $id): Response
    {
        $alert = (new AlertRepository($this->ctx->pdo))->find($id);
        if ($alert === null) {
            return $this->ctx->notFound();
        }
        $deliveries = new DeliveryRepository($this->ctx->pdo);
        $list = $deliveries->forAlert($id);
        $attempts = [];
        foreach ($list as $d) {
            $attempts[(string) $d['id']] = $deliveries->attempts((string) $d['id']);
        }

        return $this->ctx->page('alert', [
            'title' => 'alert.detail',
            'alert' => $alert,
            'deliveries' => $list,
            'attempts' => $attempts,
        ]);
    }

    private function deliveries(): Response
    {
        $repo = new DeliveryRepository($this->ctx->pdo);
        $list = $repo->recent(null, 100);
        $attempts = [];
        foreach ($list as $d) {
            $attempts[(string) $d['id']] = $repo->attempts((string) $d['id']);
        }

        return $this->ctx->page('deliveries', ['title' => 'delivery.title', 'deliveries' => $list, 'attempts' => $attempts]);
    }

    private function createWindow(): Response
    {
        $r = $this->ctx->request;
        $form = [
            'scope' => $r->input('scope') === 'tag' ? 'tag' : 'object',
            'object_id' => $r->input('object_id'),
            'tag' => $r->input('tag'),
            'starts_at' => $r->input('starts_at'),
            'ends_at' => $r->input('ends_at'),
            'pause_checks' => $r->input('pause_checks') === '1',
            'note' => trim($r->input('note')),
        ];
        $errors = [];
        $objectId = null;
        $tag = null;
        if ($form['scope'] === 'object') {
            if ((new ObjectRepository($this->ctx->pdo))->find($form['object_id']) === null) {
                $errors['object_id'] = 'invalid_target';
            }
            $objectId = $form['object_id'];
        } else {
            $tag = Vocabulary::normalizeTag($form['tag']);
            if ($tag === null) {
                $errors['tag'] = 'invalid_tag';
            }
        }
        $starts = $this->parseLocal($form['starts_at']);
        $ends = $this->parseLocal($form['ends_at']);
        if ($starts === null || $ends === null || $ends <= $starts) {
            $errors['ends_at'] = 'invalid_window_time';
        }
        if (mb_strlen($form['note']) > 500) {
            $errors['note'] = 'invalid_note';
        }
        if ($errors !== []) {
            return $this->index($form, $errors, 422);
        }
        $id = (new MaintenanceRepository($this->ctx->pdo))->create(
            $objectId,
            $tag,
            Time::toDb($starts),
            Time::toDb($ends),
            $form['pause_checks'],
            $form['note'],
            $this->ctx->userId(),
        );
        $this->ctx->audit('maintenance.create', ['id' => $id, 'object_id' => $objectId, 'tag' => $tag,
            'starts_at' => Time::toDb($starts), 'ends_at' => Time::toDb($ends), 'pause_checks' => $form['pause_checks']]);
        $this->ctx->flash('maintenance.created');

        return $this->ctx->redirect('/alerts', ['status' => 'open']);
    }

    private function endWindow(string $id): Response
    {
        $repo = new MaintenanceRepository($this->ctx->pdo);
        if ($repo->find($id) === null) {
            return $this->ctx->notFound();
        }
        $repo->end($id, Time::toDb(Time::now()));
        $this->ctx->audit('maintenance.end', ['id' => $id]);
        $this->ctx->flash('maintenance.ended');

        return $this->ctx->redirect('/alerts');
    }

    private function deleteWindow(string $id): Response
    {
        $repo = new MaintenanceRepository($this->ctx->pdo);
        $window = $repo->find($id);
        if ($window === null) {
            return $this->ctx->notFound();
        }
        $repo->delete($id);
        $this->ctx->audit('maintenance.delete', ['id' => $id, 'object_id' => $window['object_id'], 'tag' => $window['tag']]);
        $this->ctx->flash('maintenance.deleted');

        return $this->ctx->redirect('/alerts');
    }

    private function parseLocal(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, $this->timezone());
        } catch (Exception) {
            return null;
        }
    }

    private function timezone(): DateTimeZone
    {
        $tz = (new SettingsRepository($this->ctx->pdo))->get('timezone', 'Europe/Prague') ?? 'Europe/Prague';

        return new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');
    }
}
