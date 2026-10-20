<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed>|null $object */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
$action = $object === null ? '/objects' : '/objects/' . $object['id'] . '/edit';
?>
<h1><?= $v->t($object === null ? 'object.new' : 'object.edit') ?></h1>
<section class="card">
    <form method="post" action="<?= $v->url($action) ?>" class="form" data-test="object-form">
        <?= $v->csrfField() ?>
        <label for="type"><?= $v->t('object.type') ?></label>
        <select id="type" name="type">
            <?php foreach (\Nordvarr\Inventory\Vocabulary::OBJECT_TYPES as $type): ?>
            <option value="<?= $v->e($type) ?>"<?= ($values['type'] ?? '') === $type ? ' selected' : '' ?>><?= $v->t('type.' . $type) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['type'])): ?><p class="field-error"><?= $v->t('err.' . $errors['type']) ?></p><?php endif; ?>

        <label for="name"><?= $v->t('object.name') ?></label>
        <input id="name" name="name" type="text" maxlength="200" required value="<?= $v->e($values['name'] ?? '') ?>">
        <?php if (isset($errors['name'])): ?><p class="field-error"><?= $v->t('err.' . $errors['name']) ?></p><?php endif; ?>

        <label for="description"><?= $v->t('object.description') ?></label>
        <textarea id="description" name="description" rows="3" maxlength="2000"><?= $v->e($values['description'] ?? '') ?></textarea>
        <?php if (isset($errors['description'])): ?><p class="field-error"><?= $v->t('err.' . $errors['description']) ?></p><?php endif; ?>

        <div class="row">
            <button type="submit" class="primary"><?= $v->t('common.save') ?></button>
            <a href="<?= $v->url($object === null ? '/inventory' : '/objects/' . $object['id']) ?>"><?= $v->t('common.cancel') ?></a>
        </div>
    </form>
</section>
