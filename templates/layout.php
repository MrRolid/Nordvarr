<?php
/** @var \Nordvarr\View\View $v */
/** @var string $content */
/** @var string $title */
$user = $v->shared('user');
$path = $v->shared('path', '/');
$flash = $v->shared('flash');
$migrations = $v->shared('migrations');
$navItems = [
    '/' => ['nav.overview', 'overview'],
    '/inventory' => ['nav.inventory', 'inventory'],
    '/topology' => ['nav.topology', 'topology'],
    '/alerts' => ['nav.alerts', 'alerts'],
    '/events' => ['nav.events', 'events'],
    '/settings' => ['nav.settings', 'settings'],
];
?><!doctype html>
<html lang="<?= $v->e($v->lang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title !== '' ? $v->t($title) . ' - ' : '' ?><?= $v->t('app.name') ?></title>
    <link rel="stylesheet" href="<?= $v->asset('css/app.css') ?>">
    <script type="module" src="<?= $v->asset('js/app.js') ?>"></script>
</head>
<body class="<?= $user !== null ? 'has-nav' : 'no-nav' ?>">
<header class="topbar">
    <a class="brand" href="<?= $v->url('/') ?>"><?= $v->t('app.name') ?></a>
    <?php if ($user !== null): ?>
    <nav class="mainnav" aria-label="<?= $v->t('nav.main') ?>">
        <?php foreach ($navItems as $href => [$label, $icon]): ?>
        <a href="<?= $v->url($href) ?>" data-nav="<?= $v->e($icon) ?>"<?= $path === $href ? ' aria-current="page"' : '' ?>>
            <?= $v->raw($v->render('partials/icon', ['name' => $icon])) ?>
            <span><?= $v->t($label) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="toolbar">
        <?= $v->raw($v->render('partials/lang', [])) ?>
        <?php if ($user !== null): ?>
        <span class="whoami"><?= $v->t('user.logged_in_as', ['email' => (string) $user['email']]) ?></span>
        <form method="post" action="<?= $v->url('/logout') ?>" class="inline">
            <?= $v->csrfField() ?>
            <button type="submit" class="link" data-test="logout"><?= $v->t('user.logout') ?></button>
        </form>
        <?php endif; ?>
    </div>
</header>
<main class="content">
    <?php if (is_string($flash)): ?>
    <p class="notice" role="status" data-test="flash"><?= $v->t($flash) ?></p>
    <?php elseif (is_array($flash) && is_string($flash[0] ?? null)): ?>
    <p class="notice" role="status" data-test="flash"><?= $v->t($flash[0], is_array($flash[1] ?? null) ? $flash[1] : []) ?></p>
    <?php endif; ?>
    <?php if (is_array($migrations)): ?>
    <p class="notice" role="status"><?= $v->t('migrations.done', $migrations) ?></p>
    <?php endif; ?>
    <?= $v->raw($content) ?>
</main>
<footer class="footer"><?= $v->t('footer.version', ['version' => (string) $v->shared('version', '')]) ?></footer>
</body>
</html>
