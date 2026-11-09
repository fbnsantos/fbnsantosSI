<?php
$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isActive = fn(string $href) => $href === '/' ? $current === '/' : str_starts_with((string) $current, $href);
$menu = [['/', t('Início')]];
foreach (menu_pages() as $item) {
    $menu[] = ['/' . $item['slug'], $item['title']];
}
if (has_public_posts()) {
    $menu[] = ['/blog', setting('blog_title', 'Blog')];
}
if (has_research()) {
    $menu[] = ['/research', research_title()];
}
if (has_highlights()) {
    $menu[] = ['/highlights', highlights_title()];
}
?><!doctype html>
<html lang="<?= e(setting('site_lang', 'pt-PT')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= seo_head($meta ?? []) ?>
    <link rel="preload" href="/assets/fonts/inter.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/fraunces.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/site.css?v=<?= filemtime(ROOT . '/assets/site.css') ?>">
</head>
<body>
<a class="skip" href="#main"><?= e(t('Saltar para o conteúdo')) ?></a>
<header class="site-header">
    <div class="wrap-wide">
        <a class="brand" href="/">
            <span class="brand-mark" aria-hidden="true"></span>
            <?= e(setting('site_name')) ?>
        </a>
        <nav aria-label="Main">
            <ul>
                <?php foreach ($menu as [$href, $label]): ?>
                    <li><a href="<?= e($href) ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>

<main id="main">
<?= $content ?>
</main>

<footer class="site-footer">
    <div class="wrap-wide">
        <div>
            <p class="footer-name"><?= e(setting('author_name', setting('site_name'))) ?></p>
            <?php if (setting('site_tagline')): ?><p class="muted"><?= e(setting('site_tagline')) ?></p><?php endif; ?>
        </div>
        <?php if ($links = social_links() + link_lines(setting('footer_links'))): ?>
            <ul class="footer-links">
                <?php foreach ($links as $label => $url): ?>
                    <li><a href="<?= e($url) ?>" rel="me noopener" target="_blank"><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="wrap-wide footer-bottom">
        <span><?= setting('footer_text') !== '' ? e(setting('footer_text')) : '© ' . date('Y') . ' ' . e(setting('author_name', setting('site_name'))) ?></span>
        <span class="footer-actions">
            <a href="/feed.xml">RSS</a>
            <a class="footer-admin" href="/admin" rel="nofollow">Backoffice</a>
        </span>
    </div>
</footer>
</body>
</html>
