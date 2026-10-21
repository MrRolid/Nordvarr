<?php
/** @var \Nordvarr\View\View $v */
/** @var list<array<string, mixed>> $deliveries */
/** @var array<string, list<array<string, mixed>>> $attempts */
?>
<p><a href="<?= $v->url('/alerts') ?>"><?= $v->t('nav.alerts') ?></a></p>
<h1><?= $v->t('delivery.title') ?></h1>
<section class="card">
    <p class="help"><?= $v->t('delivery.help') ?></p>
    <?= $v->raw($v->render('partials/deliveries', ['deliveries' => $deliveries, 'attempts' => $attempts, 'showChannel' => true])) ?>
</section>
