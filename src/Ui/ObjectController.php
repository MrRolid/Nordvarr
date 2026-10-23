<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Status;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Time;
use Nordvarr\View\Markdown;

/**
 * Detail objektu (diagnostická karta v podobě F1) a jeho úpravy.
 */
final class ObjectController
{
    private Inventory $inventory;

    public function __construct(private readonly Ctx $ctx)
    {
        $this->inventory = new Inventory($ctx->pdo);
    }

    public function register(Router $router): void
    {
        $router->get('/objects/new', fn (): Response => $this->form(null, $this->emptyForm(), []));
        $router->post('/objects', fn (): Response => $this->create());
        $router->get('/objects/{id}', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->show($o)));
        $router->get('/objects/{id}/diagnosis', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->diagnosisJson($o)));
        $router->get('/objects/{id}/edit', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->form($o, $o, [])));
        $router->post('/objects/{id}/edit', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->update($o)));
        $router->post('/objects/{id}/lifecycle', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->lifecycle($o)));
        $router->post('/objects/{id}/delete', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->delete($o)));
        $router->post('/objects/{id}/attributes', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->setAttribute($o)));
        $router->post('/objects/{id}/attributes/delete', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->deleteAttribute($o)));
        $router->post('/objects/{id}/tags', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->addTag($o)));
        $router->post('/objects/{id}/tags/delete', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->removeTag($o)));
        $router->post('/objects/{id}/relationships', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->addRelationship($o)));
        $router->post('/objects/{id}/relationships/{rid}/delete', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->deleteRelationship($o, $p['rid'])));
        $router->get('/objects/{id}/docs', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->docsForm($o)));
        $router->post('/objects/{id}/docs', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->saveDocs($o)));
        $router->get('/objects/{id}/docs/revisions/{rid}', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->revision($o, $p['rid'])));
        $router->post('/objects/{id}/events', fn (array $p): Response => $this->withObject($p, fn (array $o): Response => $this->addEvent($o)));
    }

    /**
     * @param array<string, string> $params
     * @param callable(array<string, mixed>): Response $action
     */
    private function withObject(array $params, callable $action): Response
    {
        $object = $this->inventory->objects->find($params['id'] ?? '');

        return $object === null ? $this->ctx->notFound() : $action($object);
    }

    /**
     * @return array<string, string>
     */
    private function emptyForm(): array
    {
        return ['type' => 'server', 'name' => '', 'description' => ''];
    }

    /**
     * @param array<string, mixed>|null $object
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(?array $object, array $values, array $errors): Response
    {
        return $this->ctx->page('object_form', [
            'title' => $object === null ? 'object.new' : 'object.edit',
            'object' => $object,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array<string, string>
     */
    private function formValues(): array
    {
        $r = $this->ctx->request;

        return [
            'type' => $r->input('type'),
            'name' => trim($r->input('name')),
            'description' => trim(str_replace("\r\n", "\n", $r->input('description'))),
        ];
    }

    private function create(): Response
    {
        $values = $this->formValues();
        $errors = Inventory::validateObject($values);
        if ($errors !== []) {
            return $this->form(null, $values, $errors);
        }
        $id = $this->inventory->objects->create($values);
        $this->ctx->flash('object.created');

        return $this->ctx->redirect('/objects/' . $id);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function update(array $object): Response
    {
        $values = $this->formValues();
        $errors = Inventory::validateObject($values);
        if ($errors !== []) {
            return $this->form($object, $values, $errors);
        }
        $this->inventory->objects->update((string) $object['id'], $values);
        $this->ctx->flash('object.saved');

        return $this->ctx->redirect('/objects/' . $object['id']);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function lifecycle(array $object): Response
    {
        $new = $object['lifecycle'] === 'active' ? 'archived' : 'active';
        $this->inventory->objects->update((string) $object['id'], ['lifecycle' => $new]);
        $this->ctx->audit($new === 'archived' ? 'object.archive' : 'object.restore', ['id' => $object['id'], 'name' => $object['name']]);
        $this->ctx->flash($new === 'archived' ? 'object.archived_done' : 'object.restored_done');

        return $this->ctx->redirect('/objects/' . $object['id']);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function delete(array $object): Response
    {
        if ($this->ctx->request->input('confirm') !== '1') {
            $this->ctx->flash('object.delete_confirm_missing');

            return $this->ctx->redirect('/objects/' . $object['id']);
        }
        $this->inventory->objects->delete((string) $object['id']);
        $this->ctx->audit('object.delete', ['id' => $object['id'], 'name' => $object['name'], 'type' => $object['type']]);
        $this->ctx->flash('object.deleted', ['name' => (string) $object['name']]);

        return $this->ctx->redirect('/inventory');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function show(array $object): Response
    {
        $id = (string) $object['id'];
        $inv = $this->inventory;
        $docs = $inv->docs->current($id);
        $timeline = $inv->timeline($id, 50);
        $relatedIds = [];
        foreach ($timeline as $event) {
            array_push($relatedIds, ...$event['object_ids']);
        }

        $checks = (new CheckRepository($this->ctx->pdo))->forObject($id);

        $host = \Nordvarr\Agent\HostView::forObject($this->ctx->pdo, $id, $checks, true, $this->ctx->view->t('host.chart_label'));

        return $this->ctx->page('object', $this->diagnosis($object, $checks) + [
            'host' => $host,
            'suggestions' => \Nordvarr\Checks\Suggestions::load($this->ctx->pdo, $id),
            'title' => 'object.detail',
            'object' => $object,
            'checks' => $checks,
            'paused' => $this->ctx->pausedObjects(),
            'incidents' => (new AlertRepository($this->ctx->pdo))->forObject($id, Time::toDb(Time::now()->modify('-90 days')), 20),
            'tags' => $inv->tags->forObject($id),
            'externalIds' => $inv->externalIds->forObject($id),
            'attributes' => $inv->attributes->grouped($id),
            'relationships' => $inv->relationships->forObject($id),
            'docsHtml' => $docs === null ? null : (new Markdown())->render((string) $docs['markdown']),
            'docs' => $docs,
            'revisionCount' => count($inv->docs->revisions($id)),
            'timeline' => $timeline,
            'timelineObjects' => $inv->objects->findMany(array_values(array_unique($relatedIds))),
            'objectNames' => array_diff_key($inv->objects->names(), [$id => true]),
            'nowLocal' => Time::now()->setTimezone($this->timezone())->format('Y-m-d\TH:i'),
        ]);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function setAttribute(array $object): Response
    {
        $key = trim($this->ctx->request->input('key'));
        $value = str_replace("\r\n", "\n", $this->ctx->request->input('value'));
        if (!Vocabulary::isValidAttributeKey($key)) {
            return $this->back($object, 'err.invalid_attribute_key');
        }
        if (mb_strlen($value) > Inventory::MAX_ATTRIBUTE_VALUE) {
            return $this->back($object, 'err.invalid_attribute_value');
        }
        if ($this->inventory->attributes->set((string) $object['id'], $key, $value, false, Vocabulary::MANUAL_SOURCE) !== null) {
            $this->inventory->objects->touch((string) $object['id']);
        }

        return $this->back($object, 'object.attribute_saved', '#attributes');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function deleteAttribute(array $object): Response
    {
        $key = $this->ctx->request->input('key');
        if ($this->inventory->attributes->remove((string) $object['id'], $key, Vocabulary::MANUAL_SOURCE)) {
            $this->inventory->objects->touch((string) $object['id']);
            $this->ctx->audit('attribute.delete', ['object_id' => $object['id'], 'key' => $key]);
        }

        return $this->back($object, 'object.attribute_deleted', '#attributes');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function addTag(array $object): Response
    {
        $tag = Vocabulary::normalizeTag($this->ctx->request->input('tag'));
        if ($tag === null) {
            return $this->back($object, 'err.invalid_tag');
        }
        if ($this->inventory->tags->attach((string) $object['id'], $tag)) {
            $this->inventory->objects->touch((string) $object['id']);
        }

        return $this->back($object, 'object.tag_added');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function removeTag(array $object): Response
    {
        $tag = $this->ctx->request->input('tag');
        $this->inventory->tags->detach((string) $object['id'], $tag);
        $this->inventory->objects->touch((string) $object['id']);
        $this->ctx->audit('tag.remove', ['object_id' => $object['id'], 'tag' => $tag]);

        return $this->back($object, 'object.tag_removed');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function addRelationship(array $object): Response
    {
        $r = $this->ctx->request;
        $target = $this->inventory->objects->find($r->input('target'));
        if ($target === null) {
            return $this->back($object, 'err.invalid_target', '#relationships');
        }
        [$direction, $kind] = array_pad(explode(':', $r->input('relation'), 2), 2, '');
        $incoming = $direction === 'in';
        $from = $incoming ? (string) $target['id'] : (string) $object['id'];
        $to = $incoming ? (string) $object['id'] : (string) $target['id'];
        try {
            $result = $this->inventory->relate($from, $to, $kind, trim($r->input('note')), Vocabulary::MANUAL_SOURCE);
        } catch (ValidationException $e) {
            return $this->back($object, 'err.' . $e->firstError(), '#relationships');
        }

        return $this->back($object, $result['created'] ? 'object.relationship_added' : 'object.relationship_exists', '#relationships');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function deleteRelationship(array $object, string $relationshipId): Response
    {
        $rel = $this->inventory->relationships->find($relationshipId);
        if ($rel === null || ($rel['from_id'] !== $object['id'] && $rel['to_id'] !== $object['id'])) {
            return $this->ctx->notFound();
        }
        $this->inventory->relationships->delete($relationshipId);
        $this->inventory->objects->touch((string) $rel['from_id']);
        $this->ctx->audit('relationship.delete', [
            'from_id' => $rel['from_id'], 'to_id' => $rel['to_id'], 'kind' => $rel['kind'], 'source' => $rel['source'],
        ]);

        return $this->back($object, 'object.relationship_deleted', '#relationships');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function docsForm(array $object, ?string $markdown = null, ?string $error = null): Response
    {
        $id = (string) $object['id'];
        $current = $this->inventory->docs->current($id);

        return $this->ctx->page('object_docs', [
            'title' => 'docs.edit',
            'object' => $object,
            'markdown' => $markdown ?? (string) ($current['markdown'] ?? ''),
            'error' => $error,
            'revisions' => $this->inventory->docs->revisions($id),
        ], $error === null ? 200 : 422);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function saveDocs(array $object): Response
    {
        $markdown = str_replace("\r\n", "\n", $this->ctx->request->input('markdown'));
        if (mb_strlen($markdown) > Inventory::MAX_DOC) {
            return $this->docsForm($object, $markdown, 'err.invalid_docs');
        }
        $saved = $this->inventory->docs->save((string) $object['id'], $markdown, $this->ctx->userEmail());
        if ($saved) {
            $this->inventory->objects->touch((string) $object['id']);
        }

        return $this->back($object, $saved ? 'docs.saved' : 'docs.unchanged', '#docs');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function revision(array $object, string $revisionId): Response
    {
        $revision = $this->inventory->docs->revision((string) $object['id'], $revisionId);
        if ($revision === null) {
            return $this->ctx->notFound();
        }

        return $this->ctx->page('object_revision', [
            'title' => 'docs.revision',
            'object' => $object,
            'revision' => $revision,
            'html' => (new Markdown())->render((string) $revision['markdown']),
        ]);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function addEvent(array $object): Response
    {
        $r = $this->ctx->request;
        try {
            $occurred = new DateTimeImmutable($r->input('occurred_at', 'now'), $this->timezone());
        } catch (Exception) {
            return $this->back($object, 'err.invalid_occurred_at', '#timeline');
        }
        try {
            $this->inventory->addManualEvent(
                (string) $object['id'],
                $r->input('type'),
                $r->input('summary'),
                str_replace("\r\n", "\n", $r->input('details')),
                Time::toDb($occurred),
            );
        } catch (ValidationException $e) {
            return $this->back($object, 'err.' . $e->firstError(), '#timeline');
        }

        return $this->back($object, 'event.added', '#timeline');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function back(array $object, string $flash, string $anchor = ''): Response
    {
        $this->ctx->flash($flash);
        $response = $this->ctx->redirect('/objects/' . $object['id']);
        if ($anchor !== '') {
            $response->headers['Location'] .= $anchor;
        }

        return $response;
    }

    /**
     * Data diagnostické hlavičky: stav a důvod, pravděpodobná příčina, poslední změna
     * na objektu a přímých sousedech za 24 h, stav závislostí, počet alertů za 90 dní.
     *
     * @param array<string, mixed> $object
     * @param list<array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    private function diagnosis(array $object, array $checks): array
    {
        $id = (string) $object['id'];
        $now = Time::now();
        $alerts = new AlertRepository($this->ctx->pdo);
        $changes = $this->inventory->timeline($id, 1, Time::toDb($now->modify('-24 hours')));
        $lastChange = $changes[0] ?? null;
        $changeObjects = $lastChange === null ? [] : $this->inventory->objects->findMany($lastChange['object_ids']);

        return [
            'diagObject' => $object,
            'objectStatus' => ObjectStatus::forObject($object, $checks, $this->ctx->pausedObjects()),
            'openAlerts' => $alerts->openForObject($id),
            'inMaintenance' => \Nordvarr\Alerts\MaintenanceState::load($this->ctx->pdo, Time::toDb($now))->inMaintenance($id),
            'dependencies' => $this->dependencyStatuses($id),
            'lastChange' => $lastChange,
            'lastChangeObjects' => $changeObjects,
            'alertCount90' => $alerts->countForObject($id, Time::toDb($now->modify('-90 days'))),
        ];
    }

    /**
     * Diagnostická hlavička jako JSON pro obnovování s ETag.
     *
     * @param array<string, mixed> $object
     */
    private function diagnosisJson(array $object): Response
    {
        $checks = (new CheckRepository($this->ctx->pdo))->forObject((string) $object['id']);

        return JsonFragment::response($this->ctx, $this->ctx->view->render('partials/diagnosis', $this->diagnosis($object, $checks)));
    }

    /**
     * Stav přímých závislostí (objekty, na kterých objekt závisí nebo ve kterých je obsažen).
     *
     * @return list<array{id: string, name: string, kind: string, status: string}>
     */
    private function dependencyStatuses(string $objectId): array
    {
        $relationships = $this->inventory->relationships->forObject($objectId);
        $targets = [];
        foreach ($relationships['outgoing'] as $rel) {
            if ($rel['kind'] === 'depends_on') {
                $targets[(string) $rel['other_id']] = ['kind' => 'depends_on', 'name' => (string) $rel['other_name']];
            }
        }
        foreach ($relationships['incoming'] as $rel) {
            if ($rel['kind'] === 'contains') {
                $targets[(string) $rel['other_id']] ??= ['kind' => 'contained_in', 'name' => (string) $rel['other_name']];
            }
        }
        if ($targets === []) {
            return [];
        }
        $paused = $this->ctx->pausedObjects();
        $statuses = ObjectStatus::forObjects((new CheckRepository($this->ctx->pdo))->all(), $paused);
        $objects = $this->inventory->objects->findMany(array_keys($targets));
        $result = [];
        foreach ($targets as $id => $target) {
            $status = ($objects[$id]['lifecycle'] ?? 'active') !== 'active' || isset($paused[$id]) ? Status::PAUSED : ($statuses[$id]['status'] ?? Status::NONE);
            $result[] = ['id' => (string) $id, 'name' => $target['name'], 'kind' => $target['kind'], 'status' => $status];
        }
        usort($result, static fn (array $a, array $b): int => Status::severity($b['status']) <=> Status::severity($a['status']));

        return $result;
    }

    private function timezone(): DateTimeZone
    {
        $tz = (new SettingsRepository($this->ctx->pdo))->get('timezone', 'Europe/Prague') ?? 'Europe/Prague';

        return new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');
    }
}
