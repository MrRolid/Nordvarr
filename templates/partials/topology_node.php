<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $object */
/** @var array{status: string, check: array<string, mixed>|null} $status */
/** @var list<array<string, mixed>> $checks */
/** @var array<string, true> $paused */
/** @var list<array<string, mixed>> $openAlerts */
/** @var list<array<string, mixed>> $incidents */
/** @var int $incidentCount */
/** @var list<array<string, mixed>> $timeline */
/** @var array<string, array<string, mixed>> $timelineObjects */
/** @var string|null $docsHtml */
/** @var list<string> $tags */
/** @var array<string, mixed>|null $host */
$id = (string) $object['id'];
$worst = $status['check'];
?>
<div class="np" data-test="node-card" data-node-id="<?= $v->e($id) ?>">
    <div class="np-head">
        <h2><?= $v->e($object['name']) ?></h2>
        <span class="muted small-text"><?= $v->t('type.' . $object['type']) ?></span>
    </div>
    <p class="status-line">
        <?= $v->raw($v->render('partials/status', ['status' => $status['status']])) ?>
        <?php if ($worst !== null && !in_array($status['status'], ['healthy', 'paused', 'none'], true)): ?>
        <span><?= $v->t('checktype.' . $worst['type']) ?><?php if ((string) ($worst['reason_code'] ?? '') !== ''): ?>: <code><?= $v->e($worst['reason_code']) ?></code><?php endif; ?>, <?= $v->ago((string) $worst['since']) ?></span>
        <?php endif; ?>
    </p>
    <?php foreach ($openAlerts as $alert): ?>
    <?php if ($alert['probable_cause_object_id'] !== null): ?>
    <p class="np-cause" data-test="probable-cause"><?= $v->t('alert.cause') ?>: <a href="<?= $v->url('/objects/' . $alert['probable_cause_object_id']) ?>"><?= $v->e($alert['cause_name'] ?? '') ?></a></p>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($tags !== []): ?>
    <p class="tags"><?php foreach ($tags as $tag): ?><span class="tag"><?= $v->e($tag) ?></span><?php endforeach; ?></p>
    <?php endif; ?>
    <div class="row">
        <a class="button primary small" href="<?= $v->url('/objects/' . $id) ?>" data-test="open-card"><?= $v->t('topology.open_card') ?></a>
        <a class="button small" href="<?= $v->url('/topology', ['center' => $id]) ?>"><?= $v->t('topology.recenter') ?></a>
    </div>

    <?php if ($host !== null): ?>
    <?= $v->raw($v->render('partials/host_compact', ['host' => $host])) ?>
    <?php endif; ?>

    <h3 class="np-h"><?= $v->t('object.checks') ?></h3>
    <?php if ($checks === []): ?>
    <p class="muted small-text"><?= $v->t('check.none') ?></p>
    <?php else: ?>
    <ul class="np-list">
        <?php foreach ($checks as $c): ?>
        <?php $cs = \Nordvarr\Checks\ObjectStatus::checkStatus($c, $paused); ?>
        <li>
            <span class="dot dot-<?= $v->e($cs) ?>" aria-hidden="true"></span>
            <a href="<?= $v->url('/checks/' . $c['id']) ?>"><?= $v->t('checktype.' . $c['type']) ?><?= (string) $c['name'] !== '' ? ' ' . $v->e($c['name']) : '' ?></a>
            <span class="np-val"><?php if ($cs !== 'healthy' && $c['reason_code'] !== null && $cs !== 'paused'): ?><code><?= $v->e($c['reason_code']) ?></code><?php elseif ($c['last_latency_ms'] !== null): ?><?= $v->e($c['last_latency_ms']) ?> ms<?php else: ?><?= $v->t('status.' . $cs) ?><?php endif; ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3 class="np-h"><?= $v->t('topology.incidents', ['count' => $incidentCount]) ?></h3>
    <?php if ($incidents === []): ?>
    <p class="muted small-text"><?= $v->t('diag.no_incidents') ?></p>
    <?php else: ?>
    <ul class="np-list">
        <?php foreach ($incidents as $a): ?>
        <li>
            <span class="dot dot-<?= $v->e($a['status'] === 'open' ? (string) $a['level'] : 'healthy') ?>" aria-hidden="true"></span>
            <a href="<?= $v->url('/alerts/' . $a['id']) ?>"><?= $v->time((string) $a['opened_at']) ?></a>
            <span class="np-val"><code><?= $v->e($a['reason_code'] ?? '') ?></code> <?= $v->duration((string) $a['opened_at'], $a['resolved_at'] !== null ? (string) $a['resolved_at'] : null) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3 class="np-h"><?= $v->t('topology.changes') ?></h3>
    <?php if ($timeline === []): ?>
    <p class="muted small-text"><?= $v->t('object.no_events') ?></p>
    <?php else: ?>
    <ul class="np-list">
        <?php foreach ($timeline as $event): ?>
        <li class="np-event">
            <span class="badge"><?= $v->t('event.type.' . $event['type']) ?></span>
            <span><?= $v->e($event['summary']) ?></span>
            <span class="np-val"><?= $v->ago((string) $event['occurred_at']) ?></span>
            <?php if (!in_array($id, $event['object_ids'], true)): ?>
            <span class="small-text muted np-at"><?= $v->t('event.at_neighbor') ?> <?php foreach ($event['object_ids'] as $oid): ?><?= $v->e($timelineObjects[$oid]['name'] ?? '') ?> <?php endforeach; ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3 class="np-h"><?= $v->t('object.docs') ?></h3>
    <?php if ($docsHtml === null): ?>
    <p class="muted small-text"><?= $v->t('object.no_docs') ?></p>
    <?php else: ?>
    <div class="markdown np-docs" data-test="node-docs"><?= $v->raw($docsHtml) ?></div>
    <?php endif; ?>
</div>
