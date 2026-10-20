<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $alert */
/** @var list<array<string, mixed>> $deliveries */
/** @var array<string, list<array<string, mixed>>> $attempts */
?>
<p><a href="<?= $v->url('/alerts') ?>"><?= $v->t('nav.alerts') ?></a></p>
<section class="card object-head" data-test="alert-head">
    <p class="muted"><?= $v->t('checktype.' . $alert['check_type']) ?><?= (string) $alert['check_name'] !== '' ? ' ' . $v->e($alert['check_name']) : '' ?></p>
    <h1><a href="<?= $v->url('/objects/' . $alert['object_id']) ?>"><?= $v->e($alert['object_name']) ?></a></h1>
    <p class="status-line">
        <?= $v->raw($v->render('partials/status', ['status' => $alert['status'] === 'open' ? (string) $alert['level'] : 'healthy'])) ?>
        <?php if ($alert['reason_code'] !== null): ?><code><?= $v->e($alert['reason_code']) ?></code><?php endif; ?>
        <span class="muted"><?= $v->e($alert['reason_detail']) ?></span>
    </p>
    <dl class="facts">
        <dt><?= $v->t('alert.opened') ?></dt><dd><?= $v->time((string) $alert['opened_at']) ?></dd>
        <dt><?= $v->t('alert.state') ?></dt><dd><?= $alert['status'] === 'open' ? $v->t('alert.status.open') : $v->t('alert.resolved_reason.' . ($alert['resolved_reason'] ?? 'recovered')) . ' ' . $v->time((string) $alert['resolved_at']) ?></dd>
        <?php if ($alert['probable_cause_object_id'] !== null): ?>
        <dt><?= $v->t('alert.cause') ?></dt><dd data-test="probable-cause"><a href="<?= $v->url('/objects/' . $alert['probable_cause_object_id']) ?>"><?= $v->e($alert['cause_name'] ?? '') ?></a>
            <?php if ($alert['suppressed_by_alert_id'] !== null): ?> <span class="badge"><?= $v->t('alert.suppressed') ?></span><?php endif; ?></dd>
        <?php endif; ?>
        <dt><?= $v->t('alert.last_notified') ?></dt><dd><?= $alert['last_notified_at'] !== null ? $v->time((string) $alert['last_notified_at']) : $v->t('settings.never') ?></dd>
        <?php if ($alert['check_id'] !== null): ?>
        <dt><?= $v->t('check.detail') ?></dt><dd><a href="<?= $v->url('/checks/' . $alert['check_id']) ?>"><?= $v->t('alert.show_check') ?></a></dd>
        <?php endif; ?>
    </dl>
</section>
<section class="card">
    <h2><?= $v->t('alert.deliveries') ?></h2>
    <?= $v->raw($v->render('partials/deliveries', ['deliveries' => $deliveries, 'attempts' => $attempts, 'showChannel' => true])) ?>
</section>
