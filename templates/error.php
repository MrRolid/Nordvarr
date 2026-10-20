<?php
/** @var \Nordvarr\View\View $v */
/** @var string $message */
?>
<section class="card narrow">
    <h1><?= $v->t('error.title') ?></h1>
    <p data-test="error-message"><?= $v->t($message) ?></p>
    <p><a href="<?= $v->url('/') ?>"><?= $v->t('error.back_home') ?></a></p>
</section>
