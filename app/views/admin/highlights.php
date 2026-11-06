<div class="head">
    <h1>Destaques</h1>
    <div class="actions">
        <a class="btn" href="/highlights" target="_blank">Ver no site ↗</a>
        <a class="btn" href="/admin/social">Redes sociais</a>
    </div>
</div>
<p class="muted">Vídeos, publicações do LinkedIn e do Instagram e outros links que aparecem em <a href="/highlights" target="_blank">/highlights</a>
    e na página inicial. Os marcados com ★ aparecem primeiro e em tamanho grande.</p>

<section class="card">
    <h2>Adicionar</h2>
    <form method="post" action="/admin/highlights" enctype="multipart/form-data" class="highlight-add">
        <?= csrf_field() ?>
        <label>Link
            <input type="url" name="url" required placeholder="https://www.linkedin.com/posts/…  ·  https://youtu.be/…  ·  https://www.instagram.com/p/…">
        </label>
        <details>
            <summary>Imagem própria (opcional)</summary>
            <label>Enviar imagem <input type="file" name="image_file" accept="image/*"></label>
            <p class="hint">O LinkedIn e o Instagram nem sempre deixam ler a imagem: nesse caso envie uma captura de ecrã ou a foto original.</p>
        </details>
        <label class="check"><input type="checkbox" name="featured"> ★ Destacar</label>
        <button class="btn primary">Adicionar</button>
    </form>
    <p class="hint">
        No LinkedIn: menu <b>…</b> da publicação → <i>Copiar link da publicação</i>. No Instagram: <b>…</b> → <i>Copiar link</i>.
        <?php if ($channel): ?>
            Os vídeos novos do seu canal do YouTube entram sozinhos (verificado de 6 em 6 horas).
        <?php else: ?>
            <a href="/admin/social#youtube">Ligue o seu canal do YouTube</a> para os vídeos novos entrarem sozinhos.
        <?php endif; ?>
    </p>
</section>

<nav class="stats-range" aria-label="Filtrar">
    <a class="btn small<?= $kind === '' ? ' primary' : '' ?>" href="/admin/highlights">Todos</a>
    <?php foreach (HIGHLIGHT_KINDS as $k => $lbl): ?>
        <a class="btn small<?= $kind === $k ? ' primary' : '' ?>" href="/admin/highlights?tipo=<?= $k ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
    <?php if ($channel): ?>
        <form method="post" action="/admin/social/youtube-sync" class="inline">
            <?= csrf_field() ?><input type="hidden" name="back" value="highlights">
            <button class="btn small">Importar vídeos novos agora</button>
        </form>
    <?php endif; ?>
</nav>

<section class="card">
    <?php if (!$items): ?>
        <p class="muted">Ainda não há destaques.</p>
    <?php else: ?>
        <table class="table highlights-table">
            <thead><tr><th></th><th>Título</th><th>Rede</th><th>Data</th><th>No site</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $h): ?>
                <tr class="<?= $h['visible'] ? '' : 'is-hidden' ?>">
                    <td><?php if ($h['image']): ?><img class="hl-thumb" src="<?= e($h['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
                    <td>
                        <a href="/admin/highlights/<?= (int) $h['id'] ?>"><?= e($h['title'] ?: '(sem título)') ?></a>
                        <div class="muted small"><a class="muted" href="<?= e($h['url']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($h['url'], 0, 60, '…')) ?></a></div>
                    </td>
                    <td><span class="badge"><?= e(HIGHLIGHT_KINDS[$h['kind']] ?? $h['kind']) ?></span></td>
                    <td class="muted nowrap"><?= e(date('d/m/Y', strtotime($h['item_date']))) ?></td>
                    <td class="nowrap">
                        <form method="post" action="/admin/highlights/<?= (int) $h['id'] ?>/visible" class="inline">
                            <?= csrf_field() ?>
                            <button class="btn small" title="Mostrar ou esconder no site"><?= $h['visible'] ? 'Visível' : 'Escondido' ?></button>
                        </form>
                        <form method="post" action="/admin/highlights/<?= (int) $h['id'] ?>/featured" class="inline">
                            <?= csrf_field() ?>
                            <button class="btn small<?= $h['featured'] ? ' primary' : '' ?>" title="Destacar (aparece primeiro e maior)" aria-pressed="<?= $h['featured'] ? 'true' : 'false' ?>">★</button>
                        </form>
                    </td>
                    <td><a class="btn small" href="/admin/highlights/<?= (int) $h['id'] ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
