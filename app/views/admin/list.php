<div class="head">
    <h1><?= e($title) ?></h1>
    <a class="btn primary" href="/admin/<?= $type ?>/new">+ <?= $type === 'pages' ? 'Nova página' : 'Novo artigo' ?></a>
</div>

<?php if (!$items): ?>
    <div class="card"><p>Ainda não há nada aqui.</p></div>
<?php else: ?>
    <table class="table card">
        <thead>
        <tr>
            <th>Título</th>
            <th>Endereço</th>
            <th>Estado</th>
            <th><?= $type === 'pages' ? 'Menu' : 'Data' ?></th>
            <th class="num"><a href="/admin/stats" class="muted">Visualizações</a></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
            $url = public_url($type, $it);
            $scheduled = $type === 'posts' && $it['published'] && $it['published_at'] > now(); ?>
            <tr>
                <td><a href="/admin/<?= $type ?>/<?= $it['id'] ?>"><strong><?= e($it['title']) ?></strong></a></td>
                <td><a class="muted" href="<?= e($url) ?>" target="_blank"><?= e($url) ?></a></td>
                <td>
                    <?php if (!$it['published'] && $it['review_status'] === 'pending'): ?><a class="badge review" href="/admin/review">Para aprovar</a>
                    <?php elseif (!$it['published'] && $it['review_status'] === 'rejected'): ?><span class="badge draft">Rejeitado</span>
                    <?php elseif (!$it['published']): ?><span class="badge draft">Rascunho</span>
                    <?php elseif ($scheduled): ?><span class="badge sched">Agendado</span>
                    <?php else: ?><span class="badge pub">Publicado</span><?php endif; ?>
                    <?php if ($it['noindex']): ?><span class="badge">noindex</span><?php endif; ?>
                </td>
                <td class="muted nowrap">
                    <?php if ($type === 'pages'): ?>
                        <?= $it['slug'] === 'home' ? 'Página inicial' : ($it['in_menu'] ? 'Sim (' . (int) $it['menu_order'] . ')' : '—') ?>
                    <?php else: ?>
                        <?= e(format_date($it['published_at'])) ?>
                    <?php endif; ?>
                </td>
                <td class="num muted"><?= number_format($views[$it['id']] ?? 0, 0, ',', ' ') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
