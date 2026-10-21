<?php
/** @var \Nordvarr\View\View $v */
/** @var string $baseUrl */
/** @var string $filesUrl */
$install = "curl -fsSL " . $filesUrl . "nordvarr-agent.sh -o /usr/local/bin/nordvarr-agent.sh\n"
    . "chmod 755 /usr/local/bin/nordvarr-agent.sh\n"
    . "curl -fsSL " . $filesUrl . "nordvarr-agent.service -o /etc/systemd/system/nordvarr-agent.service\n"
    . "curl -fsSL " . $filesUrl . "nordvarr-agent.timer -o /etc/systemd/system/nordvarr-agent.timer\n"
    . "apt install smartmontools   # stav disků (SMART)";
$config = "install -m 600 /dev/null /etc/nordvarr-agent.conf\n"
    . "cat > /etc/nordvarr-agent.conf <<'KONEC'\n"
    . "NORDVARR_URL=" . $baseUrl . "\n"
    . "NORDVARR_TOKEN=nv_...\n"
    . "KONEC";
$test = "/usr/local/bin/nordvarr-agent.sh --test\n"
    . "systemctl daemon-reload\n"
    . "systemctl enable --now nordvarr-agent.timer";
?>
<p><a href="<?= $v->url('/settings') ?>"><?= $v->t('nav.settings') ?></a></p>
<h1><?= $v->t('agent.title') ?></h1>
<section class="card agent-guide" data-test="agent-page">
    <p><?= $v->t('settings.agent_intro') ?></p>
    <ol class="steps">
        <li><?= $v->t('agent.step_token') ?></li>
        <li><?= $v->t('agent.step_install') ?><pre class="code" data-test="agent-install"><?= $v->e($install) ?></pre></li>
        <li><?= $v->t('agent.step_config') ?><pre class="code"><?= $v->e($config) ?></pre></li>
        <li><?= $v->t('agent.step_test') ?><pre class="code"><?= $v->e($test) ?></pre></li>
    </ol>
    <p><?= $v->t('agent.step_done') ?></p>
    <p class="muted small-text"><?= $v->t('agent.cron_alternative') ?></p>
    <h2><?= $v->t('agent.files') ?></h2>
    <ul>
        <?php foreach (['nordvarr-agent.sh', 'nordvarr-agent.service', 'nordvarr-agent.timer', 'nordvarr-agent.conf.example'] as $file): ?>
        <li><a href="<?= $v->e($filesUrl . $file) ?>"><?= $v->e($file) ?></a></li>
        <?php endforeach; ?>
    </ul>
</section>
