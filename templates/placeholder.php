<?php
/** @var \Nordvarr\View\View $v */
/** @var string $title */
/** @var string $phase */
?>
<h1><?= $v->t($title) ?></h1>
<section class="card">
    <p class="muted"><?= $v->t('placeholder.text', ['phase' => $phase]) ?></p>
</section>
