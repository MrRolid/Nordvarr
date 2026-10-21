<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed>|null $check */
/** @var array<string, mixed> $object */
/** @var string $type */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
$isNew = $check === null;
$action = $isNew ? '/objects/' . $object['id'] . '/checks' : '/checks/' . $check['id'] . '/edit';
$err = static fn (string $field): string => isset($errors[$field])
    ? '<p class="field-error">' . $v->t('err.' . $errors[$field]) . '</p>' : '';
$cfg = static fn (string $t, string $key): string => (string) ($values['cfg'][$t][$key] ?? '');
$field = static function (string $t, string $key, string $label, string $inputType = 'text', string $extra = '') use ($v, $cfg, $err): string {
    $id = 'cfg-' . $t . '-' . $key;

    return '<label for="' . $v->e($id) . '">' . $v->t($label) . '</label>'
        . '<input id="' . $v->e($id) . '" name="cfg[' . $v->e($t) . '][' . $v->e($key) . ']" type="' . $v->e($inputType) . '" value="'
        . $v->e($cfg($t, $key)) . '"' . $extra . '>' . $err($key);
};
?>
<p><a href="<?= $v->url('/objects/' . $object['id']) ?>"><?= $v->e($object['name']) ?></a></p>
<h1><?= $v->t($isNew ? 'check.new' : 'check.edit') ?></h1>
<section class="card">
    <form method="post" action="<?= $v->url($action) ?>" class="form" data-test="check-form" data-check-form>
        <?= $v->csrfField() ?>
        <?php if ($isNew): ?>
        <label for="check-type"><?= $v->t('check.type') ?></label>
        <select id="check-type" name="type" data-check-type>
            <?php foreach (\Nordvarr\Checks\CheckTypes::TYPES as $t): ?>
            <?php $d = \Nordvarr\Checks\CheckTypes::defaults($t); ?>
            <option value="<?= $v->e($t) ?>"<?= $t === $type ? ' selected' : '' ?> data-interval="<?= $v->e($d['interval_s']) ?>"
                data-threshold="<?= $v->e($d['failure_threshold']) ?>" data-notify="<?= $d['notify_on_degraded'] ? '1' : '0' ?>"><?= $v->t('checktype.' . $t) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $v->raw($err('type')) ?>
        <?php else: ?>
        <p><strong><?= $v->t('checktype.' . $type) ?></strong></p>
        <?php endif; ?>

        <label for="check-name"><?= $v->t('check.name') ?></label>
        <input id="check-name" name="name" type="text" maxlength="100" value="<?= $v->e($values['name'] ?? '') ?>">
        <p class="help"><?= $v->t('check.name_help') ?></p>
        <?= $v->raw($err('name')) ?>

        <?php foreach (\Nordvarr\Checks\CheckTypes::TYPES as $t): ?>
        <?php if (!$isNew && $t !== $type) { continue; } ?>
        <fieldset data-type-fields="<?= $v->e($t) ?>">
            <legend><?= $v->t('checktype.' . $t) ?></legend>
            <?php if ($t === 'http'): ?>
            <?= $v->raw($field('http', 'url', 'check.url', 'url', ' placeholder="https://www.example.com/"')) ?>
            <label for="cfg-http-method"><?= $v->t('check.method') ?></label>
            <select id="cfg-http-method" name="cfg[http][method]">
                <?php foreach (['GET', 'HEAD'] as $m): ?>
                <option value="<?= $v->e($m) ?>"<?= $cfg('http', 'method') === $m ? ' selected' : '' ?>><?= $v->e($m) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $v->raw($err('method')) ?>
            <?= $v->raw($field('http', 'expected_status', 'check.expected_status')) ?>
            <?= $v->raw($field('http', 'contains', 'check.contains')) ?>
            <?= $v->raw($field('http', 'latency_degraded_ms', 'check.latency', 'number', ' min="0"')) ?>
            <p class="help"><?= $v->t('check.http_help') ?></p>
            <?php elseif ($t === 'tcp'): ?>
            <?= $v->raw($field('tcp', 'host', 'check.host')) ?>
            <?= $v->raw($field('tcp', 'port', 'check.port', 'number', ' min="1" max="65535"')) ?>
            <?php elseif ($t === 'ping'): ?>
            <?= $v->raw($field('ping', 'host', 'check.host')) ?>
            <?php elseif ($t === 'tls_expiry'): ?>
            <?= $v->raw($field('tls_expiry', 'host', 'check.host')) ?>
            <?= $v->raw($field('tls_expiry', 'port', 'check.port', 'number', ' min="1" max="65535"')) ?>
            <?= $v->raw($field('tls_expiry', 'degraded_days', 'check.degraded_days', 'number', ' min="0"')) ?>
            <?= $v->raw($field('tls_expiry', 'unhealthy_days', 'check.unhealthy_days', 'number', ' min="0"')) ?>
            <?php elseif ($t === 'domain_expiry'): ?>
            <?= $v->raw($field('domain_expiry', 'domain', 'check.domain', 'text', ' placeholder="example.com"')) ?>
            <?= $v->raw($field('domain_expiry', 'degraded_days', 'check.degraded_days', 'number', ' min="0"')) ?>
            <?= $v->raw($field('domain_expiry', 'unhealthy_days', 'check.unhealthy_days', 'number', ' min="0"')) ?>
            <p class="help"><?= $v->t('check.domain_help') ?></p>
            <?php elseif ($t === 'heartbeat'): ?>
            <?= $v->raw($field('heartbeat', 'grace_s', 'check.grace', 'number', ' min="0"')) ?>
            <p class="help"><?= $v->t('check.heartbeat_help') ?></p>
            <?php endif; ?>
        </fieldset>
        <?php endforeach; ?>

        <label for="interval_s"><?= $v->t('check.interval') ?></label>
        <input id="interval_s" name="interval_s" type="number" min="60" value="<?= $v->e($values['interval_s'] ?? '') ?>">
        <?= $v->raw($err('interval_s')) ?>
        <label for="timeout_s"><?= $v->t('check.timeout') ?></label>
        <input id="timeout_s" name="timeout_s" type="number" min="1" max="30" value="<?= $v->e($values['timeout_s'] ?? '') ?>">
        <?= $v->raw($err('timeout_s')) ?>
        <label for="failure_threshold"><?= $v->t('check.threshold') ?></label>
        <input id="failure_threshold" name="failure_threshold" type="number" min="1" max="10" value="<?= $v->e($values['failure_threshold'] ?? '') ?>">
        <p class="help"><?= $v->t('check.threshold_help') ?></p>
        <?= $v->raw($err('failure_threshold')) ?>
        <input type="hidden" name="notify_on_degraded" value="0">
        <label class="check-label"><input type="checkbox" name="notify_on_degraded" value="1" id="notify_on_degraded"<?= !empty($values['notify_on_degraded']) ? ' checked' : '' ?>> <?= $v->t('check.notify_degraded') ?></label>
        <input type="hidden" name="enabled" value="0">
        <label class="check-label"><input type="checkbox" name="enabled" value="1"<?= !empty($values['enabled']) ? ' checked' : '' ?>> <?= $v->t('check.enabled_label') ?></label>

        <div class="row">
            <button type="submit" class="primary"><?= $v->t('common.save') ?></button>
            <a href="<?= $v->url($isNew ? '/objects/' . $object['id'] : '/checks/' . $check['id']) ?>"><?= $v->t('common.cancel') ?></a>
        </div>
    </form>
</section>
