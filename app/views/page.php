<header class="page-head">
    <div class="wrap">
        <?php if ($preview): ?><p class="notice"><?= e(t('Pré-visualização — não publicado.')) ?></p><?php endif; ?>
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/"><?= e(t('Início')) ?></a> / <span><?= e($page['title']) ?></span></nav>
        <h1><?= e($page['title']) ?></h1>
    </div>
</header>
<article class="wrap">
    <?php if ($page['cover_image']): ?>
        <img class="cover" src="<?= e($page['cover_image']) ?>" alt="<?= e($page['title']) ?>" fetchpriority="high">
    <?php endif; ?>
    <div class="prose"><?= $page['content'] ?></div>
</article>
