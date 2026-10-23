<?php
/** @var \Nordvarr\View\View $v */
/** @var array{type: string, tag: string, q: string, lifecycle: string} $filter */
/** @var list<array<string, mixed>> $objects */
/** @var array<string, list<string>> $tags */
/** @var list<string> $allTags */
/** @var bool $truncated */
/** @var array<string, array{status: string, check: array<string, mixed>|null}> $statuses */
$back = http_build_query(array_filter($filter, static fn (string $x): bool => $x !== ''));
?>
<div class="page-head">
    <h1><?= $v->t('nav.inventory') ?></h1>
    <a class="button primary" href="<?= $v->url('/objects/new') ?>" data-test="new-object"><?= $v->t('object.new') ?></a>
</div>

<form method="get" action="<?= $v->url('/inventory') ?>" class="card filters" data-test="inventory-filter">
    <?php if ($v->shared('pretty_urls') !== true): ?>
    <input type="hidden" name="r" value="/inventory">
    <?php endif; ?>
    <div class="field">
        <label for="f-q"><?= $v->t('inventory.search') ?></label>
        <input id="f-q" type="search" name="q" value="<?= $v->e($filter['q']) ?>">
    </div>
    <div class="field">
        <label for="f-type"><?= $v->t('inventory.type') ?></label>
        <select id="f-type" name="type">
            <option value=""><?= $v->t('inventory.all') ?></option>
            <?php foreach (\Nordvarr\Inventory\Vocabulary::OBJECT_TYPES as $type): ?>
            <option value="<?= $v->e($type) ?>"<?= $filter['type'] === $type ? ' selected' : '' ?>><?= $v->t('type.' . $type) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="f-tag"><?= $v->t('inventory.tag') ?></label>
        <select id="f-tag" name="tag">
            <option value=""><?= $v->t('inventory.all') ?></option>
            <?php foreach ($allTags as $tag): ?>
            <option value="<?= $v->e($tag) ?>"<?= $filter['tag'] === $tag ? ' selected' : '' ?>><?= $v->e($tag) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="f-status"><?= $v->t('inventory.status') ?></label>
        <select id="f-status" name="status">
            <option value=""><?= $v->t('inventory.all') ?></option>
            <?php foreach (['unhealthy', 'degraded', 'unknown', 'healthy', 'paused', 'none'] as $st): ?>
            <option value="<?= $v->e($st) ?>"<?= $filter['status'] === $st ? ' selected' : '' ?>><?= $v->t('status.' . $st) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="f-lifecycle"><?= $v->t('inventory.lifecycle') ?></label>
        <select id="f-lifecycle" name="lifecycle">
            <?php foreach (['active', 'archived', 'all'] as $lc): ?>
            <option value="<?= $v->e($lc) ?>"<?= $filter['lifecycle'] === $lc ? ' selected' : '' ?>><?= $v->t('lifecycle.' . $lc) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field actions">
        <button type="submit"><?= $v->t('inventory.filter') ?></button>
    </div>
</form>

<form method="post" action="<?= $v->url('/inventory/tags') ?>" class="card">
    <?= $v->csrfField() ?>
    <input type="hidden" name="back" value="<?= $v->e($back) ?>">
    <p class="muted" data-test="inventory-count"><?= $v->t('inventory.count', ['count' => count($objects)]) ?></p>
    <?php if ($truncated): ?>
    <p class="alert-box"><?= $v->t('inventory.truncated') ?></p>
    <?php endif; ?>
    <?php if ($objects === []): ?>
    <p><?= $v->t('inventory.empty') ?></p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="list" data-test="inventory-table">
            <thead>
            <tr>
                <th class="check"><span class="visually-hidden"><?= $v->t('inventory.select') ?></span></th>
                <th><?= $v->t('inventory.status') ?></th>
                <th><?= $v->t('object.name') ?></th>
                <th><?= $v->t('object.type') ?></th>
                <th><?= $v->t('object.tags') ?></th>
                <th><?= $v->t('object.updated') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($objects as $o): ?>
            <tr<?= $o['lifecycle'] === 'archived' ? ' class="archived"' : '' ?>>
                <td class="check"><input type="checkbox" name="ids[]" value="<?= $v->e($o['id']) ?>" aria-label="<?= $v->t('inventory.select_one', ['name' => (string) $o['name']]) ?>"></td>
                <td><?= $v->raw($v->render('partials/status', ['status' => $statuses[$o['id']]['status'] ?? 'none'])) ?></td>
                <td><a href="<?= $v->url('/objects/' . $o['id']) ?>"><?= $v->e($o['name']) ?></a>
                    <?php if ($o['lifecycle'] === 'archived'): ?><span class="badge"><?= $v->t('lifecycle.archived') ?></span><?php endif; ?></td>
                <td><?= $v->t('type.' . $o['type']) ?></td>
                <td><?php foreach ($tags[$o['id']] ?? [] as $tag): ?><a class="tag" href="<?= $v->url('/inventory', ['tag' => $tag]) ?>"><?= $v->e($tag) ?></a> <?php endforeach; ?></td>
                <td class="nowrap"><?= $v->time((string) $o['updated_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="bulk">
        <label for="bulk-tag"><?= $v->t('inventory.bulk_tag') ?></label>
        <input id="bulk-tag" name="tag" type="text" maxlength="50" required>
        <button type="submit"><?= $v->t('inventory.bulk_submit') ?></button>
        <button type="submit" formaction="<?= $v->url('/inventory/checks') ?>" formnovalidate data-test="bulk-suggested"><?= $v->t('suggest.bulk') ?></button>
    </div>
    <?php endif; ?>
</form>
