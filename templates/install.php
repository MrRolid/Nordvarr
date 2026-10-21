<?php
/** @var \Nordvarr\View\View $v */
/** @var list<array{key: string, params: array<string, string>, ok: bool}> $requirements */
/** @var bool $requirementsMet */
/** @var array<string, string> $input */
/** @var array<string, string> $errors */
/** @var string|null $generalError */
/** @var list<string> $timezones */
/** @var int $minPassword */
$field = static function (string $name) use ($v, $errors): string {
    return isset($errors[$name])
        ? '<p class="field-error" id="err-' . $v->e($name) . '">' . $v->t($errors[$name], ['min' => (string) \Nordvarr\Security\Passwords::MIN_LENGTH]) . '</p>'
        : '';
};
?>
<h1><?= $v->t('install.title') ?></h1>

<section class="card">
    <h2><?= $v->t('install.requirements') ?></h2>
    <ul class="checklist">
        <?php foreach ($requirements as $req): ?>
        <li class="<?= $req['ok'] ? 'ok' : 'fail' ?>">
            <?= $v->t($req['key'], $req['params']) ?>: <strong><?= $v->t($req['ok'] ? 'install.req_ok' : 'install.req_fail') ?></strong>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$requirementsMet): ?>
    <p class="alert-box" role="alert"><?= $v->t('install.req_blocking') ?></p>
    <?php endif; ?>
</section>

<?php if ($generalError !== null): ?>
<p class="alert-box" role="alert"><?= $v->e($generalError) ?></p>
<?php endif; ?>

<?php if ($requirementsMet): ?>
<section class="card">
    <form method="post" action="install.php?lang=<?= $v->e($v->lang()) ?>" class="form" data-test="install-form">
        <?= $v->csrfField() ?>
        <label for="data_dir"><?= $v->t('install.data_dir') ?></label>
        <input id="data_dir" name="data_dir" type="text" required value="<?= $v->e($input['data_dir']) ?>">
        <p class="help"><?= $v->t('install.data_dir_help') ?></p>
        <?= $v->raw($field('data_dir')) ?>

        <label for="email"><?= $v->t('install.admin_email') ?></label>
        <input id="email" name="email" type="email" autocomplete="username" required value="<?= $v->e($input['email']) ?>">
        <?= $v->raw($field('email')) ?>

        <label for="password"><?= $v->t('install.admin_password') ?></label>
        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="<?= $v->e($minPassword) ?>">
        <p class="help"><?= $v->t('install.admin_password_help', ['min' => $minPassword]) ?></p>
        <?= $v->raw($field('password')) ?>

        <label for="password_confirm"><?= $v->t('install.admin_password_confirm') ?></label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
        <?= $v->raw($field('password_confirm')) ?>

        <label for="timezone"><?= $v->t('install.timezone') ?></label>
        <select id="timezone" name="timezone">
            <?php foreach ($timezones as $tz): ?>
            <option value="<?= $v->e($tz) ?>"<?= $tz === $input['timezone'] ? ' selected' : '' ?>><?= $v->e($tz) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $v->raw($field('timezone')) ?>

        <button type="submit" class="primary"><?= $v->t('install.submit') ?></button>
    </form>
</section>
<?php endif; ?>
