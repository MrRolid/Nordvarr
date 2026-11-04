<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $object */
/** @var string $category */
/** @var list<string> $filled */
/** @var string $markdown */
/** @var string|null $error */
/** @var list<array<string, mixed>> $revisions */
$id = (string) $object['id'];
?>
<p><a href="<?= $v->url('/objects/' . $id) ?>"><?= $v->e($object['name']) ?></a></p>
<h1><?= $v->t('docs.edit_cat', ['category' => $v->t('docs.cat.' . $category)]) ?></h1>
<nav class="doc-tabs" data-test="docs-tabs" aria-label="<?= $v->t('docs.categories') ?>">
    <?php foreach (\Nordvarr\Repository\DocRepository::CATEGORIES as $cat): ?>
    <a href="<?= $v->url('/objects/' . $id . '/docs', ['cat' => $cat]) ?>"<?= $cat === $category ? ' class="active" aria-current="page"' : '' ?>><?= $v->t('docs.cat.' . $cat) ?><?= in_array($cat, $filled, true) ? '' : ' <span class="muted">(' . $v->t('docs.empty') . ')</span>' ?></a>
    <?php endforeach; ?>
</nav>
<section class="card">
    <?php if ($error !== null): ?><p class="alert-box"><?= $v->t($error) ?></p><?php endif; ?>
    <form method="post" action="<?= $v->url('/objects/' . $id . '/docs') ?>" class="form" data-test="docs-form">
        <?= $v->csrfField() ?>
        <input type="hidden" name="category" value="<?= $v->e($category) ?>">
        <p class="help"><?= $v->t('docs.cat_help.' . $category) ?></p>
        <label for="markdown"><?= $v->t('docs.markdown') ?></label>
        <textarea id="markdown" name="markdown" rows="18" class="mono"><?= $v->e($markdown) ?></textarea>
        <p class="help"><?= $v->t('docs.help') ?></p>
        <div class="row">
            <button type="submit" class="primary"><?= $v->t('common.save') ?></button>
            <a href="<?= $v->url('/objects/' . $id) ?>"><?= $v->t('common.cancel') ?></a>
        </div>
    </form>
</section>
<section class="card">
    <h2><?= $v->t('docs.revisions') ?></h2>
    <?php if ($revisions === []): ?>
    <p class="muted"><?= $v->t('docs.no_revisions') ?></p>
    <?php else: ?>
    <ol class="plain" data-test="revisions">
        <?php foreach ($revisions as $rev): ?>
        <li><a href="<?= $v->url('/objects/' . $id . '/docs/revisions/' . $rev['id']) ?>"><?= $v->time((string) $rev['created_at']) ?></a>
            <span class="muted"><?= $v->e($rev['author']) ?></span></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>
