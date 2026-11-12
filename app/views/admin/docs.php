<div class="head">
    <h1>Documentos MD</h1>
</div>
<p class="muted">Documentos em Markdown privados (não aparecem no site), que pode escrever aqui e partilhar com assistentes de IA
    (ChatGPT, Claude, Gemini, scripts…). Cada documento tem a <strong>sua própria chave</strong>: o assistente só vê e altera esse documento.
    Todas as alterações ficam no histórico e podem ser repostas.</p>

<section class="card">
    <h2>Novo documento</h2>
    <form method="post" action="/admin/docs" class="api-token-form">
        <?= csrf_field() ?>
        <input name="title" placeholder="Título, ex.: Plano do projeto X" maxlength="200" required>
        <button class="btn primary">Criar</button>
    </form>
</section>

<section class="card">
    <?php if (!$docs): ?>
        <p class="muted">Ainda não há documentos.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Título</th><th>Assistentes</th><th>Versão</th><th>Última alteração</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($docs as $d): $id = (int) $d['id']; ?>
                <tr>
                    <td>
                        <a href="/admin/docs/<?= $id ?>"><?= e($d['title']) ?></a>
                        <div class="muted small"><?= number_format((int) $d['chars'], 0, ',', ' ') ?> caracteres · chave <code><?= e($d['key_prefix']) ?>…</code></div>
                    </td>
                    <td><span class="badge<?= $d['access'] === 'write' ? ' review' : '' ?>"><?= e(DOC_ACCESS[$d['access']] ?? $d['access']) ?></span>
                        <div class="muted small">API: <?= $d['last_api_at'] ? e(format_date($d['last_api_at'])) : 'nunca usada' ?></div></td>
                    <td class="num"><?= (int) $d['version'] ?></td>
                    <td class="muted small nowrap"><?= e(date('Y-m-d H:i', strtotime($d['updated_at']))) ?><br><?= e($d['updated_by']) ?></td>
                    <td><a class="btn small" href="/admin/docs/<?= $id ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<p class="muted small">API dos documentos: especificação OpenAPI em <a href="<?= e($openapiUrl) ?>" target="_blank"><?= e($openapiUrl) ?></a>.
    As instruções completas para o assistente (com a chave) estão em cada documento.</p>
