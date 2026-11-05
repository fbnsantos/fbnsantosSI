<h1>Para aprovar</h1>
<p class="muted">Conteúdo criado ou alterado pelo ChatGPT, Claude ou outras ferramentas através da API.
    Fica invisível no site até o aprovar aqui (ou publicar no editor).</p>

<?php if (!$items): ?>
    <div class="card"><p>Nada à espera de aprovação.</p></div>
<?php else: ?>
    <table class="table card review-table">
        <thead>
        <tr><th>Título</th><th>Tipo</th><th>Origem</th><th>Recebido</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
            $base = "/admin/review/{$it['type']}/{$it['id']}"; ?>
            <tr>
                <td>
                    <a href="/admin/<?= $it['type'] ?>/<?= (int) $it['id'] ?>"><strong><?= e($it['title']) ?></strong></a>
                    <?php if ($it['type'] === 'posts' && $it['excerpt'] !== ''): ?>
                        <div class="muted small"><?= e(mb_strimwidth($it['excerpt'], 0, 140, '…')) ?></div>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= $it['type'] === 'posts' ? 'Artigo' : 'Página' ?></td>
                <td class="muted"><?= e($it['source'] ?: '—') ?></td>
                <td class="muted nowrap"><?= e(format_date($it['updated_at'])) ?></td>
                <td class="review-actions">
                    <a class="btn small" href="<?= e(public_url($it['type'], $it)) ?>" target="_blank">Pré-visualizar ↗</a>
                    <a class="btn small" href="/admin/<?= $it['type'] ?>/<?= (int) $it['id'] ?>">Editar</a>
                    <form method="post" action="<?= $base ?>/approve">
                        <?= csrf_field() ?>
                        <button class="btn small primary" data-confirm="Publicar &quot;<?= e($it['title']) ?>&quot; no site?">Aprovar e publicar</button>
                    </form>
                    <form method="post" action="<?= $base ?>/reject">
                        <?= csrf_field() ?>
                        <button class="btn small" data-confirm="Rejeitar? Fica como rascunho, invisível no site.">Rejeitar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
