<div class="head">
    <h1>Imagens e ficheiros</h1>
</div>

<form class="card dropzone" method="post" action="/admin/media/upload" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Enviar imagens ou PDF (pode escolher vários)
        <input type="file" name="file[]" multiple accept="image/*,application/pdf" required>
    </label>
    <label>Texto alternativo (descreve a imagem — importante para SEO e acessibilidade)
        <input name="alt" placeholder="ex.: Filipe Santos a apresentar no congresso X">
    </label>
    <button class="btn primary">Enviar</button>
    <p class="hint">Máx. <?= (int) config('max_upload_mb', 10) ?> MB por ficheiro. Imagens com mais de 2000 px de largura são reduzidas automaticamente.</p>
</form>

<?php if ($items): ?>
    <div class="media-grid">
        <?php foreach ($items as $m): ?>
            <figure class="card media-item">
                <?php if (str_starts_with($m['mime'], 'image/')): ?>
                    <a href="<?= e($m['path']) ?>" target="_blank"><img src="<?= e($m['path']) ?>" alt="<?= e($m['alt']) ?>" loading="lazy"></a>
                <?php else: ?>
                    <a class="file" href="<?= e($m['path']) ?>" target="_blank">PDF</a>
                <?php endif; ?>
                <figcaption>
                    <div class="name" title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></div>
                    <div class="muted small">
                        <?= $m['width'] ? (int) $m['width'] . '×' . (int) $m['height'] . ' · ' : '' ?><?= number_format($m['size'] / 1024, 0, ',', ' ') ?> KB
                    </div>
                    <div class="row">
                        <input class="url" value="<?= e($m['path']) ?>" readonly>
                        <button type="button" class="btn small" data-copy="<?= e(abs_url($m['path'])) ?>">Copiar</button>
                    </div>
                    <?php if (str_starts_with($m['mime'], 'image/')): ?>
                        <form method="post" action="/admin/media/<?= $m['id'] ?>" class="row">
                            <?= csrf_field() ?>
                            <input name="alt" value="<?= e($m['alt']) ?>" placeholder="Texto alternativo">
                            <button class="btn small">OK</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/admin/media/<?= $m['id'] ?>/delete">
                        <?= csrf_field() ?>
                        <button class="link danger-text" data-confirm="Apagar este ficheiro? Conteúdos que o usem ficam sem imagem.">Apagar</button>
                    </form>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="muted">Ainda não enviou nenhum ficheiro.</p>
<?php endif; ?>
