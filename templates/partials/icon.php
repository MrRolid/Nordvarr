<?php
/** @var \Nordvarr\View\View $v */
/** @var string $name */
// Jednoduché ikony pro spodní lištu na mobilu (statické SVG, bez skriptů).
$paths = [
    'overview' => 'M3 12h4l3-8 4 16 3-8h4',
    'inventory' => 'M4 5h16M4 12h16M4 19h16',
    'topology' => 'M6 4a2 2 0 100 4 2 2 0 000-4zM18 4a2 2 0 100 4 2 2 0 000-4zM12 16a2 2 0 100 4 2 2 0 000-4zM7 8l4 8M17 8l-4 8',
    'alerts' => 'M12 3l9 17H3zM12 10v4M12 17h.01',
    'settings' => 'M12 8a4 4 0 100 8 4 4 0 000-8zM12 2v3M12 19v3M2 12h3M19 12h3',
];
$d = $paths[$name] ?? '';
?><svg class="icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="<?= $v->e($d) ?>"/></svg>
