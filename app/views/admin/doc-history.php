<?php $id = (int) $doc['id']; ?>
<div class="head">
    <h1>Histórico</h1>
    <div class="actions">
        <a class="btn" href="/admin/docs/<?= $id ?>">← <?= e($doc['title']) ?></a>
    </div>
</div>
<p class="muted">Cada gravação (no backoffice ou por um assistente) cria uma versão. Guardam-se as últimas <?= DOC_MAX_REVISIONS ?>.
    Repor uma versão cria uma versão nova com esse texto: nada se perde.</p>

<?php if ($rev): ?>
    <section class="card">
        <div class="head">
            <h2>Versão <?= (int) $rev['version'] ?> · <?= e($rev['source']) ?> · <?= e(date('Y-m-d H:i', strtotime($rev['created_at']))) ?></h2>
            <?php if ((int) $rev['version'] !== (int) $doc['version']): ?>
                <form method="post" action="/admin/docs/<?= $id ?>/history/<?= (int) $rev['id'] ?>/restore">
                    <?= csrf_field() ?>
                    <button class="btn primary small" data-confirm="Repor a versão <?= (int) $rev['version'] ?>? A atual fica no histórico.">Repor esta versão</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($rev['note'] !== ''): ?><p class="muted"><?= e($rev['note']) ?></p><?php endif; ?>
        <pre class="deploy-log doc-raw"><?= e($rev['content']) ?></pre>
    </section>
<?php endif; ?>

<section class="card">
    <table class="table">
        <thead><tr><th>Versão</th><th>Quando</th><th>Quem</th><th>Nota</th><th class="num">Caracteres</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($revisions as $r): ?>
            <tr<?= $rev && (int) $rev['id'] === (int) $r['id'] ? ' class="is-current"' : '' ?>>
                <td>v<?= (int) $r['version'] ?><?= (int) $r['version'] === (int) $doc['version'] ? ' <span class="badge pub">atual</span>' : '' ?></td>
                <td class="nowrap muted small"><?= e(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                <td><span class="badge<?= str_starts_with($r['source'], 'API') ? ' review' : '' ?>"><?= e($r['source']) ?></span></td>
                <td class="small"><?= e($r['note']) ?></td>
                <td class="num"><?= number_format((int) $r['chars'], 0, ',', ' ') ?></td>
                <td><a class="btn small" href="/admin/docs/<?= $id ?>/history?v=<?= (int) $r['id'] ?>">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
