<header class="page-head">
    <div class="wrap"><h1><?= e($title) ?></h1></div>
</header>
<div class="wrap">
    <?php if ($posts): ?>
        <?php require __DIR__ . '/partials/post-list.php'; ?>
    <?php else: ?>
        <p><?= e(t('Ainda não há artigos publicados.')) ?></p>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Pagination">
            <?php if ($current > 1): ?>
                <a href="/blog<?= $current > 2 ? '?pagina=' . ($current - 1) : '' ?>" rel="prev"><?= e(t('← Mais recentes')) ?></a>
            <?php endif; ?>
            <span><?= e(t('Página %d de %d', $current, $pages)) ?></span>
            <?php if ($current < $pages): ?>
                <a href="/blog?pagina=<?= $current + 1 ?>" rel="next"><?= e(t('Mais antigos →')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
