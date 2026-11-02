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
