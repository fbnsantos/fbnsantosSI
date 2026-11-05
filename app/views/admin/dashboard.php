<div class="head">
    <h1>Painel</h1>
    <div class="actions">
        <a class="btn primary" href="/admin/posts/new">+ Novo artigo</a>
        <a class="btn" href="/admin/pages/new">+ Nova página</a>
    </div>
</div>

<?php if ($pending = pending_review_count()): ?>
    <div class="card review-banner pending">
        <div><strong><?= $pending ?> <?= $pending === 1 ? 'conteúdo' : 'conteúdos' ?> à espera da sua aprovação</strong>
            <span class="muted">· enviados pelo ChatGPT, Claude ou outra ferramenta</span></div>
        <a class="btn small primary" href="/admin/review">Rever</a>
    </div>
<?php endif; ?>

<div class="stats">
    <a class="stat" href="/admin/posts"><b><?= $counts['posts'] ?></b><span>artigos<?= $counts['drafts'] ? " ({$counts['drafts']} rascunhos)" : '' ?></span></a>
    <a class="stat" href="/admin/pages"><b><?= $counts['pages'] ?></b><span>páginas</span></a>
    <a class="stat" href="/admin/media"><b><?= $counts['media'] ?></b><span>imagens e ficheiros</span></a>
</div>

<section class="card">
    <h2>SEO e Google</h2>
    <p class="muted">Gerado automaticamente a partir do conteúdo:</p>
    <ul class="links">
        <li><a href="/robots.txt" target="_blank">robots.txt</a></li>
        <li><a href="/sitemap.xml" target="_blank">sitemap.xml</a> (páginas, artigos, imagens e vídeos)</li>
        <li><a href="/feed.xml" target="_blank">feed.xml</a> (RSS)</li>
        <li><a href="/llms.txt" target="_blank">llms.txt</a> (resumo para ChatGPT, Perplexity e outros motores com IA)</li>
        <li>Imagens de partilha 1200×630 geradas para cada página sem capa · IndexNow avisa o Bing ao publicar</li>
        <li><a href="https://search.google.com/test/rich-results?url=<?= e(rawurlencode(abs_url('/'))) ?>" target="_blank" rel="noopener">Testar dados estruturados no Google ↗</a></li>
        <li><a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console ↗</a></li>
    </ul>
    <?php if ($checks): ?>
        <ul class="checks">
            <?php foreach ($checks as [$level, $msg]): ?><li class="<?= $level ?>"><?= e($msg) ?></li><?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="ok-text">Tudo em ordem.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Editado recentemente</h2>
    <table class="table">
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><a href="/admin/<?= $r['type'] ?>/<?= $r['id'] ?>"><?= e($r['title']) ?></a></td>
                <td class="muted"><?= $r['type'] === 'posts' ? 'Artigo' : 'Página' ?></td>
                <td class="muted nowrap"><?= e(format_date($r['updated_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
