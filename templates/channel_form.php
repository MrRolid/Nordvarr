<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed>|null $channel */
/** @var string $type */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $deliveries */
/** @var array<string, list<array<string, mixed>>> $attempts */
/** @var array<string, bool> $secrets */
$isNew = $channel === null;
$err = static fn (string $f): string => isset($errors[$f]) ? '<p class="field-error">' . $v->t('err.' . $errors[$f]) . '</p>' : '';
$val = static fn (string $key): string => (string) ($values['cfg'][$type][$key] ?? '');
$field = static function (string $key, string $label, string $inputType = 'text', string $extra = '') use ($v, $type, $val, $err, $secrets): string {
    $id = 'ch-' . $key;
    $isSecret = $inputType === 'password';
    $placeholder = $isSecret && ($secrets[$key] ?? false) ? ' placeholder="' . $v->t('channel.keep_secret') . '"' : '';

    return '<label for="' . $v->e($id) . '">' . $v->t($label) . '</label>'
        . '<input id="' . $v->e($id) . '" name="cfg[' . $v->e($type) . '][' . $v->e($key) . ']" type="' . $v->e($inputType) . '"'
        . ($isSecret ? ' autocomplete="new-password"' : ' value="' . $v->e($val($key)) . '"') . $placeholder . $extra . '>' . $err($key);
};
$server = static function (string $prefix) use ($v, $type, $val, $field, $err): string {
    $html = $field($prefix . '_host', 'channel.smtp_host') . $field($prefix . '_port', 'channel.smtp_port', 'number', ' min="1" max="65535"');
    $html .= '<label for="ch-' . $prefix . '_security">' . $v->t('channel.smtp_security') . '</label><select id="ch-' . $prefix . '_security" name="cfg[' . $v->e($type) . '][' . $prefix . '_security]">';
    foreach (['starttls', 'ssl', 'none'] as $s) {
        $html .= '<option value="' . $s . '"' . (($val($prefix . '_security') ?: 'starttls') === $s ? ' selected' : '') . '>' . $v->t('channel.security.' . $s) . '</option>';
    }
    $html .= '</select>' . $err($prefix . '_security');

    return $html . $field($prefix . '_username', 'channel.smtp_username') . $field($prefix . '_password', 'channel.smtp_password', 'password');
};
?>
<p><a href="<?= $v->url('/settings') ?>#channels"><?= $v->t('nav.settings') ?></a></p>
<h1><?= $v->t($isNew ? 'channel.new' : 'channel.edit') ?></h1>
<?php if (!$isNew): ?>
<section class="card">
    <form method="post" action="<?= $v->url('/settings/channels/' . $channel['id'] . '/test') ?>" class="inline-form">
        <?= $v->csrfField() ?>
        <button type="submit" class="primary" data-test="send-test"><?= $v->t('channel.send_test') ?></button>
        <span class="help"><?= $v->t('channel.send_test_help') ?></span>
    </form>
</section>
<?php endif; ?>
<section class="card">
    <form method="post" action="<?= $v->url($isNew ? '/settings/channels' : '/settings/channels/' . $channel['id']) ?>" class="form" data-test="channel-form">
        <?= $v->csrfField() ?>
        <?php if ($isNew): ?>
        <p>
            <?php foreach (\Nordvarr\Notify\ChannelTypes::TYPES as $t): ?>
            <a class="button<?= $t === $type ? ' primary' : '' ?>" href="<?= $v->url('/settings/channels/new', ['type' => $t]) ?>"><?= $v->t('channeltype.' . $t) ?></a>
            <?php endforeach; ?>
        </p>
        <input type="hidden" name="type" value="<?= $v->e($type) ?>">
        <?php else: ?>
        <p><strong><?= $v->t('channeltype.' . $type) ?></strong></p>
        <?php endif; ?>
        <label for="ch-name"><?= $v->t('channel.name') ?></label>
        <input id="ch-name" name="name" type="text" maxlength="100" required value="<?= $v->e($values['name'] ?? '') ?>">
        <?= $v->raw($err('name')) ?>

        <?php if ($type === 'email'): ?>
        <?= $v->raw($field('from', 'channel.from', 'email')) ?>
        <?= $v->raw($field('to', 'channel.to')) ?>
        <p class="help"><?= $v->t('channel.to_help') ?></p>
        <fieldset><legend><?= $v->t('channel.primary') ?></legend><?= $v->raw($server('primary')) ?></fieldset>
        <fieldset><legend><?= $v->t('channel.fallback') ?></legend><?= $v->raw($server('fallback')) ?><p class="help"><?= $v->t('channel.fallback_help') ?></p></fieldset>
        <input type="hidden" name="cfg[email][verify_tls]" value="0">
        <label class="check-label"><input type="checkbox" name="cfg[email][verify_tls]" value="1"<?= ($val('verify_tls') ?: '1') === '1' ? ' checked' : '' ?>> <?= $v->t('channel.verify_tls') ?></label>
        <?php elseif ($type === 'telegram'): ?>
        <?= $v->raw($field('bot_token', 'channel.bot_token', 'password')) ?>
        <?= $v->raw($field('chat_id', 'channel.chat_id')) ?>
        <p class="help"><?= $v->t('channel.telegram_help') ?></p>
        <?php elseif ($type === 'discord'): ?>
        <?= $v->raw($field('url', 'channel.discord_url', 'password')) ?>
        <p class="help"><?= $v->t('channel.discord_help') ?></p>
        <?php else: ?>
        <?= $v->raw($field('url', 'channel.url', 'url')) ?>
        <?= $v->raw($field('secret', 'channel.secret', 'password')) ?>
        <p class="help"><?= $v->t('channel.webhook_help') ?></p>
        <?php endif; ?>

        <label for="ch-tags"><?= $v->t('channel.tags') ?></label>
        <input id="ch-tags" name="tags" type="text" value="<?= $v->e($values['tags'] ?? '') ?>">
        <p class="help"><?= $v->t('channel.tags_help') ?></p>
        <?= $v->raw($err('tags')) ?>
        <input type="hidden" name="enabled" value="0">
        <label class="check-label"><input type="checkbox" name="enabled" value="1"<?= !empty($values['enabled']) ? ' checked' : '' ?>> <?= $v->t('channel.enabled') ?></label>
        <div class="row">
            <button type="submit" class="primary"><?= $v->t('common.save') ?></button>
            <a href="<?= $v->url('/settings') ?>#channels"><?= $v->t('common.cancel') ?></a>
        </div>
    </form>
</section>
<?php if (!$isNew): ?>
<section class="card">
    <h2><?= $v->t('delivery.title') ?></h2>
    <?= $v->raw($v->render('partials/deliveries', ['deliveries' => $deliveries, 'attempts' => $attempts, 'showChannel' => false])) ?>
</section>
<section class="card danger-zone">
    <h2><?= $v->t('channel.delete') ?></h2>
    <form method="post" action="<?= $v->url('/settings/channels/' . $channel['id'] . '/delete') ?>" class="inline-form">
        <?= $v->csrfField() ?>
        <label class="check-label"><input type="checkbox" name="confirm" value="1" required> <?= $v->t('channel.delete_confirm') ?></label>
        <button type="submit" class="danger"><?= $v->t('channel.delete') ?></button>
    </form>
</section>
<?php endif; ?>
