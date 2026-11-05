<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$nav = [
    '/admin' => 'Painel',
    '/admin/pages' => 'Páginas',
    '/admin/posts' => 'Artigos',
    '/admin/media' => 'Imagens',
    '/admin/settings' => 'Definições',
    '/admin/api' => 'API / IA',
    '/admin/update' => 'Atualizar',
];
$flash = flash();
?><!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'Backoffice') ?> · Backoffice</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(ROOT . '/assets/admin.css') ?>">
</head>
<body class="<?= !empty($bare) ? 'bare' : '' ?>">
<?php if (empty($bare)): ?>
    <aside class="side">
        <a class="logo" href="/admin"><?= e(setting('site_name')) ?></a>
        <nav>
            <?php foreach ($nav as $href => $label):
                $active = $href === '/admin' ? $path === '/admin' : str_starts_with((string) $path, $href); ?>
                <a href="<?= $href ?>"<?= $active ? ' class="active"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <a href="/" target="_blank">Ver site ↗</a>
            <a href="/admin/password">Palavra-passe</a>
            <form method="post" action="/admin/logout"><?= csrf_field() ?><button class="link">Sair</button></form>
        </div>
    </aside>
<?php endif; ?>
<main class="main">
    <?php if ($flash): ?><div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?= $content ?>
</main>
<script src="/assets/admin.js?v=<?= filemtime(ROOT . '/assets/admin.js') ?>"></script>
</body>
</html>
