<?php
/** @var \Nordvarr\View\View $v */
/** @var string $cronLine */
/** @var string $dataDirStatus klíč hlášky o datovém adresáři */
/** @var bool $dataDirWarning */
/** @var string $loginUrl */
?>
<h1><?= $v->t('install.done_title') ?></h1>
<section class="card">
    <p data-test="install-done"><?= $v->t('install.done_text') ?></p>
    <p class="<?= $dataDirWarning ? 'alert-box' : 'notice' ?>"><?= $v->t($dataDirStatus) ?></p>
    <p><?= $v->t('install.cron') ?></p>
    <pre class="code" data-test="cron-line"><?= $v->e($cronLine) ?></pre>
    <p><a class="button primary" href="<?= $v->e($loginUrl) ?>"><?= $v->t('install.go_login') ?></a></p>
</section>
