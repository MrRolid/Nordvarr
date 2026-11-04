<?php
/** @var \Nordvarr\View\View $v */
/** @var array<string, mixed> $object */
/** @var array<string, mixed> $revision */
/** @var string $html */
$id = (string) $object['id'];
?>
<?php $category = (string) ($revision['category'] ?? \Nordvarr\Repository\DocRepository::MAIN); ?>
<p><a href="<?= $v->url('/objects/' . $id . '/docs', ['cat' => $category]) ?>"><?= $v->t('docs.revisions') ?></a></p>
<h1><?= $v->t('docs.revision_of', ['name' => (string) $object['name']]) ?></h1>
<p data-test="revision-category"><?= $v->t('docs.cat.' . $category) ?></p>
<p class="muted"><?= $v->e($revision['author']) ?>, <?= $v->time((string) $revision['created_at']) ?></p>
<section class="card">
    <div class="markdown"><?= $v->raw($html) ?></div>
</section>
<section class="card">
    <h2><?= $v->t('docs.source') ?></h2>
    <pre class="code"><?= $v->e($revision['markdown']) ?></pre>
</section>
