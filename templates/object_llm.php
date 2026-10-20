<?php
/** @var \Nordvarr\View\View $v */
/** @var array{id: string, name: string} $object */
/** @var string $markdown */
/** @var string $sizeKb */
/** @var bool $truncated */
?>
<p><a href="<?= $v->url('/objects/' . $object['id']) ?>"><?= $v->e($object['name']) ?></a></p>
<h1><?= $v->t('llm.page_title') ?>: <?= $v->e($object['name']) ?></h1>
<p class="muted"><?= $v->t('llm.intro') ?></p>
<section class="card llm">
    <div class="row llm-tools">
        <button type="button" class="primary" data-copy-target="llm-text" data-copied="<?= $v->t('llm.copied') ?>" data-copy-failed="<?= $v->t('llm.copy_failed') ?>" data-test="copy"><?= $v->t('llm.copy') ?></button>
        <span class="muted small-text" data-test="llm-size"><?= $v->t('llm.size', ['kb' => $sizeKb]) ?></span>
        <span class="small-text" role="status" aria-live="polite" data-copy-status="llm-text"></span>
    </div>
    <label for="llm-text" class="visually-hidden"><?= $v->t('llm.text_label') ?></label>
    <textarea id="llm-text" class="code llm-text" rows="28" readonly spellcheck="false" data-test="llm-text"><?= $v->e($markdown) ?></textarea>
    <p class="muted small-text"><?= $v->t('llm.api_hint', ['id' => $object['id']]) ?></p>
</section>
