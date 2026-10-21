<?php
/** @var \Nordvarr\View\View $v */
/** @var string $overviewHtml */
?>
<h1><?= $v->t('dashboard.title') ?></h1>
<div id="overview" data-refresh-url="<?= $v->url('/overview/data') ?>" aria-live="polite">
    <?= $v->raw($overviewHtml) ?>
</div>
