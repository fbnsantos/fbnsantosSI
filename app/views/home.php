<section class="hero">
    <div class="wrap-wide hero-grid">
        <?php if (setting('author_image')): ?>
            <div class="hero-photo">
                <img src="<?= e(setting('author_image')) ?>" alt="<?= e(setting('author_name')) ?>" width="200" height="200" fetchpriority="high">
            </div>
        <?php endif; ?>
        <div class="hero-text">
            <h1><?= e(setting('author_name', setting('site_name'))) ?></h1>
            <?php if ($roles = setting_lines('author_headline')): ?>
                <ul class="roles">
                    <?php foreach ($roles as $role): ?><li><?= e($role) ?></li><?php endforeach; ?>
                </ul>
            <?php elseif (setting('author_job')): ?>
                <p class="roles"><?= e(setting('author_job')) ?></p>
            <?php endif; ?>
            <?php if (setting('author_tagline')): ?>
                <p class="tagline"><?= e(setting('author_tagline')) ?></p>
            <?php endif; ?>
            <div class="chips">
                <?php if (page_exists('contacts')): ?>
                    <a class="chip primary" href="/contacts"><?= e(t('Contactar')) ?></a>
                <?php endif; ?>
                <?php foreach (social_links() as $label => $url): ?>
                    <a class="chip" href="<?= e($url) ?>" rel="me noopener" target="_blank"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<div class="wrap">
    <?php if (trim($page['content']) !== ''): ?>
        <div class="prose"><?= $page['content'] ?></div>
    <?php endif; ?>

    <?php if ($posts): ?>
        <section class="latest">
            <h2><?= e(t('Últimos artigos')) ?></h2>
            <?php require __DIR__ . '/partials/post-list.php'; ?>
            <p><a class="more" href="/blog"><?= e(t('Ver todos os artigos →')) ?></a></p>
        </section>
    <?php endif; ?>
</div>

<?php if (!empty($activities)): ?>
    <section class="latest wrap ac-home">
        <h2><?= e(activities_title()) ?></h2>
        <?php $acts = $activities; $compact = true; require __DIR__ . '/partials/activity-items.php'; ?>
        <p><a class="more" href="/activities"><?= e(t('Ver tudo →')) ?></a></p>
    </section>
<?php endif; ?>

<?php if ($highlights): ?>
    <section class="latest wrap-wide hl-home">
        <h2><?= e(highlights_title()) ?></h2>
        <?php $cards = $highlights; $large = false; require __DIR__ . '/partials/highlight-cards.php'; ?>
        <p><a class="more" href="/highlights"><?= e(t('Ver todos os destaques →')) ?></a></p>
    </section>
<?php endif; ?>
