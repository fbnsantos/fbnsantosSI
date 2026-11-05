<?php
$key = $newToken ?? '<A_SUA_CHAVE>';
$instructions = <<<TXT
Tens acesso à API do meu site pessoal ({$apiBase}) para criar e editar artigos do blog e páginas.

Ligação
- URL base: {$apiBase}
- Especificação OpenAPI: {$openapiUrl}
- Autenticação: cabeçalho "Authorization: Bearer {$key}"
- Pedidos e respostas em JSON (Content-Type: application/json).

Endpoints
- GET  /site                      dados do site, idioma e etiquetas já usadas (consulta antes de escrever)
- GET  /posts?q=&limit=&offset=   listar artigos (sem conteúdo)
- POST /posts                     criar artigo
- GET  /posts/{id}                ler artigo com o conteúdo HTML
- PATCH /posts/{id}               alterar artigo ainda não público (só os campos enviados mudam)
- DELETE /posts/{id}              apagar artigo ainda não público (definitivo)
- O mesmo para /pages e /pages/{id}
- GET  /media, POST /media        listar / enviar imagem: {"filename": "foto.jpg", "data": "<base64>", "alt": "..."}

Campos de um artigo: title, slug (opcional), excerpt, content, meta_description, cover_image, tags (lista),
noindex, published_at (ISO 8601; data futura = agendado depois de aprovado).
Campos de uma página: title, slug, content, meta_description, cover_image, noindex, in_menu, menu_order.

Aprovação
- Tu não publicas: tudo o que crias ou alteras fica com "status": "pending_review", invisível no site,
  até eu aprovar no backoffice. Depois de aprovado, o status passa a "published"; se eu rejeitar, a "rejected".
- Conteúdo já público não pode ser alterado nem apagado pela API (erro 409). Para propor mudanças a algo
  publicado, cria um conteúdo novo e explica-me o que mudou.

Regras
- "content" é HTML simples: <p>, <h2>, <h3>, <ul>/<ol>/<li>, <a>, <strong>, <em>, <blockquote>, <pre><code>,
  <img>, <figure>/<figcaption>, <table>. Não uses <h1> (o título já é o h1), scripts, estilos nem iframes.
  Um link do YouTube sozinho num parágrafo vira vídeo incorporado.
- Escreve no idioma do site (ver GET /site) e reutiliza as etiquetas existentes quando fizer sentido.
- Preenche sempre excerpt e meta_description (até ~155 caracteres) para o SEO.
- Nunca apagues nada sem eu pedir explicitamente.
- No fim, diz-me que ficou à espera da minha aprovação e devolve-me o "admin_url" da resposta.
TXT;
$curl = "curl -X POST {$apiBase}/posts \\\n  -H \"Authorization: Bearer {$key}\" \\\n  -H \"Content-Type: application/json\" \\\n"
    . "  -d '{\"title\": \"Olá mundo\", \"excerpt\": \"Primeiro artigo pela API\", \"content\": \"<p>Escrito por IA.</p>\", \"tags\": [\"IA\"]}'";
?>
<h1>API / IA</h1>
<p class="muted">Permite que o ChatGPT, o Claude ou qualquer script criem e editem artigos e páginas. Tudo o que chega pela API
    passa pelos mesmos filtros do editor e fica <strong>invisível até o aprovar</strong> em
    <a href="/admin/review">Para aprovar</a>. A API não consegue publicar nem alterar o que já está público.</p>

<?php if ($newToken): ?>
    <div class="card api-new-key">
        <h2><?= $shown['created'] ? 'Chave criada' : 'Chave' ?>: <?= e($shown['name']) ?></h2>
        <p>As <a href="#instrucoes">instruções para o assistente</a> abaixo já incluem esta chave.
            Ao sair ou recarregar a página deixa de ser mostrada; pode voltar a vê-la com <em>Mostrar instruções</em>.</p>
        <p><code class="api-key"><?= e($newToken) ?></code>
            <button type="button" class="btn small" data-copy="<?= e($newToken) ?>">Copiar</button></p>
    </div>
<?php endif; ?>

<div class="card">
    <h2>1. Chaves de acesso</h2>
    <p class="muted">Crie uma chave por ferramenta (ex.: "ChatGPT", "Claude"). Se uma chave for exposta, revogue-a.</p>
    <form method="post" action="/admin/api/tokens" class="api-token-form">
        <?= csrf_field() ?>
        <input name="name" placeholder="Nome, ex.: ChatGPT" maxlength="80" required>
        <button class="btn primary">Criar chave</button>
    </form>
    <?php if ($tokens): ?>
        <table class="api-tokens">
            <thead><tr><th>Nome</th><th>Chave</th><th>Criada</th><th>Último uso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= e($t['name']) ?></td>
                    <td><code><?= e($t['prefix']) ?>…</code></td>
                    <td><?= e(format_date($t['created_at'])) ?></td>
                    <td><?= $t['last_used_at'] ? e(format_date($t['last_used_at'])) : '<span class="muted">nunca</span>' ?></td>
                    <td class="review-actions">
                        <?php if ($t['token_enc'] !== ''): ?>
                            <form method="post" action="/admin/api/tokens/<?= (int) $t['id'] ?>/show">
                                <?= csrf_field() ?>
                                <button class="btn small">Mostrar instruções</button>
                            </form>
                        <?php else: ?>
                            <span class="muted small" title="Criada antes desta opção: só existe o hash. Crie uma nova chave para ver as instruções com ela.">não recuperável</span>
                        <?php endif; ?>
                        <form method="post" action="/admin/api/tokens/<?= (int) $t['id'] ?>/delete">
                            <?= csrf_field() ?>
                            <button class="btn small" data-confirm="Revogar a chave &quot;<?= e($t['name']) ?>&quot;? As ferramentas que a usam deixam de funcionar.">Revogar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card<?= $newToken ? ' api-new-key' : '' ?>" id="instrucoes">
    <h2>2. Instruções para o assistente<?= $newToken ? ' — com a chave "' . e($shown['name']) . '"' : '' ?></h2>
    <p class="muted">Cole este texto nas instruções do GPT, num projeto do Claude ou diretamente na conversa.
        <?= $newToken ? 'Já inclui a chave.' : 'Use <em>Mostrar instruções</em> ao lado de uma chave para as ver com ela incluída, ou substitua <code>&lt;A_SUA_CHAVE&gt;</code>.' ?></p>
    <textarea class="api-instructions" rows="14" readonly><?= e($instructions) ?></textarea>
    <p><button type="button" class="btn" data-copy="<?= e($instructions) ?>">Copiar instruções</button></p>
</div>

<div class="settings-grid">
    <div class="card">
        <h2>ChatGPT (GPT personalizado)</h2>
        <ol>
            <li>Em chatgpt.com → <strong>GPTs</strong> → <strong>Criar</strong> → separador <strong>Configurar</strong>.</li>
            <li>Em <strong>Instruções</strong>, cole as instruções acima (pode tirar a linha da chave).</li>
            <li><strong>Ações</strong> → <strong>Criar nova ação</strong> → <strong>Importar de URL</strong>:<br>
                <code><?= e($openapiUrl) ?></code>
                <button type="button" class="btn small" data-copy="<?= e($openapiUrl) ?>">Copiar</button></li>
            <li><strong>Autenticação</strong> → <em>Chave de API</em> → tipo <em>Bearer</em> → cole a chave.</li>
            <li>Guarde o GPT como <strong>Só eu</strong> (privado).</li>
        </ol>
    </div>
    <div class="card">
        <h2>Claude</h2>
        <ul>
            <li><strong>Claude Code</strong> (terminal ou app de desktop): cole as instruções com a chave na conversa;
                o Claude usa <code>curl</code> para chamar a API.</li>
            <li><strong>Projeto no claude.ai</strong>: as instruções ajudam o Claude a preparar os pedidos, mas o chat
                web só chama APIs externas através de um conector (MCP).</li>
            <li>Qualquer outra ferramenta ou script: use a especificação OpenAPI ou o exemplo abaixo.</li>
        </ul>
    </div>
</div>

<div class="card">
    <h2>Exemplo (curl)</h2>
    <pre class="deploy-log"><?= e($curl) ?></pre>
    <p class="muted">Endereço da API: <code><?= e($apiBase) ?></code> · Especificação: <a href="<?= e($openapiUrl) ?>" target="_blank"><?= e($openapiUrl) ?></a></p>
</div>
