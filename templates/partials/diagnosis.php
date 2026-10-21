<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $diagObject */
/** @var array{status: string, check: array<string, mixed>|null} $objectStatus */
/** @var list<array<string, mixed>> $openAlerts */
/** @var bool $inMaintenance */
/** @var list<array{id: string, name: string, kind: string, status: string}> $dependencies */
/** @var array<string, mixed>|null $lastChange */
/** @var array<string, array<string, mixed>> $lastChangeObjects */
/** @var int $alertCount90 */
$worst = $objectStatus['check'];
$failing = $worst !== null && !in_array($objectStatus['status'], ['healthy', 'paused', 'none'], true);
$causes = array_values(array_filter($openAlerts, static fn (array $a): bool => $a['probable_cause_object_id'] !== null));
?>
<dl class="diag" data-test="diagnosis">
    <div class="diag-cell diag-state">
        <dt><?= $v->t('diag.state') ?></dt>
        <dd data-test="object-status">
            <?= $v->raw($v->render('partials/status', ['status' => $objectStatus['status']])) ?>
            <?php if ($failing): ?>
            <span class="diag-reason"><?= $v->t('checktype.' . $worst['type']) ?><?php if ((string) ($worst['reason_code'] ?? '') !== ''): ?>: <code><?= $v->e($worst['reason_code']) ?></code><?php endif; ?>, <?= $v->ago((string) $worst['since']) ?></span>
            <?php if ((string) $worst['reason_detail'] !== ''): ?><span class="muted small-text diag-detail"><?= $v->e($worst['reason_detail']) ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($inMaintenance): ?><span class="badge warn" data-test="in-maintenance"><?= $v->t('object.in_maintenance') ?></span><?php endif; ?>
        </dd>
    </div>
    <div class="diag-cell">
        <dt><?= $v->t('alert.cause') ?></dt>
        <dd>
            <?php if ($causes === []): ?>
            <span class="muted">-</span>
            <?php else: ?>
            <?php foreach ($causes as $alert): ?>
            <span data-test="probable-cause"><a href="<?= $v->url('/objects/' . $alert['probable_cause_object_id']) ?>"><?= $v->e($alert['cause_name'] ?? '') ?></a></span>
            <a class="small-text" href="<?= $v->url('/alerts/' . $alert['id']) ?>">(<?= $v->t('checktype.' . $alert['check_type']) ?>)</a>
            <?php endforeach; ?>
            <?php endif; ?>
        </dd>
    </div>
    <div class="diag-cell">
        <dt><?= $v->t('diag.last_change') ?></dt>
        <dd data-test="last-change">
            <?php if ($lastChange === null): ?>
            <span class="muted"><?= $v->t('diag.no_change') ?></span>
            <?php else: ?>
            <span class="badge"><?= $v->t('event.type.' . $lastChange['type']) ?></span>
            <span><strong><?= $v->e($lastChange['summary']) ?></strong>, <?= $v->ago((string) $lastChange['occurred_at']) ?></span>
            <?php if (!in_array((string) $diagObject['id'], $lastChange['object_ids'], true)): ?>
            <span class="small-text"><?= $v->t('event.at_neighbor') ?>
                <?php foreach ($lastChange['object_ids'] as $oid): ?><a href="<?= $v->url('/objects/' . $oid) ?>"><?= $v->e($lastChangeObjects[$oid]['name'] ?? $oid) ?></a> <?php endforeach; ?></span>
            <?php endif; ?>
            <?php endif; ?>
        </dd>
    </div>
    <div class="diag-cell diag-wide">
        <dt><?= $v->t('object.dependencies') ?></dt>
        <dd data-test="dependencies">
            <?php if ($dependencies === []): ?>
            <span class="muted">-</span>
            <?php endif; ?>
            <?php foreach ($dependencies as $dep): ?>
            <span class="dep"><span class="dep-kind"><?= $v->t('dep.' . $dep['kind']) ?></span> <a href="<?= $v->url('/objects/' . $dep['id']) ?>"><?= $v->e($dep['name']) ?></a> <?= $v->raw($v->render('partials/status', ['status' => $dep['status']])) ?></span>
            <?php endforeach; ?>
        </dd>
    </div>
    <div class="diag-cell">
        <dt><?= $v->t('diag.alerts_90') ?></dt>
        <dd data-test="alerts-90"><a href="#incidents"><?= $v->e($alertCount90) ?></a></dd>
    </div>
</dl>
