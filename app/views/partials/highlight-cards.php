<?php
/** @var array $cards  @var bool $large (destacados: maiores; vídeos do YouTube tocam na página) */
$large ??= false;
?>
<ul class="hl-grid<?= $large ? ' hl-large' : '' ?>">
    <?php foreach ($cards as $h):
        $yt = $h['kind'] === 'youtube' ? youtube_id($h['url']) : null;
        $network = HIGHLIGHT_KINDS[$h['kind']] ?? '';
        ?>
        <li class="hl-card hl-<?= e($h['kind']) ?>">
            <?php if ($large && $yt): ?>
                <div class="video hl-media">
                    <iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt) ?>" title="<?= e($h['title']) ?>" loading="lazy"
                            allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
            <?php elseif ($h['image']): ?>
                <a class="hl-media" href="<?= e($h['url']) ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
                    <img src="<?= e($h['image']) ?>" alt="" loading="lazy" decoding="async">
                    <?php if ($yt): ?><span class="hl-play"></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <div class="hl-body">
                <p class="hl-meta">
                    <span class="hl-network"><?= e($network) ?></span>
                    <time datetime="<?= e(iso_date($h['item_date'])) ?>"><?= e(format_date($h['item_date'])) ?></time>
                </p>
                <h3><a href="<?= e($h['url']) ?>" target="_blank" rel="noopener"><?= e($h['title'] ?: $network) ?></a></h3>
                <?php if ($h['description'] !== ''): ?>
                    <p><?= e(mb_strimwidth($h['description'], 0, $large ? 320 : 180, '…')) ?></p>
                <?php endif; ?>
                <a class="hl-cta" href="<?= e($h['url']) ?>" target="_blank" rel="noopener"><?= e(highlight_cta($h['kind'])) ?> ↗</a>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
