<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Suggestions;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\CheckResultRepository;
use Nordvarr\Repository\ObjectRepository;
use Nordvarr\Time;

/**
 * Správa kontrol objektu a detail kontroly s historií.
 */
final class CheckController
{
    private CheckRepository $checks;

    public function __construct(private readonly Ctx $ctx)
    {
        $this->checks = new CheckRepository($ctx->pdo);
    }

    public function register(Router $router): void
    {
        $router->get('/objects/{id}/checks/new', fn (array $p): Response => $this->withObject($p['id'], fn (array $o): Response => $this->newForm($o)));
        $router->post('/objects/{id}/checks', fn (array $p): Response => $this->withObject($p['id'], fn (array $o): Response => $this->create($o)));
        $router->post('/objects/{id}/checks/suggested', fn (array $p): Response => $this->withObject($p['id'], fn (array $o): Response => $this->addSuggested($o)));
        $router->get('/checks/{id}', fn (array $p): Response => $this->withCheck($p['id'], fn (array $c): Response => $this->show($c)));
        $router->get('/checks/{id}/edit', fn (array $p): Response => $this->withCheck($p['id'], fn (array $c): Response => $this->form($c, null, $c['type'], self::formValues($c), [])));
        $router->post('/checks/{id}/edit', fn (array $p): Response => $this->withCheck($p['id'], fn (array $c): Response => $this->update($c)));
        $router->post('/checks/{id}/delete', fn (array $p): Response => $this->withCheck($p['id'], fn (array $c): Response => $this->delete($c)));
        $router->post('/checks/{id}/toggle', fn (array $p): Response => $this->withCheck($p['id'], fn (array $c): Response => $this->toggle($c)));
        $router->post('/checks/{id}/run', fn (array $p): Response => $this->withCheck($p['id'], fn (array $c): Response => $this->runNow($c)));
    }

    /**
     * @param callable(array<string, mixed>): Response $action
     */
    private function withObject(string $id, callable $action): Response
    {
        $object = (new ObjectRepository($this->ctx->pdo))->find($id);

        return $object === null ? $this->ctx->notFound() : $action($object);
    }

    /**
     * @param callable(array<string, mixed>): Response $action
     */
    private function withCheck(string $id, callable $action): Response
    {
        $check = $this->checks->find($id);

        return $check === null ? $this->ctx->notFound() : $action($check);
    }

    /**
     * Hodnoty formuláře z uložené kontroly.
     *
     * @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    private static function formValues(array $check): array
    {
        return [
            'name' => $check['name'],
            'interval_s' => $check['interval_s'],
            'timeout_s' => $check['timeout_s'],
            'failure_threshold' => $check['failure_threshold'],
            'enabled' => $check['enabled'],
            'notify_on_degraded' => $check['notify_on_degraded'],
            'cfg' => [$check['type'] => $check['config']],
        ];
    }

    /**
     * @param array<string, mixed> $object
     */
    private function newForm(array $object): Response
    {
        $type = $this->ctx->request->queryString('type', 'http');
        $type = in_array($type, CheckTypes::MANUAL_TYPES, true) ? $type : 'http';
        $values = CheckTypes::defaults($type) + ['name' => '', 'enabled' => true, 'cfg' => []];
        foreach (CheckTypes::TYPES as $t) {
            $values['cfg'][$t] = CheckTypes::defaultConfig($t);
        }
        // Při změně typu ve formuláři doplní výchozí interval a práh podle typu skript (data atributy).
        return $this->form(null, $object, $type, $values, []);
    }

    /**
     * @param array<string, mixed>|null $check
     * @param array<string, mixed>|null $object
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(?array $check, ?array $object, string $type, array $values, array $errors): Response
    {
        foreach (CheckTypes::TYPES as $t) {
            $values['cfg'][$t] = ($values['cfg'][$t] ?? []) + CheckTypes::defaultConfig($t);
        }

        return $this->ctx->page('check_form', [
            'title' => $check === null ? 'check.new' : 'check.edit',
            'check' => $check,
            'object' => $object ?? ['id' => $check['object_id'], 'name' => $check['object_name']],
            'type' => $type,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * @return array{type: string, input: array<string, mixed>, values: array<string, mixed>}
     */
    private function readForm(string $type): array
    {
        $post = $this->ctx->request->post;
        $cfg = is_array($post['cfg'] ?? null) && is_array($post['cfg'][$type] ?? null) ? $post['cfg'][$type] : [];
        $cfg = array_filter($cfg, 'is_string');
        $common = [];
        foreach (['name', 'interval_s', 'timeout_s', 'failure_threshold', 'enabled', 'notify_on_degraded'] as $key) {
            $common[$key] = is_string($post[$key] ?? null) ? $post[$key] : '';
        }
        $values = $common;
        $values['cfg'] = is_array($post['cfg'] ?? null) ? array_map(static fn ($c): array => is_array($c) ? array_filter($c, 'is_string') : [], $post['cfg']) : [];
        $values['enabled'] = $common['enabled'] === '1';
        $values['notify_on_degraded'] = $common['notify_on_degraded'] === '1';

        return ['type' => $type, 'input' => $common + $cfg, 'values' => $values];
    }

    /**
     * @param array<string, mixed> $object
     */
    private function create(array $object): Response
    {
        $type = $this->ctx->request->input('type');
        $form = $this->readForm($type);
        // Kontrolu kontejneru zakládá jen agent.
        $valid = in_array($type, CheckTypes::MANUAL_TYPES, true)
            ? CheckTypes::validate($type, $form['input'])
            : ['common' => [], 'config' => [], 'errors' => ['type' => 'invalid_check_type']];
        if ($valid['errors'] !== []) {
            return $this->form(null, $object, in_array($type, CheckTypes::MANUAL_TYPES, true) ? $type : 'http', $form['values'], $valid['errors']);
        }
        $id = $this->checks->create((string) $object['id'], $type, $valid['common'], $valid['config']);
        $this->ctx->audit('check.create', ['id' => $id, 'object_id' => $object['id'], 'type' => $type]);
        $this->ctx->flash('check.created');

        return $this->ctx->redirect('/checks/' . $id);
    }

    /**
     * Založí vybrané doporučené kontroly objektu.
     *
     * @param array<string, mixed> $object
     */
    private function addSuggested(array $object): Response
    {
        $keys = $this->ctx->request->post['keys'] ?? [];
        $keys = is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
        $created = $keys === [] ? [] : Suggestions::apply($this->ctx->pdo, (string) $object['id'], $keys);
        foreach ($created as $c) {
            $this->ctx->audit('check.create', ['id' => $c['id'], 'object_id' => $object['id'], 'type' => $c['type'], 'suggested' => true]);
        }
        $this->ctx->flash('suggest.added', ['count' => count($created)]);
        $response = $this->ctx->redirect('/objects/' . $object['id']);
        $response->headers['Location'] .= '#checks';

        return $response;
    }

    /**
     * @param array<string, mixed> $check
     */
    private function update(array $check): Response
    {
        $type = (string) $check['type'];
        $form = $this->readForm($type);
        $valid = CheckTypes::validate($type, $form['input']);
        if ($valid['errors'] !== []) {
            return $this->form($check, null, $type, $form['values'], $valid['errors']);
        }
        $this->checks->update((string) $check['id'], $valid['common'], $valid['config']);
        $this->ctx->audit('check.update', ['id' => $check['id'], 'type' => $type]);
        $this->ctx->flash('check.saved');

        return $this->ctx->redirect('/checks/' . $check['id']);
    }

    /**
     * @param array<string, mixed> $check
     */
    private function delete(array $check): Response
    {
        if ($this->ctx->request->input('confirm') !== '1') {
            $this->ctx->flash('check.delete_confirm_missing');

            return $this->ctx->redirect('/checks/' . $check['id']);
        }
        $this->checks->delete((string) $check['id']);
        $this->ctx->audit('check.delete', ['id' => $check['id'], 'object_id' => $check['object_id'], 'type' => $check['type'],
            'target' => CheckTypes::target((string) $check['type'], $check['config'])]);
        $this->ctx->flash('check.deleted');

        return $this->ctx->redirect('/objects/' . $check['object_id']);
    }

    /**
     * @param array<string, mixed> $check
     */
    private function toggle(array $check): Response
    {
        $this->checks->setEnabled((string) $check['id'], !$check['enabled']);
        $this->ctx->audit($check['enabled'] ? 'check.disable' : 'check.enable', ['id' => $check['id']]);
        $this->ctx->flash($check['enabled'] ? 'check.disabled' : 'check.enabled');

        return $this->ctx->redirect('/checks/' . $check['id']);
    }

    /**
     * @param array<string, mixed> $check
     */
    private function runNow(array $check): Response
    {
        $this->checks->scheduleNow((string) $check['id']);
        $this->ctx->flash('check.scheduled');

        return $this->ctx->redirect('/checks/' . $check['id']);
    }

    /**
     * @param array<string, mixed> $check
     */
    private function show(array $check): Response
    {
        $results = new CheckResultRepository($this->ctx->pdo);
        $heartbeatUrl = null;
        if ($check['type'] === 'heartbeat' && $check['heartbeat_token'] !== null) {
            $host = (string) $this->ctx->request->header('Host');
            $prefix = $host !== '' && preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host) === 1
                ? ($this->ctx->request->https ? 'https://' : 'http://') . $host : '';
            $heartbeatUrl = $prefix . $this->ctx->urls->route('/hb/' . $check['heartbeat_token']);
        }

        return $this->ctx->page('check', [
            'title' => 'check.detail',
            'check' => $check,
            'status' => ObjectStatus::checkStatus($check, $this->ctx->pausedObjects()),
            'results' => $results->recent((string) $check['id'], 50),
            'hourly' => $results->hourly((string) $check['id'], Time::now()->modify('-48 hours')->format('Y-m-d\TH:00:00\Z')),
            'summary' => $results->summary((string) $check['id'], Time::now()->modify('-24 hours')->format('Y-m-d\TH:00:00\Z')),
            'heartbeatUrl' => $heartbeatUrl,
        ]);
    }
}
