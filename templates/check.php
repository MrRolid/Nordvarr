<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $check */
/** @var string $status */
/** @var list<array<string, mixed>> $results */
/** @var list<array<string, mixed>> $hourly */
/** @var array{count: int, failures: int, avg_latency_ms: ?int} $summary */
/** @var string|null $heartbeatUrl */
$id = (string) $check['id'];
?>
<p><a href="<?= $v->url('/objects/' . $check['object_id']) ?>"><?= $v->e($check['object_name']) ?></a></p>
<section class="card object-head" data-test="check-head">
    <p class="muted"><?= $v->t('checktype.' . $check['type']) ?></p>
    <h1><?= $v->e((string) $check['name'] !== '' ? $check['name'] : \Nordvarr\Checks\CheckTypes::target((string) $check['type'], $check['config'])) ?></h1>
    <p class="status-line" data-test="check-status">
        <?= $v->raw($v->render('partials/status', ['status' => $status])) ?>
        <?php if ($check['reason_code'] !== null && $status !== 'healthy' && $status !== 'paused'): ?>
        <code><?= $v->e($check['reason_code']) ?></code>
        <?php endif; ?>
        <span class="muted"><?= $v->t('check.since') ?> <?= $v->ago((string) $check['since']) ?></span>
    </p>
    <?php if ((string) $check['reason_detail'] !== ''): ?><p><?= $v->e($check['reason_detail']) ?></p><?php endif; ?>
    <dl class="facts">
        <dt><?= $v->t('check.target') ?></dt><dd><code><?= $v->e(\Nordvarr\Checks\CheckTypes::target((string) $check['type'], $check['config']) ?: '-') ?></code></dd>
        <dt><?= $v->t('check.last_run') ?></dt><dd><?= $check['last_run_at'] !== null ? $v->time((string) $check['last_run_at']) : $v->t('settings.never') ?></dd>
        <dt><?= $v->t('check.next_run') ?></dt><dd><?= $check['enabled'] && $check['next_run_at'] !== null ? $v->time((string) $check['next_run_at']) : '-' ?></dd>
        <dt><?= $v->t('check.interval') ?></dt><dd><?= $v->e($check['interval_s']) ?> s</dd>
        <dt><?= $v->t('check.threshold') ?></dt><dd><?= $v->e($check['failure_threshold']) ?> (<?= $v->t('check.consecutive', ['n' => (int) $check['consecutive_failures']]) ?>)</dd>
        <?php if ($check['expires_at'] !== null): ?>
        <dt><?= $v->t('check.expires_at') ?></dt><dd><?= $v->time((string) $check['expires_at']) ?></dd>
        <?php endif; ?>
        <dt><?= $v->t('check.summary_24h') ?></dt>
        <dd data-test="summary-24h"><?= $v->t('check.summary_text', ['count' => $summary['count'], 'failures' => $summary['failures'],
            'latency' => $summary['avg_latency_ms'] === null ? '-' : $summary['avg_latency_ms'] . ' ms']) ?></dd>
    </dl>
    <?php if ($heartbeatUrl !== null): ?>
    <p><?= $v->t('check.heartbeat_url') ?></p>
    <pre class="code" data-test="heartbeat-url"><?= $v->e($heartbeatUrl) ?></pre>
    <p class="help"><?= $v->t('check.heartbeat_usage') ?></p>
    <?php endif; ?>
    <div class="row">
        <a class="button" href="<?= $v->url('/checks/' . $id . '/edit') ?>"><?= $v->t('check.edit') ?></a>
        <form method="post" action="<?= $v->url('/checks/' . $id . '/run') ?>" class="inline"><?= $v->csrfField() ?>
            <button type="submit"><?= $v->t('check.run_now') ?></button></form>
        <form method="post" action="<?= $v->url('/checks/' . $id . '/toggle') ?>" class="inline"><?= $v->csrfField() ?>
            <button type="submit"><?= $v->t($check['enabled'] ? 'check.disable' : 'check.enable') ?></button></form>
    </div>
</section>

<section class="card">
    <h2><?= $v->t('check.results') ?></h2>
    <?php if ($results === []): ?>
    <p class="muted"><?= $v->t('check.no_results') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="results">
        <thead><tr><th><?= $v->t('check.time') ?></th><th><?= $v->t('check.result') ?></th><th><?= $v->t('check.reason') ?></th><th><?= $v->t('check.latency_col') ?></th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td class="nowrap"><?= $v->time((string) $r['started_at']) ?></td>
            <td><?= $v->raw($v->render('partials/status', ['status' => (string) $r['status']])) ?></td>
            <td><?php if ($r['reason_code'] !== null): ?><code><?= $v->e($r['reason_code']) ?></code> <?php endif; ?><span class="muted"><?= $v->e($r['reason_detail']) ?></span></td>
            <td class="nowrap"><?= $r['latency_ms'] !== null ? $v->e($r['latency_ms']) . ' ms' : '' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php if ($hourly !== []): ?>
<section class="card">
    <h2><?= $v->t('check.hourly') ?></h2>
    <div class="table-wrap">
    <table class="list" data-test="hourly">
        <thead><tr><th><?= $v->t('check.hour') ?></th><th><?= $v->t('check.runs') ?></th><th><?= $v->t('check.failures') ?></th><th><?= $v->t('check.avg_latency') ?></th><th><?= $v->t('check.max_latency') ?></th></tr></thead>
        <tbody>
        <?php foreach ($hourly as $h): ?>
        <tr>
            <td class="nowrap"><?= $v->time((string) $h['hour']) ?></td>
            <td><?= $v->e($h['count']) ?></td>
            <td><?= $v->e($h['failures']) ?></td>
            <td><?= $h['avg_latency_ms'] !== null ? $v->e($h['avg_latency_ms']) . ' ms' : '' ?></td>
            <td><?= $h['max_latency_ms'] !== null ? $v->e($h['max_latency_ms']) . ' ms' : '' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<section class="card danger-zone">
    <h2><?= $v->t('check.delete') ?></h2>
    <form method="post" action="<?= $v->url('/checks/' . $id . '/delete') ?>" class="inline-form">
        <?= $v->csrfField() ?>
        <label class="check-label"><input type="checkbox" name="confirm" value="1" required> <?= $v->t('check.delete_confirm') ?></label>
        <button type="submit" class="danger"><?= $v->t('check.delete') ?></button>
    </form>
</section>
