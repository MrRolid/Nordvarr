<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $failing */
/** @var list<array<string, mixed>> $expiring */
/** @var list<array<string, mixed>> $events */
/** @var array<string, array<string, mixed>> $eventObjects */
/** @var string|null $workerLastRun */
/** @var bool $workerStale */
/** @var bool $hasChecks */
/** @var array<string, true> $paused */
/** @var array<string, array<string, mixed>> $openAlerts id kontroly => otevřený alert */
$status = static fn (string $s): string => $v->render('partials/status', ['status' => $s]);
?>
<?php if ($workerStale): ?>
<p class="alert-box" role="alert" data-test="worker-warning">
    <?= $workerLastRun === null ? $v->t('overview.worker_never') : $v->t('overview.worker_stale', ['ago' => $v->ago($workerLastRun)]) ?>
    <a href="<?= $v->url('/settings') ?>#general"><?= $v->t('overview.worker_cron') ?></a>
</p>
<?php endif; ?>

<section class="card" data-test="summary">
    <h2><?= $v->t('overview.summary') ?></h2>
    <ul class="summary">
        <?php foreach ($summary as $key => $count): ?>
        <li><a href="<?= $v->url('/inventory', ['status' => $key]) ?>"><?= $v->raw($status($key)) ?> <strong data-test="summary-<?= $v->e($key) ?>"><?= $v->e($count) ?></strong></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if (($summary['none'] ?? 0) > 0): ?>
    <p class="muted small-text" data-test="no-checks-hint"><?= $v->t('suggest.overview_hint', ['count' => (int) $summary['none']]) ?>
        <a href="<?= $v->url('/inventory', ['status' => 'none']) ?>"><?= $v->t('suggest.overview_link') ?></a></p>
    <?php endif; ?>
</section>

<section class="card" data-test="down-now">
    <h2><?= $v->t('overview.down') ?></h2>
    <?php if ($failing === []): ?>
    <p class="muted"><?= $v->t($hasChecks ? 'overview.all_ok' : 'overview.no_checks') ?></p>
    <?php else: ?>
    <ul class="incidents">
        <?php foreach ($failing as $c): ?>
        <li>
            <?= $v->raw($status(\Nordvarr\Checks\ObjectStatus::checkStatus($c, $paused))) ?>
            <a href="<?= $v->url('/objects/' . $c['object_id']) ?>"><strong><?= $v->e($c['object_name']) ?></strong></a>
            <a href="<?= $v->url('/checks/' . $c['id']) ?>" class="muted"><?= $v->t('checktype.' . $c['type']) ?><?= (string) $c['name'] !== '' ? ' ' . $v->e($c['name']) : '' ?></a>:
            <code><?= $v->e($c['reason_code'] ?? '') ?></code>, <?= $v->ago((string) $c['since']) ?>
            <?php if ((string) $c['reason_detail'] !== ''): ?><span class="muted small-text">(<?= $v->e($c['reason_detail']) ?>)</span><?php endif; ?>
            <?php $alert = $openAlerts[$c['id']] ?? null; ?>
            <?php if ($alert !== null && $alert['probable_cause_object_id'] !== null): ?>
            <br><span class="small-text" data-test="probable-cause"><?= $v->t('alert.cause') ?>: <a href="<?= $v->url('/objects/' . $alert['probable_cause_object_id']) ?>"><?= $v->e($alert['cause_name'] ?? '') ?></a></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<section class="card" data-test="changes">
    <h2><?= $v->t('overview.changes') ?></h2>
    <?php if ($events === []): ?>
    <p class="muted"><?= $v->t('overview.no_changes') ?></p>
    <?php else: ?>
    <ol class="timeline">
        <?php foreach ($events as $event): ?>
        <li>
            <span class="nowrap muted"><?= $v->time((string) $event['occurred_at']) ?></span>
            <span class="badge"><?= $v->t('event.type.' . $event['type']) ?></span>
            <strong><?= $v->e($event['summary']) ?></strong>
            <?php foreach ($event['object_ids'] as $oid): ?>
            <a href="<?= $v->url('/objects/' . $oid) ?>"><?= $v->e($eventObjects[$oid]['name'] ?? $oid) ?></a>
            <?php endforeach; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>

<section class="card" data-test="expiring">
    <h2><?= $v->t('overview.expiring') ?></h2>
    <?php if ($expiring === []): ?>
    <p class="muted"><?= $v->t('overview.no_expiring') ?></p>
    <?php else: ?>
    <ul class="incidents">
        <?php foreach ($expiring as $c): ?>
        <?php $days = (int) floor((strtotime((string) $c['expires_at']) - time()) / 86400); ?>
        <li>
            <?= $v->raw($status(\Nordvarr\Checks\ObjectStatus::checkStatus($c, $paused))) ?>
            <a href="<?= $v->url('/checks/' . $c['id']) ?>"><strong><?= $v->e(\Nordvarr\Checks\CheckTypes::target((string) $c['type'], $c['config'])) ?></strong></a>
            <span class="muted"><?= $v->t('checktype.' . $c['type']) ?>, <?= $v->e($c['object_name']) ?></span>:
            <?= $days < 0 ? $v->t('overview.expired') : $v->t('overview.expires_in', ['n' => $days]) ?> (<?= $v->time((string) $c['expires_at']) ?>)
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
