<?php
$connected = linkedin_connected();
$expires = (int) setting('linkedin_expires');
?>
<div class="head"><h1>Redes sociais</h1></div>
<p class="muted">Do site para as redes: publicar os artigos no LinkedIn. Das redes para o site: vídeos do YouTube,
    publicações do LinkedIn e do Instagram em <a href="/admin/highlights">Destaques</a>.</p>

<div class="settings-grid">
    <section class="card" id="linkedin">
        <h2>LinkedIn: publicar artigos</h2>
        <?php if ($connected): ?>
            <p class="ok-text">Ligado como <strong><?= e(setting('linkedin_name')) ?></strong>.
                A autorização é válida até <?= e(date('d/m/Y', $expires)) ?>.</p>
            <?php if ($expires < strtotime('+10 days')): ?>
                <p class="muted">Expira em breve: clique em "Ligar outra vez" para renovar por 60 dias.</p>
            <?php endif; ?>
            <p>Em cada artigo publicado aparece o cartão <b>Partilhar no LinkedIn</b>, no fundo do editor.</p>
        <?php elseif ($expires && $expires < time()): ?>
            <p class="muted">A autorização expirou (o LinkedIn só dá 60 dias). Clique em "Ligar ao LinkedIn" para renovar.</p>
        <?php endif; ?>

        <?php if (linkedin_configured()): ?>
            <form method="post" action="/admin/linkedin/connect" class="inline">
                <?= csrf_field() ?>
                <button class="btn<?= $connected ? '' : ' primary' ?>"><?= $connected ? 'Ligar outra vez' : 'Ligar ao LinkedIn' ?></button>
            </form>
            <?php if ($connected): ?>
                <form method="post" action="/admin/linkedin/disconnect" class="inline">
                    <?= csrf_field() ?>
                    <button class="btn" data-confirm="Desligar o LinkedIn deste site?">Desligar</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <details<?= linkedin_configured() ? '' : ' open' ?>>
            <summary>Configurar a app do LinkedIn (uma vez)</summary>
            <ol class="steps">
                <li>Abra <a href="https://www.linkedin.com/developers/apps/new" target="_blank" rel="noopener">linkedin.com/developers/apps</a> → <i>Create app</i>.
                    O LinkedIn pede uma página de empresa associada (pode usar uma sua ou criar uma página simples).</li>
                <li>Em <i>Products</i>, peça <b>Share on LinkedIn</b> e <b>Sign In with LinkedIn using OpenID Connect</b> (aprovação imediata).</li>
                <li>Em <i>Auth → Authorized redirect URLs</i>, adicione:
                    <code class="copy-line"><?= e(linkedin_redirect_uri()) ?></code>
                    <button type="button" class="btn small" data-copy="<?= e(linkedin_redirect_uri()) ?>">Copiar</button></li>
                <li>Copie o <i>Client ID</i> e o <i>Primary Client Secret</i> para aqui, guarde e clique em <b>Ligar ao LinkedIn</b>.</li>
            </ol>
            <form method="post" action="/admin/social">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="linkedin">
                <label>Client ID <input name="linkedin_client_id" value="<?= e(setting('linkedin_client_id')) ?>" autocomplete="off"></label>
                <label>Client Secret
                    <input type="password" name="linkedin_client_secret" autocomplete="new-password"
                           placeholder="<?= setting('linkedin_client_secret') !== '' ? '•••••••• (guardado; deixe vazio para manter)' : '' ?>">
                </label>
                <p class="hint">O segredo fica cifrado na base de dados.</p>
                <button class="btn">Guardar</button>
            </form>
        </details>
        <p class="hint">Sem configurar nada também pode partilhar: o botão "Abrir no LinkedIn" de cada artigo abre o LinkedIn com o texto e o link já escritos.</p>

        <?php if ($shares): ?>
            <h3>Últimas publicações</h3>
            <ul class="links">
                <?php foreach ($shares as $s): ?>
                    <li><a href="<?= e(linkedin_post_url($s['external_id'])) ?>" target="_blank" rel="noopener"><?= e($s['title'] ?? '(apagado)') ?></a>
                        <span class="muted">· <?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card" id="youtube">
        <h2>YouTube: vídeos novos nos Destaques</h2>
        <form method="post" action="/admin/social">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="youtube">
            <label>Canal
                <input name="youtube_channel" value="<?= e($channel ?: setting('social_youtube')) ?>" placeholder="https://www.youtube.com/@nome">
            </label>
            <p class="hint">URL do canal (youtube.com/@nome ou youtube.com/channel/UC…). Os vídeos novos são importados de 6 em 6 horas,
                pelo feed público do YouTube (sem chave de API).</p>
            <label class="check"><input type="checkbox" name="youtube_auto_visible" <?= setting('youtube_auto_visible', '1') === '1' ? 'checked' : '' ?>>
                Mostrar logo no site os vídeos novos (senão ficam escondidos até os aprovar em Destaques)</label>
            <button class="btn">Guardar e importar</button>
        </form>
        <?php if ($channel): ?>
            <p class="muted">Canal <code><?= e($channel) ?></code> · <?= $videos ?> vídeo(s) importado(s)
                <?php if ($at = (int) setting('youtube_sync_at')): ?>· última verificação <?= e(date('d/m/Y H:i', $at)) ?><?php endif; ?></p>
            <form method="post" action="/admin/social/youtube-sync" class="inline">
                <?= csrf_field() ?><button class="btn small">Importar agora</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>LinkedIn e Instagram → site</h2>
        <p>O LinkedIn e o Instagram não deixam outros sites ler as suas publicações automaticamente
            (as APIs que o fazem estão reservadas a parceiros ou contas empresariais).
            Por isso basta colar o link da publicação em <a href="/admin/highlights">Destaques</a>: o site lê o título,
            o texto e a imagem quando a rede o permite, e guarda uma cópia da imagem.</p>
    </section>

    <section class="card">
        <h2>Página de destaques</h2>
        <form method="post" action="/admin/social">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="highlights">
            <label>Título da página <input name="highlights_title" value="<?= e(setting('highlights_title')) ?>" placeholder="<?= e(t('Destaques')) ?>"></label>
            <label class="check"><input type="checkbox" name="highlights_home" <?= setting('highlights_home', '1') === '1' ? 'checked' : '' ?>>
                Mostrar os 3 destaques mais recentes na página inicial</label>
            <p class="hint">A página fica em <a href="/highlights" target="_blank">/highlights</a> e aparece no menu quando houver destaques visíveis.</p>
            <button class="btn">Guardar</button>
        </form>
    </section>
</div>
