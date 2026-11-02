<header class="page-head">
    <div class="wrap">
        <?php if ($preview): ?><p class="notice"><?= e(t('Pré-visualização — não publicado.')) ?></p><?php endif; ?>
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/"><?= e(t('Início')) ?></a> / <a href="/blog"><?= e(setting('blog_title', 'Blog')) ?></a></nav>
        <h1><?= e($post['title']) ?></h1>
        <p class="meta">
            <time datetime="<?= e(iso_date($post['published_at'])) ?>"><?= e(format_date($post['published_at'])) ?></time>
            · <?= e(t('%d min de leitura', reading_minutes($post['content']))) ?>
            <?php if (substr($post['updated_at'], 0, 10) > substr($post['published_at'], 0, 10)): ?>
                · <?= e(t('atualizado a')) ?> <time datetime="<?= e(iso_date($post['updated_at'])) ?>"><?= e(format_date($post['updated_at'])) ?></time>
            <?php endif; ?>
        </p>
    </div>
</header>
<article class="wrap">
    <?php if ($post['cover_image']): ?>
        <img class="cover" src="<?= e($post['cover_image']) ?>" alt="<?= e($post['title']) ?>" fetchpriority="high">
    <?php endif; ?>
    <div class="prose"><?= $post['content'] ?></div>
    <?php if ($tags): ?>
        <ul class="tags"><?php foreach ($tags as $tag): ?><li><?= e($tag) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</article>
