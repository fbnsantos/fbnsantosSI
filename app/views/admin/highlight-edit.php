<form method="post" enctype="multipart/form-data" action="/admin/highlights/<?= (int) $item['id'] ?>">
    <?= csrf_field() ?>
    <div class="head">
        <h1>Editar destaque</h1>
        <div class="actions">
            <a class="btn" href="<?= e($item['url']) ?>" target="_blank" rel="noopener">Abrir original ↗</a>
            <button class="btn primary">Guardar</button>
        </div>
    </div>
    <div class="settings-grid">
        <section class="card">
            <label>Título <input name="title" value="<?= e($item['title']) ?>" maxlength="300" required></label>
            <label>Texto curto <textarea name="description" rows="5" maxlength="600"><?= e($item['description']) ?></textarea></label>
            <p class="hint">Uma ou duas frases sobre a publicação. Aparece no cartão do site.</p>
            <label>Rede
                <select name="kind">
                    <?php foreach (HIGHLIGHT_KINDS as $k => $lbl): ?>
                        <option value="<?= $k ?>"<?= $item['kind'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Data <input type="date" name="item_date" value="<?= e(substr($item['item_date'], 0, 10)) ?>"></label>
            <label class="check"><input type="checkbox" name="visible" <?= $item['visible'] ? 'checked' : '' ?>> Visível no site</label>
            <label class="check"><input type="checkbox" name="featured" <?= $item['featured'] ? 'checked' : '' ?>> ★ Destacar (aparece primeiro e maior)</label>
            <p class="hint">Link: <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener"><?= e($item['url']) ?></a></p>
        </section>
        <section class="card">
            <h2>Imagem</h2>
            <?php if ($item['image']): ?><img class="thumb" src="<?= e($item['image']) ?>" alt=""><?php endif; ?>
            <label>URL <input name="image" value="<?= e($item['image']) ?>" placeholder="/uploads/…"></label>
            <label>…ou enviar nova <input type="file" name="image_file" accept="image/*"></label>
            <p class="hint">Ideal 16:9 ou quadrada. Para publicações do LinkedIn sem imagem, uma captura de ecrã da publicação funciona bem.</p>
        </section>
    </div>
</form>
<form method="post" action="/admin/highlights/<?= (int) $item['id'] ?>/delete" class="card">
    <?= csrf_field() ?>
    <button class="btn danger" data-confirm="Remover este destaque do site?"><?= $item['source'] === 'youtube' ? 'Esconder' : 'Remover' ?> destaque</button>
</form>
