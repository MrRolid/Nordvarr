<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $host data z Nordvarr\Agent\HostView::build() */
use Nordvarr\View\Svg;

$h = $host;
$rows = [
    ['CPU', $h['cpu']['pct'], $h['cpu']['level'], $v->num($h['cpu']['pct']) . ' %'],
    ['RAM', $h['mem']['pct'], $h['mem']['level'], $v->num($h['mem']['pct']) . ' %'],
];
foreach ($h['gpus'] as $g) {
    $rows[] = ['GPU ' . (int) $g['index'], $g['util_pct'], $g['util_level'], $v->num($g['util_pct']) . ' %, ' . $v->num($g['temp_c']) . ' °C'];
}
if ($h['disk_max'] !== null) {
    $rows[] = [$v->t('host.disk_max'), $h['disk_max'], $h['disk_max_level'], $v->num($h['disk_max']) . ' %'];
}
if ($h['temp_max'] !== null) {
    $rows[] = [$v->t('host.temp_max'), $h['temp_max'], $h['temp_max_level'], $v->num($h['temp_max']) . ' °C'];
}
$smartBad = count(array_filter($h['smart'], static fn (array $d): bool => $d['level'] !== 'ok'));
?>
<div class="np-host" data-test="node-host">
    <h3 class="np-h"><?= $v->t('host.title') ?> <span class="muted"><?= $v->ago($h['last_report_at']) ?></span></h3>
    <?php if ($h['stale']): ?><p class="lvl-bad small-text"><?= $v->t('host.stale_short') ?></p><?php endif; ?>
    <dl class="np-bars">
        <?php foreach ($rows as [$label, $pct, $level, $value]): ?>
        <div><dt><?= $v->e($label) ?></dt><dd><?= $v->raw(Svg::bar($pct, $level)) ?><span class="lvl-<?= $v->e($level) ?>"><?= $v->raw($value) ?></span></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php if ($h['smart'] !== []): ?>
    <p class="small-text <?= $smartBad > 0 ? 'lvl-warn' : 'muted' ?>"><?= $v->t($smartBad > 0 ? 'host.smart_problems' : 'host.smart_ok', ['n' => count($h['smart']), 'bad' => $smartBad]) ?></p>
    <?php endif; ?>
</div>
