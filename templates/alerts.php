<?php
/** @var \Nordvarr\View\View $v */
/** @var array{status: string, q: string} $filter */
/** @var list<array<string, mixed>> $alerts */
/** @var list<array<string, mixed>> $windows */
/** @var string $now */
/** @var array<string, string> $objectNames */
/** @var list<string> $allTags */
/** @var array<string, mixed> $form */
/** @var array<string, string> $errors */
$err = static fn (string $f): string => isset($errors[$f]) ? '<p class="field-error">' . $v->t('err.' . $errors[$f]) . '</p>' : '';
?>
<div class="page-head">
    <h1><?= $v->t('nav.alerts') ?></h1>
    <a class="button" href="<?= $v->url('/alerts/deliveries') ?>"><?= $v->t('delivery.title') ?></a>
</div>

<form method="get" action="<?= $v->url('/alerts') ?>" class="card filters">
    <?php if ($v->shared('pretty_urls') !== true): ?><input type="hidden" name="r" value="/alerts"><?php endif; ?>
    <div class="field">
        <label for="a-status"><?= $v->t('alert.filter_status') ?></label>
        <select id="a-status" name="status">
            <?php foreach (['open', 'resolved', 'all'] as $st): ?>
            <option value="<?= $v->e($st) ?>"<?= $filter['status'] === $st ? ' selected' : '' ?>><?= $v->t('alert.status.' . $st) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="a-q"><?= $v->t('inventory.search') ?></label>
        <input id="a-q" type="search" name="q" value="<?= $v->e($filter['q']) ?>">
    </div>
    <div class="field actions"><button type="submit"><?= $v->t('inventory.filter') ?></button></div>
</form>

<section class="card">
    <?php if ($alerts === []): ?>
    <p class="muted" data-test="no-alerts"><?= $v->t('alert.none') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="alerts">
        <thead><tr>
            <th><?= $v->t('alert.level') ?></th><th><?= $v->t('object.name') ?></th><th><?= $v->t('check.detail') ?></th>
            <th><?= $v->t('alert.opened') ?></th><th><?= $v->t('alert.state') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($alerts as $a): ?>
        <tr>
            <td><?= $v->raw($v->render('partials/status', ['status' => $a['status'] === 'open' ? (string) $a['level'] : 'healthy'])) ?></td>
            <td><a href="<?= $v->url('/objects/' . $a['object_id']) ?>"><?= $v->e($a['object_name']) ?></a>
                <?php if ($a['probable_cause_object_id'] !== null): ?>
                <br><span class="small-text" data-test="probable-cause"><?= $v->t('alert.cause') ?>: <a href="<?= $v->url('/objects/' . $a['probable_cause_object_id']) ?>"><?= $v->e($a['cause_name'] ?? '') ?></a></span>
                <?php endif; ?></td>
            <td><a href="<?= $v->url('/alerts/' . $a['id']) ?>"><?= $v->t('checktype.' . $a['check_type']) ?><?= (string) $a['check_name'] !== '' ? ' ' . $v->e($a['check_name']) : '' ?></a>
                <?php if ($a['reason_code'] !== null): ?><br><code><?= $v->e($a['reason_code']) ?></code><?php endif; ?></td>
            <td class="nowrap"><?= $v->time((string) $a['opened_at']) ?></td>
            <td>
                <?php if ($a['status'] === 'open'): ?>
                <?= $v->t('alert.open_for', ['ago' => $v->ago((string) $a['opened_at'])]) ?>
                <?php if ($a['suppressed_by_alert_id'] !== null): ?><br><span class="badge" data-test="suppressed"><?= $v->t('alert.suppressed') ?></span><?php endif; ?>
                <?php if ((int) $a['maintenance_suppressed'] === 1): ?><br><span class="badge"><?= $v->t('alert.in_maintenance') ?></span><?php endif; ?>
                <?php else: ?>
                <?= $v->t('alert.resolved_reason.' . ($a['resolved_reason'] ?? 'recovered')) ?> <span class="nowrap muted"><?= $v->time((string) $a['resolved_at']) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="card" id="maintenance">
    <h2><?= $v->t('maintenance.title') ?></h2>
    <?php if ($windows === []): ?>
    <p class="muted"><?= $v->t('maintenance.none') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="windows">
        <thead><tr><th><?= $v->t('maintenance.scope') ?></th><th><?= $v->t('maintenance.from') ?></th><th><?= $v->t('maintenance.to') ?></th><th><?= $v->t('maintenance.state') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($windows as $w): ?>
        <?php $state = strcmp((string) $w['ends_at'], $now) <= 0 ? 'ended' : (strcmp((string) $w['starts_at'], $now) <= 0 ? 'active' : 'planned'); ?>
        <tr>
            <td><?php if ($w['object_id'] !== null): ?><a href="<?= $v->url('/objects/' . $w['object_id']) ?>"><?= $v->e($w['object_name']) ?></a><?php else: ?><?= $v->t('maintenance.tag') ?> <span class="tag"><?= $v->e($w['tag']) ?></span><?php endif; ?>
                <?php if ((int) $w['pause_checks'] === 1): ?><br><span class="badge"><?= $v->t('maintenance.pauses_checks') ?></span><?php endif; ?>
                <?php if ((string) $w['note'] !== ''): ?><br><span class="muted small-text"><?= $v->e($w['note']) ?></span><?php endif; ?></td>
            <td class="nowrap"><?= $v->time((string) $w['starts_at']) ?></td>
            <td class="nowrap"><?= $v->time((string) $w['ends_at']) ?></td>
            <td><span class="badge" data-test="window-state"><?= $v->t('maintenance.state.' . $state) ?></span></td>
            <td class="nowrap">
                <?php if ($state === 'active'): ?>
                <form method="post" action="<?= $v->url('/maintenance/' . $w['id'] . '/end') ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="link small"><?= $v->t('maintenance.end') ?></button></form>
                <?php endif; ?>
                <form method="post" action="<?= $v->url('/maintenance/' . $w['id'] . '/delete') ?>" class="inline"><?= $v->csrfField() ?><button type="submit" class="link small"><?= $v->t('common.delete') ?></button></form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h3><?= $v->t('maintenance.new') ?></h3>
    <form method="post" action="<?= $v->url('/maintenance') ?>" class="form" data-test="maintenance-form">
        <?= $v->csrfField() ?>
        <fieldset>
            <legend><?= $v->t('maintenance.scope') ?></legend>
            <label class="check-label"><input type="radio" name="scope" value="object"<?= $form['scope'] === 'object' ? ' checked' : '' ?>> <?= $v->t('maintenance.scope_object') ?></label>
            <label for="mw-object" class="visually-hidden"><?= $v->t('object.rel_target') ?></label>
            <select id="mw-object" name="object_id">
                <option value=""></option>
                <?php foreach ($objectNames as $oid => $name): ?>
                <option value="<?= $v->e($oid) ?>"<?= $form['object_id'] === $oid ? ' selected' : '' ?>><?= $v->e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $v->raw($err('object_id')) ?>
            <label class="check-label"><input type="radio" name="scope" value="tag"<?= $form['scope'] === 'tag' ? ' checked' : '' ?>> <?= $v->t('maintenance.scope_tag') ?></label>
            <label for="mw-tag" class="visually-hidden"><?= $v->t('inventory.tag') ?></label>
            <input id="mw-tag" name="tag" type="text" maxlength="50" list="mw-tags" value="<?= $v->e($form['tag']) ?>">
            <datalist id="mw-tags"><?php foreach ($allTags as $tag): ?><option value="<?= $v->e($tag) ?>"><?php endforeach; ?></datalist>
            <?= $v->raw($err('tag')) ?>
        </fieldset>
        <label for="mw-from"><?= $v->t('maintenance.from') ?></label>
        <input id="mw-from" name="starts_at" type="datetime-local" required value="<?= $v->e($form['starts_at']) ?>">
        <label for="mw-to"><?= $v->t('maintenance.to') ?></label>
        <input id="mw-to" name="ends_at" type="datetime-local" required value="<?= $v->e($form['ends_at']) ?>">
        <?= $v->raw($err('ends_at')) ?>
        <label class="check-label"><input type="checkbox" name="pause_checks" value="1"<?= $form['pause_checks'] ? ' checked' : '' ?>> <?= $v->t('maintenance.pause_checks') ?></label>
        <label for="mw-note"><?= $v->t('maintenance.note') ?></label>
        <input id="mw-note" name="note" type="text" maxlength="500" value="<?= $v->e($form['note']) ?>">
        <?= $v->raw($err('note')) ?>
        <p class="help"><?= $v->t('maintenance.help') ?></p>
        <button type="submit" class="primary"><?= $v->t('maintenance.create') ?></button>
    </form>
</section>
