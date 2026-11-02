<ul class="post-list">
    <?php foreach ($posts as $p): ?>
        <li>
            <article>
                <?php if ($p['cover_image']): ?>
                    <a href="/blog/<?= e($p['slug']) ?>" tabindex="-1" aria-hidden="true">
                        <img src="<?= e($p['cover_image']) ?>" alt="" loading="lazy" decoding="async">
                    </a>
                <?php endif; ?>
                <div>
                    <h3><a href="/blog/<?= e($p['slug']) ?>"><?= e($p['title']) ?></a></h3>
                    <p class="meta"><time datetime="<?= e(iso_date($p['published_at'])) ?>"><?= e(format_date($p['published_at'])) ?></time></p>
                    <p><?= e($p['excerpt'] ?: text_excerpt($p['content'], 200)) ?></p>
                </div>
            </article>
        </li>
    <?php endforeach; ?>
</ul>
