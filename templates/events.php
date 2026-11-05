<?php
/** @var \Nordvarr\View\View $v */
/** @var list<array<string, mixed>> $events */
/** @var array<string, array<string, mixed>> $objects */
/** @var array<string, int> $counts */
/** @var array{period: string, severity: string, type: string} $filter */
?>
<h1><?= $v->t('nav.events') ?></h1>
<section class="card">
    <ul class="summary" data-test="event-counts">
        <?php foreach (array_reverse(\Nordvarr\Inventory\Vocabulary::EVENT_SEVERITIES) as $sev): ?>
        <li><a href="<?= $v->url('/events', ['period' => $filter['period'], 'severity' => $sev]) ?>"><span class="sev sev-<?= $v->e($sev) ?>"><?= $v->t('event.severity.' . $sev) ?></span> <strong><?= $v->e($counts[$sev] ?? 0) ?></strong></a></li>
        <?php endforeach; ?>
    </ul>
    <form method="get" action="<?= $v->url('/events') ?>" class="inline-form" data-test="event-filter">
        <label for="ef-period"><?= $v->t('event.period') ?></label>
        <select id="ef-period" name="period">
            <?php foreach (array_keys(\Nordvarr\Ui\EventController::PERIODS) as $p): ?>
            <option value="<?= $v->e($p) ?>"<?= $p === $filter['period'] ? ' selected' : '' ?>><?= $v->t('event.period.' . $p) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="ef-severity"><?= $v->t('event.min_severity') ?></label>
        <select id="ef-severity" name="severity">
            <?php foreach (\Nordvarr\Inventory\Vocabulary::EVENT_SEVERITIES as $sev): ?>
            <option value="<?= $v->e($sev) ?>"<?= $sev === $filter['severity'] ? ' selected' : '' ?>><?= $v->t('event.severity.' . $sev) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="ef-type"><?= $v->t('event.type') ?></label>
        <select id="ef-type" name="type">
            <option value=""><?= $v->t('event.all_types') ?></option>
            <?php foreach (\Nordvarr\Inventory\Vocabulary::EVENT_TYPES as $t): ?>
            <option value="<?= $v->e($t) ?>"<?= $t === $filter['type'] ? ' selected' : '' ?>><?= $v->t('event.type.' . $t) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= $v->t('event.filter') ?></button>
    </form>
</section>
<section class="card">
    <?php if ($events === []): ?>
    <p class="muted"><?= $v->t('event.none') ?></p>
    <?php else: ?>
    <ol class="timeline event-feed" data-test="event-feed">
        <?php foreach ($events as $event): ?>
        <li class="sev-row sev-row-<?= $v->e($event['severity']) ?>" data-severity="<?= $v->e($event['severity']) ?>">
            <span class="nowrap muted"><?= $v->time((string) $event['occurred_at']) ?></span>
            <span class="sev sev-<?= $v->e($event['severity']) ?>"><?= $v->t('event.severity.' . $event['severity']) ?></span>
            <span class="badge"><?= $v->t('event.type.' . $event['type']) ?></span>
            <strong><?= $v->e($event['summary']) ?></strong>
            <?php foreach ($event['object_ids'] as $oid): ?>
            <a href="<?= $v->url('/objects/' . $oid) ?>"><?= $v->e($objects[$oid]['name'] ?? $oid) ?></a>
            <?php endforeach; ?>
            <span class="muted small-text"><?= $v->e($event['source']) ?></span>
            <?php if ((string) $event['details'] !== ''): ?>
            <details><summary><?= $v->t('event.details') ?></summary><pre class="code"><?= $v->e($event['details']) ?></pre></details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <p class="help"><?= $v->t('event.feed_help') ?></p>
</section>
