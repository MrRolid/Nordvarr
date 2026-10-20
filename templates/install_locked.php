<?php
/** @var \Nordvarr\View\View $v */
/** @var string $loginUrl */
?>
<section class="card narrow">
    <h1><?= $v->t('install.title') ?></h1>
    <p data-test="install-locked"><?= $v->t('install.locked') ?></p>
    <p><a href="<?= $v->e($loginUrl) ?>"><?= $v->t('install.go_login') ?></a></p>
</section>
