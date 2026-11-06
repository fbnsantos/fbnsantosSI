<header class="page-head">
    <div class="wrap-wide">
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/"><?= e(t('Início')) ?></a></nav>
        <h1><?= e($title) ?></h1>
        <?php if (count($kinds) > 1): ?>
            <nav class="hl-filter" aria-label="<?= e(t('Filtrar')) ?>">
                <a href="/highlights"<?= $kind === '' ? ' aria-current="page"' : '' ?>><?= e(t('Tudo')) ?></a>
                <?php foreach (HIGHLIGHT_KINDS as $k => $lbl): if (empty($kinds[$k])) continue; ?>
                    <a href="/highlights?tipo=<?= $k ?>"<?= $kind === $k ? ' aria-current="page"' : '' ?>><?= e($k === 'link' ? t('Outros') : $lbl) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
</header>
<div class="wrap-wide">
    <?php if (!$items): ?>
        <p class="muted"><?= e(t('Ainda não há destaques.')) ?></p>
    <?php endif; ?>
    <?php if ($featured): ?>
        <?php $cards = $featured; $large = true; require __DIR__ . '/partials/highlight-cards.php'; ?>
    <?php endif; ?>
    <?php if ($rest): ?>
        <?php $cards = $rest; $large = false; require __DIR__ . '/partials/highlight-cards.php'; ?>
    <?php endif; ?>
    <?php if ($profiles = social_links()): ?>
        <p class="hl-follow"><?= e(t('Siga também:')) ?>
            <?php foreach ($profiles as $label => $url): ?>
                <a class="chip" href="<?= e($url) ?>" rel="me noopener" target="_blank"><?= e($label) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</div>
