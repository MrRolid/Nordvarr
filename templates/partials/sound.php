<?php
/** @var \Nordvarr\View\View $v */
// Zvukové upozornění: nastavení platí pro tento prohlížeč (ukládá ho skript sounds.js).
$sounds = ['chime', 'bell', 'beep2', 'gong', 'siren', 'none'];
$levels = ['info' => 'chime', 'warning' => 'beep2', 'critical' => 'siren'];
?>
<details class="sound-menu" data-sound data-pulse-url="<?= $v->url('/notify/pulse') ?>" data-test="sound-menu"
    data-text-off="<?= $v->t('sound.off') ?>" data-text-until="<?= $v->t('sound.until') ?>">
    <summary class="sound-toggle" title="<?= $v->t('sound.title') ?>">
        <svg class="icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M4 9h4l5-4v14l-5-4H4zM16 9a4 4 0 010 6M18.5 6.5a7.5 7.5 0 010 11" data-sound-waves/><path d="M16 9l6 6M22 9l-6 6" data-sound-cross hidden/></svg>
        <span class="sound-state" data-sound-state><?= $v->t('sound.on') ?></span>
    </summary>
    <div class="sound-panel">
        <div class="sound-actions">
            <button type="button" class="small" data-sound-action="on"><?= $v->t('sound.action_on') ?></button>
            <button type="button" class="small" data-sound-action="hour" data-test="sound-mute-hour"><?= $v->t('sound.action_hour') ?></button>
            <button type="button" class="small" data-sound-action="off"><?= $v->t('sound.action_off') ?></button>
        </div>
        <?php foreach ($levels as $level => $default): ?>
        <div class="sound-level sound-level-<?= $v->e($level) ?>">
            <label for="sound-<?= $v->e($level) ?>"><?= $v->t('sound.level.' . $level) ?></label>
            <select id="sound-<?= $v->e($level) ?>" data-sound-level="<?= $v->e($level) ?>" data-default="<?= $v->e($default) ?>">
                <?php foreach ($sounds as $s): ?>
                <option value="<?= $v->e($s) ?>"<?= $s === $default ? ' selected' : '' ?>><?= $v->t('sound.kind.' . $s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="small" data-sound-test="<?= $v->e($level) ?>"><?= $v->t('sound.play') ?></button>
        </div>
        <?php endforeach; ?>
        <p class="help"><?= $v->t('sound.help') ?></p>
    </div>
</details>
<div class="sound-toast" data-sound-toast role="status" aria-live="polite" hidden></div>
