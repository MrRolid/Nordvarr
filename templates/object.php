<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $object */
/** @var list<string> $tags */
/** @var list<array{source: string, external_id: string}> $externalIds */
/** @var list<array{key: string, values: list<array<string, mixed>>, conflict: bool, valid: ?string}> $attributes */
/** @var array{outgoing: list<array<string, mixed>>, incoming: list<array<string, mixed>>} $relationships */
/** @var array<string, array{html: string, updated_at: string, updated_by: string, revisions: int}> $docs */
/** @var list<array<string, mixed>> $timeline */
/** @var array<string, array<string, mixed>> $timelineObjects */
/** @var array<string, string> $objectNames */
/** @var string $nowLocal */
/** @var list<array<string, mixed>> $checks */
/** @var array{status: string, check: array<string, mixed>|null} $objectStatus */
/** @var list<array{id: string, name: string, kind: string, status: string}> $dependencies */
$id = (string) $object['id'];
/** @var array<string, true> $paused */
/** @var list<array<string, mixed>> $openAlerts */
/** @var bool $inMaintenance */
/** @var list<array<string, mixed>> $incidents */
/** @var array<string, mixed> $diagObject */
/** @var array<string, mixed>|null $lastChange */
/** @var array<string, array<string, mixed>> $lastChangeObjects */
/** @var int $alertCount90 */
/** @var array<string, mixed>|null $host */
/** @var list<array{key: string, type: string, input: array<string, string>, target: string, via: string}> $suggestions */
?>
<header class="object-head card" data-test="object-head">
    <p class="muted"><?= $v->t('type.' . $object['type']) ?><?php if ($object['lifecycle'] === 'archived'): ?> <span class="badge"><?= $v->t('lifecycle.archived') ?></span><?php endif; ?></p>
    <h1 data-test="object-name"><?= $v->e($object['name']) ?></h1>
    <?php
    // Proklik na web: adresa první kontroly HTTP, jinak platný atribut url/adresa.
    $webUrl = '';
    foreach ($checks as $c) {
        if ($c['type'] === 'http') {
            $webUrl = (string) $c['config']['url'];
            break;
        }
    }
    foreach ($attributes as $group) {
        if ($webUrl === '' && in_array(strtolower((string) $group['key']), ['url', 'adresa'], true) && $group['valid'] !== null) {
            $webUrl = (string) $group['valid'];
        }
    }
    ?>
    <?php if ($webUrl !== '' && $v->webLink($webUrl) !== $v->e($webUrl)): ?><p class="object-web" data-test="object-web"><?= $v->raw($v->webLink($webUrl)) ?></p><?php endif; ?>
    <div data-refresh-url="<?= $v->url('/objects/' . $id . '/diagnosis') ?>" id="diagnosis">
        <?= $v->raw($v->render('partials/diagnosis', compact('diagObject', 'objectStatus', 'openAlerts', 'inMaintenance', 'dependencies', 'lastChange', 'lastChangeObjects', 'alertCount90'))) ?>
    </div>
    <?php if ((string) $object['description'] !== ''): ?>
    <p><?= $v->e($object['description']) ?></p>
    <?php endif; ?>
    <div class="tags" data-test="object-tags">
        <?php foreach ($tags as $tag): ?>
        <form method="post" action="<?= $v->url('/objects/' . $id . '/tags/delete') ?>" class="inline tag-chip">
            <?= $v->csrfField() ?>
            <input type="hidden" name="tag" value="<?= $v->e($tag) ?>">
            <a class="tag" href="<?= $v->url('/inventory', ['tag' => $tag]) ?>"><?= $v->e($tag) ?></a>
            <button type="submit" class="link small" aria-label="<?= $v->t('object.tag_remove', ['tag' => $tag]) ?>">x</button>
        </form>
        <?php endforeach; ?>
        <form method="post" action="<?= $v->url('/objects/' . $id . '/tags') ?>" class="inline">
            <?= $v->csrfField() ?>
            <label for="new-tag" class="visually-hidden"><?= $v->t('object.tag_new') ?></label>
            <input id="new-tag" name="tag" type="text" maxlength="50" placeholder="<?= $v->t('object.tag_new') ?>" class="small-input">
            <button type="submit" class="small"><?= $v->t('object.tag_add') ?></button>
        </form>
    </div>
    <?php if ($externalIds !== []): ?>
    <p class="muted small-text"><?= $v->t('object.external_ids') ?>:
        <?php foreach ($externalIds as $x): ?><code><?= $v->e($x['source'] . ':' . $x['external_id']) ?></code> <?php endforeach; ?>
    </p>
    <?php endif; ?>
    <div class="row">
        <a class="button primary" href="<?= $v->url('/topology', ['center' => $id]) ?>" data-test="show-topology"><?= $v->t('topology.show') ?></a>
        <a class="button" href="<?= $v->url('/objects/' . $id . '/llm') ?>" data-test="llm-export"><?= $v->t('llm.button') ?></a>
        <a class="button" href="<?= $v->url('/objects/' . $id . '/edit') ?>"><?= $v->t('object.edit') ?></a>
        <form method="post" action="<?= $v->url('/objects/' . $id . '/lifecycle') ?>" class="inline">
            <?= $v->csrfField() ?>
            <button type="submit"><?= $v->t($object['lifecycle'] === 'active' ? 'object.archive' : 'object.restore') ?></button>
        </form>
    </div>
</header>

<?php if ($host !== null): ?>
<?= $v->raw($v->render('partials/host', ['host' => $host])) ?>
<?php endif; ?>

<section class="card" id="checks">
    <div class="page-head">
        <h2><?= $v->t('object.checks') ?></h2>
        <a class="button" href="<?= $v->url('/objects/' . $id . '/checks/new') ?>" data-test="add-check"><?= $v->t('check.add') ?></a>
    </div>
    <?php if ($suggestions !== []): ?>
    <form method="post" action="<?= $v->url('/objects/' . $id . '/checks/suggested') ?>" class="suggestions" data-test="suggestions">
        <?= $v->csrfField() ?>
        <p><strong><?= $v->t('suggest.title') ?></strong> <span class="muted small-text"><?= $v->t('suggest.help') ?></span></p>
        <ul class="plain">
            <?php foreach ($suggestions as $i => $s): ?>
            <li><label class="check-label"><input type="checkbox" name="keys[]" value="<?= $v->e($s['key']) ?>" checked>
                <?= $v->t('checktype.' . $s['type']) ?> <code><?= $v->e($s['target']) ?></code><?php if ($s['via'] === 'domain'): ?> <span class="muted small-text"><?= $v->t('suggest.via_domain') ?></span><?php endif; ?></label></li>
            <?php endforeach; ?>
        </ul>
        <button type="submit" class="primary" data-test="add-suggested"><?= $v->t('suggest.add') ?></button>
    </form>
    <?php endif; ?>
    <?php if ($host === null && in_array($object['type'], ['server', 'vm'], true)): ?>
    <p class="muted small-text" data-test="agent-hint"><?= $v->t('suggest.agent_hint') ?> <a href="<?= $v->url('/settings/agent') ?>"><?= $v->t('settings.agent_guide') ?></a></p>
    <?php endif; ?>
    <?php if ($checks === []): ?>
    <p class="muted"><?= $v->t('check.none') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="checks">
        <thead><tr><th><?= $v->t('check.result') ?></th><th><?= $v->t('check.type') ?></th><th><?= $v->t('check.target') ?></th><th><?= $v->t('check.reason') ?></th><th><?= $v->t('check.last_run') ?></th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
        <?php $cs = \Nordvarr\Checks\ObjectStatus::checkStatus($c, $paused); ?>
        <tr>
            <td><?= $v->raw($v->render('partials/status', ['status' => $cs])) ?></td>
            <td><a href="<?= $v->url('/checks/' . $c['id']) ?>"><?= $v->t('checktype.' . $c['type']) ?><?= (string) $c['name'] !== '' ? ': ' . $v->e($c['name']) : '' ?></a></td>
            <td class="value"><code><?= $c['type'] === 'http' ? $v->raw($v->webLink((string) $c['config']['url'], \Nordvarr\Checks\CheckTypes::target('http', $c['config']))) : $v->e(\Nordvarr\Checks\CheckTypes::target((string) $c['type'], $c['config'])) ?></code></td>
            <td><?php if (!in_array($cs, ['healthy', 'paused'], true) && $c['reason_code'] !== null): ?><code><?= $v->e($c['reason_code']) ?></code>, <?= $v->ago((string) $c['since']) ?><?php endif; ?></td>
            <td class="nowrap"><?= $c['last_run_at'] !== null ? $v->ago((string) $c['last_run_at']) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="card" id="attributes">
    <h2><?= $v->t('object.attributes') ?></h2>
    <?php if ($attributes === []): ?>
    <p class="muted"><?= $v->t('object.no_attributes') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="attributes">
        <thead><tr><th><?= $v->t('object.attr_key') ?></th><th><?= $v->t('object.attr_value') ?></th><th><?= $v->t('object.attr_source') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($attributes as $group): ?>
            <?php foreach ($group['values'] as $i => $attr): ?>
            <?php $isValid = $group['conflict'] && $attr['source'] === 'manual'; ?>
            <tr class="<?= $group['conflict'] ? 'conflict' : '' ?>">
                <?php if ($i === 0): ?>
                <th scope="row" rowspan="<?= $v->e(count($group['values'])) ?>"><?= $v->e($group['key']) ?>
                    <?php if ($group['conflict']): ?><span class="badge warn" data-test="conflict"><?= $v->t('object.conflict') ?></span><?php endif; ?></th>
                <?php endif; ?>
                <td class="value"><?= $v->raw($v->webLink((string) $attr['value'])) ?><?php if ($isValid): ?> <span class="badge ok"><?= $v->t('object.valid_value') ?></span><?php endif; ?></td>
                <td><?= $v->e($attr['source']) ?></td>
                <td>
                    <?php if ($attr['source'] === 'manual'): ?>
                    <form method="post" action="<?= $v->url('/objects/' . $id . '/attributes/delete') ?>" class="inline">
                        <?= $v->csrfField() ?>
                        <input type="hidden" name="key" value="<?= $v->e($attr['key']) ?>">
                        <button type="submit" class="link small"><?= $v->t('common.delete') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= $v->url('/objects/' . $id . '/attributes') ?>" class="inline-form" data-test="attribute-form">
        <?= $v->csrfField() ?>
        <label for="attr-key"><?= $v->t('object.attr_key') ?></label>
        <input id="attr-key" name="key" type="text" maxlength="100" required>
        <label for="attr-value"><?= $v->t('object.attr_value') ?></label>
        <input id="attr-value" name="value" type="text">
        <button type="submit"><?= $v->t('object.attr_set') ?></button>
    </form>
    <p class="help"><?= $v->t('object.attr_help') ?></p>
</section>

<section class="card" id="relationships">
    <h2><?= $v->t('object.relationships') ?></h2>
    <?php $all = [];
    foreach ($relationships['outgoing'] as $rel) { $all[] = ['dir' => 'out', 'rel' => $rel]; }
    foreach ($relationships['incoming'] as $rel) { $all[] = ['dir' => 'in', 'rel' => $rel]; } ?>
    <?php if ($all === []): ?>
    <p class="muted"><?= $v->t('object.no_relationships') ?></p>
    <?php else: ?>
    <ul class="relations" data-test="relationships">
        <?php foreach ($all as ['dir' => $dir, 'rel' => $rel]): ?>
        <li>
            <span class="rel-kind"><?= $v->t('rel.' . $dir . '.' . $rel['kind']) ?></span>
            <a href="<?= $v->url('/objects/' . $rel['other_id']) ?>"><?= $v->e($rel['other_name']) ?></a>
            <span class="muted">(<?= $v->t('type.' . $rel['other_type']) ?><?= $rel['source'] !== 'manual' ? ', ' . $v->e($rel['source']) : '' ?>)</span>
            <?php if ((string) $rel['note'] !== ''): ?><span class="muted"> - <?= $v->e($rel['note']) ?></span><?php endif; ?>
            <form method="post" action="<?= $v->url('/objects/' . $id . '/relationships/' . $rel['id'] . '/delete') ?>" class="inline">
                <?= $v->csrfField() ?>
                <button type="submit" class="link small"><?= $v->t('common.delete') ?></button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($objectNames !== []): ?>
    <form method="post" action="<?= $v->url('/objects/' . $id . '/relationships') ?>" class="inline-form" data-test="relationship-form">
        <?= $v->csrfField() ?>
        <label for="rel-type"><?= $v->t('object.rel_type') ?></label>
        <select id="rel-type" name="relation">
            <option value="out:depends_on"><?= $v->t('rel.form.out.depends_on') ?></option>
            <option value="in:depends_on"><?= $v->t('rel.form.in.depends_on') ?></option>
            <option value="out:contains"><?= $v->t('rel.form.out.contains') ?></option>
            <option value="in:contains"><?= $v->t('rel.form.in.contains') ?></option>
        </select>
        <label for="rel-target"><?= $v->t('object.rel_target') ?></label>
        <select id="rel-target" name="target">
            <?php foreach ($objectNames as $oid => $name): ?>
            <option value="<?= $v->e($oid) ?>"><?= $v->e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="rel-note"><?= $v->t('object.rel_note') ?></label>
        <input id="rel-note" name="note" type="text" maxlength="500">
        <button type="submit"><?= $v->t('object.rel_add') ?></button>
    </form>
    <?php endif; ?>
</section>

<section class="card" id="docs">
    <div class="page-head">
        <h2><?= $v->t('object.docs') ?></h2>
        <a class="button" href="<?= $v->url('/objects/' . $id . '/docs') ?>" data-test="edit-docs"><?= $v->t('docs.edit') ?></a>
    </div>
    <?php if ($docs === []): ?>
    <p class="muted"><?= $v->t('object.no_docs') ?></p>
    <?php endif; ?>
    <?php foreach ($docs as $cat => $doc): ?>
    <div class="doc-cat" id="docs-<?= $v->e($cat) ?>" data-test="docs-<?= $v->e($cat) ?>">
        <div class="doc-cat-head">
            <h3><?= $v->t('docs.cat.' . $cat) ?></h3>
            <a href="<?= $v->url('/objects/' . $id . '/docs', ['cat' => $cat]) ?>"><?= $v->t('object.edit') ?></a>
        </div>
        <div class="markdown"<?= $cat === \Nordvarr\Repository\DocRepository::MAIN ? ' data-test="docs"' : '' ?>><?= $v->raw($doc['html']) ?></div>
        <p class="muted small-text"><?= $v->t('docs.meta', ['author' => $doc['updated_by'], 'count' => $doc['revisions']]) ?> <?= $v->time($doc['updated_at']) ?></p>
    </div>
    <?php endforeach; ?>
    <?php $missing = array_diff(\Nordvarr\Repository\DocRepository::CATEGORIES, array_keys($docs)); ?>
    <?php if ($missing !== []): ?>
    <p class="doc-add" data-test="docs-add"><?= $v->t('docs.add') ?>
        <?php foreach ($missing as $cat): ?>
        <a href="<?= $v->url('/objects/' . $id . '/docs', ['cat' => $cat]) ?>"><?= $v->t('docs.cat.' . $cat) ?></a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>
</section>

<section class="card" id="timeline">
    <h2><?= $v->t('object.timeline') ?></h2>
    <?php if ($timeline === []): ?>
    <p class="muted"><?= $v->t('object.no_events') ?></p>
    <?php else: ?>
    <ol class="timeline" data-test="timeline">
        <?php foreach ($timeline as $event): ?>
        <li>
            <span class="nowrap muted"><?= $v->time((string) $event['occurred_at']) ?></span>
            <span class="badge type-<?= $v->e($event['type']) ?>"><?= $v->t('event.type.' . $event['type']) ?></span>
            <strong><?= $v->e($event['summary']) ?></strong>
            <span class="muted small-text"><?= $v->e($event['source']) ?></span>
            <?php $others = array_values(array_filter($event['object_ids'], static fn (string $x): bool => $x !== $id)); ?>
            <?php if (!in_array($id, $event['object_ids'], true) && $others !== []): ?>
            <span class="muted small-text" data-test="neighbor-event"><?= $v->t('event.at_neighbor') ?>
                <?php foreach ($others as $oid): ?><a href="<?= $v->url('/objects/' . $oid) ?>"><?= $v->e($timelineObjects[$oid]['name'] ?? $oid) ?></a> <?php endforeach; ?></span>
            <?php endif; ?>
            <?php if ((string) $event['details'] !== ''): ?>
            <details><summary><?= $v->t('event.details') ?></summary><pre class="code"><?= $v->e($event['details']) ?></pre></details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <details class="add-event">
        <summary><?= $v->t('event.add') ?></summary>
        <form method="post" action="<?= $v->url('/objects/' . $id . '/events') ?>" class="form" data-test="event-form">
            <?= $v->csrfField() ?>
            <label for="ev-type"><?= $v->t('event.type') ?></label>
            <select id="ev-type" name="type">
                <?php foreach (\Nordvarr\Inventory\Vocabulary::EVENT_TYPES as $type): ?>
                <option value="<?= $v->e($type) ?>"<?= $type === 'note' ? ' selected' : '' ?>><?= $v->t('event.type.' . $type) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="ev-when"><?= $v->t('event.occurred_at') ?></label>
            <input id="ev-when" name="occurred_at" type="datetime-local" value="<?= $v->e($nowLocal) ?>" required>
            <label for="ev-summary"><?= $v->t('event.summary') ?></label>
            <input id="ev-summary" name="summary" type="text" maxlength="300" required>
            <label for="ev-details"><?= $v->t('event.details') ?></label>
            <textarea id="ev-details" name="details" rows="3" maxlength="4000"></textarea>
            <button type="submit"><?= $v->t('event.save') ?></button>
        </form>
    </details>
</section>

<section class="card" id="incidents">
    <h2><?= $v->t('diag.incidents') ?></h2>
    <?php if ($incidents === []): ?>
    <p class="muted"><?= $v->t('diag.no_incidents') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="incidents">
        <thead><tr><th><?= $v->t('alert.opened') ?></th><th><?= $v->t('check.detail') ?></th><th><?= $v->t('check.reason') ?></th><th><?= $v->t('diag.duration') ?></th><th><?= $v->t('alert.state') ?></th></tr></thead>
        <tbody>
        <?php foreach ($incidents as $a): ?>
        <tr>
            <td class="nowrap"><a href="<?= $v->url('/alerts/' . $a['id']) ?>"><?= $v->time((string) $a['opened_at']) ?></a></td>
            <td><?= $v->t('checktype.' . $a['check_type']) ?><?= (string) $a['check_name'] !== '' ? ' ' . $v->e($a['check_name']) : '' ?></td>
            <td><?php if ($a['reason_code'] !== null): ?><code><?= $v->e($a['reason_code']) ?></code><?php endif; ?>
                <?php if ($a['probable_cause_object_id'] !== null): ?><br><span class="small-text"><?= $v->t('alert.cause') ?>: <a href="<?= $v->url('/objects/' . $a['probable_cause_object_id']) ?>"><?= $v->e($a['cause_name'] ?? '') ?></a></span><?php endif; ?></td>
            <td class="nowrap"><?= $v->duration((string) $a['opened_at'], $a['resolved_at'] !== null ? (string) $a['resolved_at'] : null) ?></td>
            <td><?= $a['status'] === 'open' ? $v->raw($v->render('partials/status', ['status' => (string) $a['level']])) : $v->t('alert.resolved_reason.' . ($a['resolved_reason'] ?? 'recovered')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="card danger-zone">
    <h2><?= $v->t('object.delete') ?></h2>
    <form method="post" action="<?= $v->url('/objects/' . $id . '/delete') ?>" class="inline-form" data-test="delete-form">
        <?= $v->csrfField() ?>
        <label class="check-label"><input type="checkbox" name="confirm" value="1" required> <?= $v->t('object.delete_confirm') ?></label>
        <button type="submit" class="danger"><?= $v->t('object.delete') ?></button>
    </form>
</section>
