<?php
$byYear = [];
foreach ($items as $a) {
    $byYear[$a['item_date'] !== '' ? substr($a['item_date'], 0, 4) : t('Sem data')][] = $a;
}
?>
<header class="page-head">
    <div class="wrap-wide">
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/"><?= e(t('Início')) ?></a></nav>
        <h1><?= e($title) ?></h1>
        <?php if (count($kinds) > 1): ?>
            <dl class="rs-stats">
                <?php foreach (ACTIVITY_KINDS as $k => $l): if (empty($kinds[$k])) continue; ?>
                    <div><dt><?= e(activity_kind_label($k, true)) ?></dt><dd><?= (int) $kinds[$k] ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <nav class="hl-filter" aria-label="<?= e(t('Filtrar')) ?>">
                <a href="/activities"<?= $kind === '' ? ' aria-current="page"' : '' ?>><?= e(t('Tudo')) ?></a>
                <?php foreach (ACTIVITY_KINDS as $k => $l): if (empty($kinds[$k])) continue; ?>
                    <a href="/activities?tipo=<?= $k ?>"<?= $kind === $k ? ' aria-current="page"' : '' ?>><?= e(activity_kind_label($k, true)) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
</header>
<div class="wrap ac-page">
    <?php if (!$items): ?><p class="muted"><?= e(t('Nenhum resultado.')) ?></p><?php endif; ?>
    <?php foreach ($byYear as $year => $acts): ?>
        <section class="ac-year">
            <h2><?= e((string) $year) ?></h2>
            <?php require __DIR__ . '/partials/activity-items.php'; ?>
        </section>
    <?php endforeach; ?>
</div>
