<?php
/** @var array $acts  @var bool $compact (página inicial: sem texto longo) */
$compact ??= false;
?>
<ol class="ac-list<?= $compact ? ' ac-compact' : '' ?>">
    <?php foreach ($acts as $a):
        $yt = $a['video_url'] !== '' ? youtube_id($a['video_url']) : null;
        $href = $a['url'] ?: ($a['video_url'] ?: '');
        $where = implode(' · ', array_filter([$a['event'], $a['location']]));
        ?>
        <li class="ac-item ac-<?= e($a['kind']) ?><?= $a['featured'] ? ' is-featured' : '' ?>">
            <span class="ac-dot" aria-hidden="true"></span>
            <div class="ac-body">
                <p class="ac-kicker">
                    <span class="ac-kind"><?= e(activity_kind_label($a['kind'])) ?></span>
                    <?php if ($a['item_date'] !== ''): ?><time datetime="<?= e($a['item_date']) ?>"><?= e(activity_date($a['item_date'])) ?></time><?php endif; ?>
                </p>
                <h3><?php if ($href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= e($a['title']) ?></a><?php else: ?><?= e($a['title']) ?><?php endif; ?></h3>
                <?php if ($where !== ''): ?><p class="ac-where"><?= e($where) ?></p><?php endif; ?>
                <?php if (!$compact && $a['description'] !== ''): ?><p class="ac-text"><?= e($a['description']) ?></p><?php endif; ?>
            </div>
            <?php if ($yt): ?>
                <div class="ac-media video">
                    <button type="button" class="ac-play" data-yt="<?= e($yt) ?>" aria-label="<?= e(t('Ver vídeo:') . ' ' . $a['title']) ?>">
                        <img src="<?= e($a['image'] ?: "https://i.ytimg.com/vi/$yt/hqdefault.jpg") ?>" alt="" loading="lazy" decoding="async">
                        <span class="hl-play"></span>
                    </button>
                </div>
            <?php elseif ($a['image'] !== ''): ?>
                <a class="ac-media" href="<?= e($href ?: $a['image']) ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
                    <img src="<?= e($a['image']) ?>" alt="" loading="lazy" decoding="async">
                </a>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
<script>
document.querySelectorAll('.ac-play:not([data-ready])').forEach(b => {
    b.dataset.ready = '1';
    b.addEventListener('click', () => {
        const f = document.createElement('iframe');
        f.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(b.dataset.yt) + '?autoplay=1';
        f.title = b.getAttribute('aria-label') || 'YouTube';
        f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        f.referrerPolicy = 'strict-origin-when-cross-origin';
        f.allowFullscreen = true;
        b.replaceWith(f);
    });
});
</script>
