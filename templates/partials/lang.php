<?php
/** @var \Nordvarr\View\View $v */
?>
<?php if ($v->shared('installer') === true): ?>
<nav class="lang-switch" aria-label="<?= $v->t('lang.label') ?>">
    <?php foreach (['cs', 'en'] as $code): ?>
    <a href="install.php?lang=<?= $v->e($code) ?>"<?= $v->lang() === $code ? ' aria-current="true"' : '' ?>><?= $v->t('lang.' . $code) ?></a>
    <?php endforeach; ?>
</nav>
<?php return; endif; ?>
<form method="post" action="<?= $v->url('/lang') ?>" class="lang-switch">
    <?= $v->csrfField() ?>
    <input type="hidden" name="back" value="<?= $v->e($v->shared('path', '/')) ?>">
    <label for="lang-select" class="visually-hidden"><?= $v->t('lang.label') ?></label>
    <select id="lang-select" name="lang" data-autosubmit>
        <?php foreach (['cs', 'en'] as $code): ?>
        <option value="<?= $v->e($code) ?>"<?= $v->lang() === $code ? ' selected' : '' ?>><?= $v->t('lang.' . $code) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="js-hidden"><?= $v->t('lang.switch') ?></button>
</form>
