<?php
/** @var \Nordvarr\View\View $v */
/** @var list<array<string, mixed>> $deliveries */
/** @var array<string, list<array<string, mixed>>> $attempts */
/** @var bool $showChannel */
?>
<?php if ($deliveries === []): ?>
<p class="muted"><?= $v->t('delivery.none') ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="list" data-test="deliveries">
    <thead><tr>
        <th><?= $v->t('delivery.created') ?></th>
        <?php if ($showChannel): ?><th><?= $v->t('delivery.channel') ?></th><?php endif; ?>
        <th><?= $v->t('delivery.kind') ?></th>
        <th><?= $v->t('delivery.status') ?></th>
        <th><?= $v->t('delivery.attempts') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d): ?>
    <tr>
        <td class="nowrap"><?= $v->time((string) $d['created_at']) ?></td>
        <?php if ($showChannel): ?><td><?= $v->e($d['channel_name']) ?> <span class="muted">(<?= $v->t('channeltype.' . $d['channel_type']) ?>)</span></td><?php endif; ?>
        <td><?= $v->t('delivery.kind.' . $d['kind']) ?><br><span class="muted small-text"><?= $v->e($d['subject']) ?></span></td>
        <td><span class="badge delivery-<?= $v->e($d['status']) ?>" data-test="delivery-status"><?= $v->t('delivery.status.' . $d['status']) ?></span>
            <?php if ($d['status'] === 'pending' && $d['next_attempt_at'] !== null && (int) $d['attempts'] > 0): ?>
            <br><span class="muted small-text"><?= $v->t('delivery.next') ?> <?= $v->time((string) $d['next_attempt_at']) ?></span>
            <?php endif; ?></td>
        <td>
            <?php foreach ($attempts[$d['id']] ?? [] as $a): ?>
            <div class="small-text"><span class="nowrap"><?= $v->time((string) $a['attempted_at']) ?></span>
                <?= (int) $a['ok'] === 1 ? $v->t('delivery.ok') : $v->t('delivery.error') ?><?= (string) $a['error'] !== '' ? ': ' . $v->e($a['error']) : '' ?></div>
            <?php endforeach; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
