<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $host data z Nordvarr\Agent\HostView::build() */
use Nordvarr\View\Svg;

$h = $host;
$gauge = static function (string $label, ?float $pct, string $level, string $value, string $note = '') use ($v): string {
    return '<div class="gauge gauge-' . $v->e($level) . '"><div class="gauge-head"><span>' . $v->e($label) . '</span><strong>' . $value . '</strong></div>'
        . Svg::bar($pct, $level) . ($note !== '' ? '<p class="gauge-note">' . $note . '</p>' : '') . '</div>';
};
?>
<section class="card host" id="host" data-test="host">
    <div class="page-head">
        <h2><?= $v->t('host.title') ?></h2>
        <span class="muted small-text" data-test="host-last-report"><?= $v->t('host.last_report', ['ago' => $v->ago($h['last_report_at'])]) ?></span>
    </div>
    <p class="muted host-meta">
        <?= $v->e($h['hostname']) ?><?php if ($h['os'] !== ''): ?>, <?= $v->e($h['os']) ?><?php endif; ?><?php if ($h['kernel'] !== ''): ?>, <?= $v->t('host.kernel') ?> <?= $v->e($h['kernel']) ?><?php endif; ?>,
        <?= $v->t('host.uptime') ?> <?= $v->uptime($h['uptime_s']) ?>, <?= $v->t('host.agent') ?> <?= $v->e($h['agent_version']) ?>
    </p>
    <?php if ($h['stale']): ?>
    <p class="alert-box" data-test="host-stale"><?= $v->t('host.stale', ['time' => $v->time($h['last_report_at'])]) ?></p>
    <?php endif; ?>
    <?php if ($h['problems'] !== []): ?>
    <ul class="host-problems" data-test="host-problems">
        <?php foreach ($h['problems'] as $p): ?>
        <li class="problem-<?= $v->e($p['severity']) ?>"><?= $v->raw($v->render('partials/status', ['status' => $p['severity']])) ?> <?= $v->e($p['text']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <div class="gauges" data-test="host-gauges">
        <?= $v->raw($gauge('CPU', $h['cpu']['pct'], $h['cpu']['level'], $v->num($h['cpu']['pct']) . ' %',
            $v->t('host.cores', ['n' => (int) $h['cpu']['cores']]) . ', load ' . $v->num($h['cpu']['load'][0], 2) . ' / ' . $v->num($h['cpu']['load'][1], 2) . ' / ' . $v->num($h['cpu']['load'][2], 2))) ?>
        <?= $v->raw($gauge('RAM', $h['mem']['pct'], $h['mem']['level'], $v->num($h['mem']['pct']) . ' %',
            $v->bytes($h['mem']['used_b']) . ' / ' . $v->bytes($h['mem']['total_b']))) ?>
        <?php if (($h['swap']['total_b'] ?? 0) > 0): ?>
        <?= $v->raw($gauge('Swap', $h['swap']['pct'], $h['swap']['level'], $v->num($h['swap']['pct']) . ' %',
            $v->bytes($h['swap']['used_b']) . ' / ' . $v->bytes($h['swap']['total_b']))) ?>
        <?php endif; ?>
        <?php if ($h['disk_max'] !== null): ?>
        <?= $v->raw($gauge($v->t('host.disk_max'), $h['disk_max'], $h['disk_max_level'], $v->num($h['disk_max']) . ' %')) ?>
        <?php endif; ?>
        <?php if ($h['temp_max'] !== null): ?>
        <?= $v->raw($gauge($v->t('host.temp_max'), $h['temp_max'], $h['temp_max_level'], $v->num($h['temp_max']) . ' °C', $v->e($h['temps'][0]['name']))) ?>
        <?php endif; ?>
        <?php foreach ($h['gpus'] as $g): ?>
        <?= $v->raw($gauge('GPU ' . (int) $g['index'], $g['util_pct'], $g['util_level'], $v->num($g['util_pct']) . ' %',
            $v->e($g['name']) . '<br>VRAM ' . $v->num($g['mem_used_mb'] !== null ? $g['mem_used_mb'] / 1024 : null, 1) . ' / ' . $v->num($g['mem_total_mb'] !== null ? $g['mem_total_mb'] / 1024 : null, 1) . ' GiB'
            . ', <span class="lvl-' . $v->e($g['temp_level']) . '">' . $v->num($g['temp_c']) . ' °C</span>'
            . ($g['power_w'] !== null ? ', ' . $v->num($g['power_w']) . ' W' : ''))) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($h['chart'] !== null): ?>
    <figure class="host-chart" data-test="host-chart">
        <?= $v->raw($h['chart']) ?>
        <figcaption>
            <span class="legend-item"><i class="lg lg-cpu"></i>CPU %</span>
            <span class="legend-item"><i class="lg lg-mem"></i>RAM %</span>
            <span class="legend-item"><i class="lg lg-temp"></i><?= $v->t('host.temp_max') ?> (0-110 °C)</span>
            <?php if ($h['has_gpu_series']): ?><span class="legend-item"><i class="lg lg-gpu"></i>GPU %</span><?php endif; ?>
            <span class="muted"><?= $v->t('host.chart_24h') ?></span>
        </figcaption>
    </figure>
    <?php endif; ?>

    <?php if ($h['disks'] !== []): ?>
    <h3><?= $v->t('host.disks') ?></h3>
    <div class="table-wrap">
    <table class="list host-disks" data-test="host-disks">
        <thead><tr><th><?= $v->t('host.mount') ?></th><th><?= $v->t('host.fill') ?></th><th><?= $v->t('host.used') ?></th><th><?= $v->t('host.inodes') ?></th><th><?= $v->t('host.device') ?></th></tr></thead>
        <tbody>
        <?php foreach ($h['disks'] as $d): ?>
        <tr>
            <td><code><?= $v->e($d['mount']) ?></code></td>
            <td class="cell-bar"><?= $v->raw(Svg::bar($d['pct'], $d['level'])) ?> <span class="lvl-<?= $v->e($d['level']) ?>"><?= $v->num($d['pct']) ?> %</span></td>
            <td class="nowrap"><?= $v->bytes($d['used_b']) ?> / <?= $v->bytes($d['size_b']) ?></td>
            <td class="lvl-<?= $v->e($d['inodes_level']) ?>"><?= $d['inodes_pct'] === null ? '-' : $v->num($d['inodes_pct']) . ' %' ?></td>
            <td class="muted small-text"><?= $v->e($d['device']) ?> <?= $v->e($d['fs']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h3><?= $v->t('host.smart') ?></h3>
    <?php if ($h['smart'] === []): ?>
    <p class="muted" data-test="host-smart-empty"><?= $v->t($h['smart_error'] !== '' ? 'host.smart_error.' . (in_array($h['smart_error'], ['smartctl_missing', 'not_root'], true) ? $h['smart_error'] : 'other') : 'host.smart_none') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list host-smart" data-test="host-smart">
        <thead><tr><th><?= $v->t('host.disk') ?></th><th><?= $v->t('host.health') ?></th><th><?= $v->t('host.wear') ?></th><th><?= $v->t('host.temp') ?></th><th><?= $v->t('host.power_on') ?></th><th><?= $v->t('host.problems') ?></th></tr></thead>
        <tbody>
        <?php foreach ($h['smart'] as $d): ?>
        <tr class="row-<?= $v->e($d['level']) ?>">
            <td><code><?= $v->e($d['device']) ?></code><br><span class="muted small-text"><?= $v->e($d['model']) ?></span></td>
            <td><span class="badge health-<?= $v->e($d['level']) ?>"><?= $v->t($d['health_passed'] === false ? 'host.health_failing' : ($d['level'] === 'ok' ? 'host.health_ok' : 'host.health_warn')) ?></span></td>
            <td class="cell-bar"><?php if ($d['wear_pct'] !== null): ?><?= $v->raw(Svg::bar($d['wear_pct'], $d['wear_level'])) ?> <?= $v->num($d['wear_pct']) ?> %<?php else: ?>-<?php endif; ?></td>
            <td><?= $d['temp_c'] === null ? '-' : $v->num($d['temp_c']) . ' °C' ?></td>
            <td class="nowrap"><?= $d['power_on_hours'] === null ? '-' : $v->num((float) $d['power_on_hours']) . ' h' ?></td>
            <td><?php if ($d['problems'] === []): ?><span class="muted">-</span><?php else: ?><ul class="plain"><?php foreach ($d['problems'] as $p): ?><li class="lvl-<?= $p['severity'] === 'unhealthy' ? 'bad' : 'warn' ?>"><?= $v->e($p['text']) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted small-text"><?= $v->t('host.smart_read', ['ago' => $v->ago($h['smart_at'])]) ?><?php if ($h['smart_stale']): ?> <span class="badge warn"><?= $v->t('host.smart_stale') ?></span><?php endif; ?></p>
    <?php endif; ?>

    <?php if ($h['temps'] !== []): ?>
    <details class="host-more">
        <summary><?= $v->t('host.temps_all', ['n' => count($h['temps'])]) ?></summary>
        <table class="list" data-test="host-temps">
            <thead><tr><th><?= $v->t('host.sensor') ?></th><th><?= $v->t('host.temp') ?></th><th><?= $v->t('host.limits') ?></th></tr></thead>
            <tbody>
            <?php foreach ($h['temps'] as $t): ?>
            <tr><td><?= $v->e($t['name']) ?></td><td class="lvl-<?= $v->e($t['level']) ?>"><?= $v->num($t['c'], 1) ?> °C</td>
                <td class="muted small-text"><?= $t['max_c'] !== null ? 'max ' . $v->num($t['max_c']) . ' °C' : '' ?><?= $t['max_c'] !== null && $t['crit_c'] !== null ? ', ' : '' ?><?= $t['crit_c'] !== null ? 'crit ' . $v->num($t['crit_c']) . ' °C' : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>
    <?php if ($h['network'] !== []): ?>
    <p class="muted small-text host-net"><?= $v->t('host.network') ?>:
        <?php foreach ($h['network'] as $i => $n): ?><?= $i > 0 ? '; ' : '' ?><?= $v->e($n['iface']) ?> <?= $v->t('host.rx') ?> <?= $v->bytes($n['rx_bps']) ?>/s, <?= $v->t('host.tx') ?> <?= $v->bytes($n['tx_bps']) ?>/s<?php endforeach; ?>
    </p>
    <?php endif; ?>
</section>
