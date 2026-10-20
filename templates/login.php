<?php
/** @var \Nordvarr\View\View $v */
/** @var string $email */
/** @var string|null $error */
?>
<section class="card narrow">
    <h1><?= $v->t('login.title') ?></h1>
    <?php if ($error !== null): ?>
    <p class="alert-box" role="alert" data-test="login-error"><?= $v->t($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $v->url('/login') ?>" class="form">
        <?= $v->csrfField() ?>
        <label for="email"><?= $v->t('login.email') ?></label>
        <input id="email" name="email" type="email" autocomplete="username" required value="<?= $v->e($email) ?>">
        <label for="password"><?= $v->t('login.password') ?></label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit" class="primary"><?= $v->t('login.submit') ?></button>
    </form>
</section>
