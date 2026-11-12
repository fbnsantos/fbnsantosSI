<?php $id = (int) $doc['id']; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css" integrity="sha384-uqD/OYCNfagd1EgXMgl5QedTD5K+B3e9b8GYo/41t7+Serf7CBxvl+tU1gHd+qd1" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">

<?php if ($conflict): ?>
    <div class="flash error">
        <strong>O documento foi alterado enquanto editava</strong> (versão <?= (int) $conflict['version'] ?>, por <?= e($conflict['by']) ?>, <?= e(date('H:i', strtotime($conflict['at']))) ?>).
        O seu texto não foi guardado e está abaixo. Veja o que mudou no <a href="/admin/docs/<?= $id ?>/history" target="_blank">histórico</a>;
        se guardar outra vez, o seu texto passa a ser a versão atual (a outra fica no histórico).
    </div>
<?php endif; ?>
<div class="flash doc-changed" hidden data-doc-changed>
    Um assistente alterou este documento (<span data-doc-changed-by></span>).
    <a href="/admin/docs/<?= $id ?>">Recarregar</a> para ver a versão nova (perde o que não guardou) ou guarde e resolva a diferença.
</div>

<form id="editor-form" method="post" action="/admin/docs/<?= $id ?>"
      data-doc-version="<?= (int) $doc['version'] ?>" data-doc-version-url="/admin/docs/<?= $id ?>/version">
    <?= csrf_field() ?>
    <input type="hidden" name="base_version" value="<?= (int) $doc['version'] ?>">
    <div class="head">
        <h1>Documento MD</h1>
        <div class="actions">
            <a class="btn" href="/admin/docs">← Todos</a>
            <a class="btn" href="/admin/docs/<?= $id ?>/history">Histórico (v<?= (int) $doc['version'] ?>)</a>
            <a class="btn" href="/admin/docs/<?= $id ?>/download">Descarregar .md</a>
            <a class="btn" href="#assistente">Prompt para IA ↓</a>
            <button class="btn primary">Guardar</button>
        </div>
    </div>

    <input class="title-input" name="title" value="<?= e($doc['title']) ?>" placeholder="Título" required maxlength="200">
    <textarea id="md-content" name="content" rows="24"><?= e($doc['content']) ?></textarea>
    <div class="doc-save-line">
        <input name="note" placeholder="Nota sobre esta alteração (opcional, fica no histórico)" maxlength="300">
        <button class="btn primary">Guardar</button>
    </div>
    <p class="hint">Última alteração: <?= e(date('Y-m-d H:i', strtotime($doc['updated_at']))) ?> por <?= e($doc['updated_by']) ?> ·
        Ctrl/Cmd+S guarda · o botão 👁 mostra a pré-visualização.</p>

    <section class="card" id="assistente">
        <h2>Assistentes de IA</h2>
        <div class="settings-grid">
            <div>
                <label>Acesso pela API
                    <select name="access">
                        <?php foreach (DOC_ACCESS as $k => $label): ?>
                            <option value="<?= $k ?>"<?= $doc['access'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="hint">"Só ler": o assistente pode consultar o documento mas não alterá-lo.</p>
            </div>
            <div>
                <label>Notas para o assistente (opcional)
                    <textarea name="instructions" rows="4" maxlength="4000"
                              placeholder="Ex.: Este documento é o plano do projeto X. Escreve em português de Portugal. Acrescenta ideias na secção ## Ideias."><?= e($doc['instructions']) ?></textarea>
                </label>
                <p class="hint">Entram na prompt abaixo e também são devolvidas pela API (campo <code>instructions</code>).</p>
            </div>
        </div>
        <p><button class="btn">Guardar</button></p>
    </section>
</form>

<section class="card api-new-key">
    <h2>Prompt para o assistente</h2>
    <p class="muted">Cole este texto numa conversa (ChatGPT, Claude, Gemini…), nas instruções de um GPT ou num projeto do Claude.
        Tem tudo o que o assistente precisa: o endereço, a chave deste documento e as regras para ler e contribuir.
        <?= $doc['access'] === 'read' ? '<strong>O documento está em "só leitura".</strong>' : '' ?></p>
    <textarea class="api-instructions" rows="18" readonly><?= e($prompt) ?></textarea>
    <p class="actions">
        <button type="button" class="btn primary" data-copy="<?= e($prompt) ?>">Copiar prompt</button>
        <?php if ($key): ?>
            <button type="button" class="btn" data-copy="<?= e($key) ?>">Copiar só a chave</button>
            <button type="button" class="btn" data-copy="<?= e($apiUrl . '?format=md&key=' . $key) ?>">Copiar link de leitura</button>
        <?php endif; ?>
    </p>
    <?php if (!$key): ?>
        <p class="flash error">A chave deste documento não pode ser mostrada (o <code>setup_key</code> do config.php mudou ou não permite cifrar).
            Crie uma chave nova abaixo para a ver incluída na prompt.</p>
    <?php elseif ($doc['key_enc'] === ''): ?>
        <p class="hint">Esta chave só é mostrada agora: copie a prompt antes de sair da página.</p>
    <?php endif; ?>

    <div class="settings-grid">
        <div>
            <h3>Chave do documento</h3>
            <p><code class="api-key"><?= $key ? e($key) : e($doc['key_prefix']) . '…' ?></code></p>
            <p class="hint">Dá acesso só a este documento. Último uso pela API:
                <?= $doc['last_api_at'] ? e(date('Y-m-d H:i', strtotime($doc['last_api_at']))) : 'nunca' ?>.</p>
            <form method="post" action="/admin/docs/<?= $id ?>/key">
                <?= csrf_field() ?>
                <button class="btn small" data-confirm="Criar uma chave nova? A atual deixa de funcionar e os assistentes que a usam perdem o acesso.">Criar chave nova (revoga a atual)</button>
            </form>
        </div>
        <div>
            <h3>Onde colar</h3>
            <ul class="small">
                <li><strong>Claude Code / Codex / agentes com terminal:</strong> cole a prompt; o assistente usa <code>curl</code> para ler e escrever.</li>
                <li><strong>ChatGPT (GPT personalizado):</strong> instruções = prompt; <em>Ações</em> → importar de <code><?= e($openapiUrl) ?></code>; autenticação <em>Bearer</em> com a chave.</li>
                <li><strong>Chats que só abrem links</strong> (claude.ai, ChatGPT, Gemini): conseguem <em>ler</em> pelo link de leitura; para escrever precisam de ações/conectores.</li>
            </ul>
        </div>
    </div>
</section>

<details class="card">
    <summary><h2 style="display:inline">Apagar documento</h2></summary>
    <p class="muted">Apaga o documento e todo o histórico. A chave deixa de funcionar. Não pode ser desfeito.</p>
    <form method="post" action="/admin/docs/<?= $id ?>/delete">
        <?= csrf_field() ?>
        <button class="btn small" data-confirm="Apagar &quot;<?= e($doc['title']) ?>&quot; e todo o histórico? Não pode ser desfeito.">Apagar definitivamente</button>
    </form>
</details>

<script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.4/dist/purify.min.js" integrity="sha384-eEu5CTj3qGvu9PdJuS+YlkNi7d2XxQROAFYOr59zgObtlcux1ae1Il3u7jvdCSWu" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js" integrity="sha384-KtB38COewxfrhJxoN2d+olxJAeT08LF8cVZ6DQ8Poqu89zIptqO6zAXoIxpGNWYE" crossorigin="anonymous"></script>
