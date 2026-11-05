<?php
$isPost = $type === 'posts';
$isHome = !$isPost && $item['slug'] === 'home';
$prefix = $isPost ? '/blog/' : '/';
?>
<form id="editor-form" method="post" enctype="multipart/form-data"
      action="/admin/<?= $type ?>/<?= $item['id'] ?: 'new' ?>">
    <?= csrf_field() ?>
    <div class="head">
        <h1><?= e($title) ?></h1>
        <div class="actions">
            <?php if ($item['id']): ?>
                <a class="btn" href="<?= e(public_url($type, $item)) ?>" target="_blank">Ver no site ↗</a>
            <?php endif; ?>
            <button class="btn primary">Guardar</button>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="flash error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="edit-grid">
        <div class="edit-main">
            <input class="title-input" name="title" value="<?= e($item['title']) ?>" placeholder="Título" required
                   data-slug-source <?= $item['id'] ? '' : 'autofocus' ?>>
            <textarea id="content" name="content"><?= e($item['content']) ?></textarea>
            <p class="hint">
                <strong>Dicas:</strong> arraste imagens para o editor para as enviar ·
                para um vídeo do YouTube cole o link sozinho numa linha (ou use o botão de vídeo) ·
                use "Título 2/3" para secções (ajuda o Google a perceber a estrutura).
            </p>
        </div>

        <aside class="edit-side">
            <section class="card">
                <h3>Publicação</h3>
                <label class="check"><input type="checkbox" name="published" <?= $item['published'] ? 'checked' : '' ?>> Publicado</label>
                <?php if ($isPost): ?>
                    <label>Data de publicação
                        <input type="datetime-local" name="published_at" value="<?= e(date('Y-m-d\TH:i', strtotime($item['published_at']))) ?>">
                    </label>
                    <p class="hint">Uma data futura agenda o artigo.</p>
                <?php else: ?>
                    <?php if (!$isHome): ?>
                        <label class="check"><input type="checkbox" name="in_menu" <?= $item['in_menu'] ? 'checked' : '' ?>> Mostrar no menu</label>
                        <label>Ordem no menu <input type="number" name="menu_order" value="<?= (int) $item['menu_order'] ?>"></label>
                    <?php else: ?>
                        <input type="hidden" name="in_menu" value="1">
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>SEO</h3>
                <label>Endereço
                    <span class="slug-field"><span class="muted"><?= e($prefix) ?></span>
                        <?php if ($isHome): ?>
                            <input name="slug" value="home" readonly title="A página inicial está sempre em /">
                        <?php else: ?>
                            <input name="slug" value="<?= e($item['slug']) ?>" pattern="[a-z0-9\-]*" data-slug placeholder="gerado-do-titulo">
                        <?php endif; ?>
                    </span>
                </label>
                <label>Descrição para o Google <span class="counter" data-for="meta_description"></span>
                    <textarea name="meta_description" rows="3" maxlength="300" data-count="160"
                              placeholder="1–2 frases que resumem a página (≈150 caracteres)."><?= e($item['meta_description']) ?></textarea>
                </label>
                <div class="serp" aria-hidden="true">
                    <div class="serp-url"><?= e(preg_replace('#^https?://#', '', base_url())) ?><span data-serp-path><?= e($isHome ? '' : $prefix . $item['slug']) ?></span></div>
                    <div class="serp-title" data-serp-title><?= e($item['title'] ?: 'Título') ?> — <?= e(setting('site_name')) ?></div>
                    <div class="serp-desc" data-serp-desc><?= e($item['meta_description'] ?: 'A descrição aparece aqui…') ?></div>
                </div>
                <label class="check"><input type="checkbox" name="noindex" <?= $item['noindex'] ? 'checked' : '' ?>> Esconder do Google (noindex)</label>
            </section>

            <?php if ($isPost): ?>
                <section class="card">
                    <h3>Resumo e etiquetas</h3>
                    <label>Resumo (lista de artigos e RSS)
                        <textarea name="excerpt" rows="3"><?= e($item['excerpt']) ?></textarea>
                    </label>
                    <label>Etiquetas (separadas por vírgulas)
                        <input name="tags" value="<?= e($item['tags']) ?>" placeholder="investigação, robótica">
                    </label>
                </section>
            <?php endif; ?>

            <section class="card">
                <h3>Imagem de capa</h3>
                <p class="hint">Usada no topo, nas listas e ao partilhar nas redes sociais (ideal 1200×630).</p>
                <?php if ($item['cover_image']): ?><img class="thumb" src="<?= e($item['cover_image']) ?>" alt=""><?php endif; ?>
                <label>URL da imagem <input name="cover_image" value="<?= e($item['cover_image']) ?>" placeholder="/uploads/…"></label>
                <label>…ou enviar nova <input type="file" name="cover_file" accept="image/*"></label>
            </section>

            <?php if ($item['id'] && !$isHome): ?>
                <button class="btn danger" form="delete-form"
                        data-confirm="Apagar &quot;<?= e($item['title']) ?>&quot;? Esta ação não pode ser desfeita.">Apagar <?= e($label) ?></button>
            <?php endif; ?>
        </aside>
    </div>
</form>

<?php if ($item['id'] && !$isHome): ?>
    <form id="delete-form" method="post" action="/admin/<?= $type ?>/<?= $item['id'] ?>/delete"><?= csrf_field() ?></form>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7.9.2/tinymce.min.js" integrity="sha384-C7mWIB9Vj1ePj/U7oKCJWgLbv1ttjXrW3JnNpeNjOJfwUWGBsnLtJ5X3alR5V1WU" crossorigin="anonymous" referrerpolicy="origin"></script>
<script>
    window.EDITOR_CSS = '/assets/site.css?v=<?= filemtime(ROOT . '/assets/site.css') ?>';
</script>
