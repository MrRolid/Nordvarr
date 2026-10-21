<?php
/** @var \Nordvarr\View\View $v */
/** @var string $status */
?><span class="status status-<?= $v->e($status) ?>" data-status="<?= $v->e($status) ?>"><?= $v->t('status.' . $status) ?></span>
