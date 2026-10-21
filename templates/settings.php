<?php
/** @var \Nordvarr\View\View $v */
/** @var string $timezone */
/** @var list<string> $timezones */
/** @var list<array<string, mixed>> $tokens */
/** @var list<string> $scopes */
/** @var string $cronLine */
/** @var string|null $newToken */
/** @var string|null $tokenError */
/** @var array{name: string, source: string, scopes: list<string>} $tokenValues */
?>
<h1><?= $v->t('nav.settings') ?></h1>

<section class="card" id="tokens">
    <h2><?= $v->t('settings.tokens') ?></h2>
    <?php if ($newToken !== null): ?>
    <div class="notice" role="status">
        <p><?= $v->t('settings.token_created') ?></p>
        <pre class="code" data-test="new-token"><?= $v->e($newToken) ?></pre>
    </div>
    <?php endif; ?>
    <?php if ($tokens === []): ?>
    <p class="muted"><?= $v->t('settings.no_tokens') ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="list" data-test="tokens">
        <thead><tr>
            <th><?= $v->t('settings.token_name') ?></th><th><?= $v->t('settings.token_source') ?></th>
            <th><?= $v->t('settings.token_scopes') ?></th><th><?= $v->t('settings.token_prefix') ?></th>
            <th><?= $v->t('settings.token_last_used') ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($tokens as $token): ?>
        <tr<?= $token['revoked_at'] !== null ? ' class="archived"' : '' ?>>
            <td><?= $v->e($token['name']) ?></td>
            <td><code><?= $v->e($token['source']) ?></code></td>
            <td><?= $v->e($token['scopes']) ?></td>
            <td><code>nv_<?= $v->e($token['prefix']) ?>_...</code></td>
            <td class="nowrap"><?= $token['last_used_at'] !== null ? $v->time((string) $token['last_used_at']) : $v->t('settings.never') ?></td>
            <td>
                <?php if ($token['revoked_at'] === null): ?>
                <form method="post" action="<?= $v->url('/settings/tokens/' . $token['id'] . '/revoke') ?>" class="inline">
                    <?= $v->csrfField() ?>
                    <button type="submit" class="link small"><?= $v->t('settings.token_revoke') ?></button>
                </form>
                <?php else: ?>
                <span class="badge"><?= $v->t('settings.token_revoked_label') ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h3><?= $v->t('settings.token_new') ?></h3>
    <?php if ($tokenError !== null): ?><p class="alert-box"><?= $v->t($tokenError) ?></p><?php endif; ?>
    <form method="post" action="<?= $v->url('/settings/tokens') ?>" class="form" data-test="token-form">
        <?= $v->csrfField() ?>
        <label for="token-name"><?= $v->t('settings.token_name') ?></label>
        <input id="token-name" name="name" type="text" maxlength="100" required value="<?= $v->e($tokenValues['name']) ?>">
        <label for="token-source"><?= $v->t('settings.token_source') ?></label>
        <input id="token-source" name="source" type="text" maxlength="50" required pattern="[a-z0-9][a-z0-9._\-]*" value="<?= $v->e($tokenValues['source']) ?>">
        <p class="help"><?= $v->t('settings.token_source_help') ?></p>
        <fieldset>
            <legend><?= $v->t('settings.token_scopes') ?></legend>
            <?php foreach ($scopes as $scope): ?>
            <label class="check-label"><input type="checkbox" name="scopes[]" value="<?= $v->e($scope) ?>"<?= in_array($scope, $tokenValues['scopes'], true) ? ' checked' : '' ?>> <code><?= $v->e($scope) ?></code></label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit" class="primary"><?= $v->t('settings.token_create') ?></button>
    </form>
</section>

<section class="card" id="transfer">
    <h2><?= $v->t('settings.transfer') ?></h2>
    <p><a class="button" href="<?= $v->url('/settings/export') ?>" data-test="export"><?= $v->t('settings.export') ?></a></p>
    <p class="help"><?= $v->t('settings.export_help') ?></p>
    <form method="post" action="<?= $v->url('/settings/import') ?>" enctype="multipart/form-data" class="form" data-test="import-form">
        <?= $v->csrfField() ?>
        <label for="import-file"><?= $v->t('settings.import_file') ?></label>
        <input id="import-file" name="file" type="file" accept="application/json,.json" required>
        <label class="check-label"><input type="checkbox" name="confirm" value="1" required> <?= $v->t('settings.import_confirm') ?></label>
        <button type="submit" class="danger"><?= $v->t('settings.import') ?></button>
    </form>
</section>

<section class="card" id="general">
    <h2><?= $v->t('settings.general') ?></h2>
    <form method="post" action="<?= $v->url('/settings/timezone') ?>" class="inline-form">
        <?= $v->csrfField() ?>
        <label for="timezone"><?= $v->t('install.timezone') ?></label>
        <select id="timezone" name="timezone">
            <?php foreach ($timezones as $tz): ?>
            <option value="<?= $v->e($tz) ?>"<?= $tz === $timezone ? ' selected' : '' ?>><?= $v->e($tz) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= $v->t('common.save') ?></button>
    </form>
    <p><?= $v->t('install.cron') ?></p>
    <pre class="code" data-test="cron-line"><?= $v->e($cronLine) ?></pre>
</section>
