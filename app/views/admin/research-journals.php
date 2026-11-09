<div class="head">
    <h1>Investigação</h1>
    <div class="actions">
        <?php if (has_research()): ?><a class="btn" href="/research" target="_blank">Ver no site ↗</a><?php endif; ?>
        <form method="post" action="/admin/research/sync" class="inline">
            <?= csrf_field() ?>
            <button class="btn primary">Sincronizar agora</button>
        </form>
    </div>
</div>

<nav class="stats-range" aria-label="Separadores">
    <a class="btn" href="/admin/research">Publicações (<?= $counts['pubs'] ?>)</a>
    <a class="btn" href="/admin/research?tab=projetos">Projetos (<?= $counts['projects'] ?>)</a>
    <a class="btn primary" href="/admin/research?tab=revistas">Revistas e impacto (<?= $counts['journals'] ?>)</a>
</nav>

<section class="card impact-summary">
    <div><b><?= number_format($impact['citations'], 0, ',', ' ') ?></b><span>citações</span></div>
    <div><b><?= $impact['h'] ?></b><span>índice h</span></div>
    <div><b><?= $impact['i10'] ?></b><span>i10 (≥10 citações)</span></div>
    <div><b><?= $impact['q1'] ?></b><span>publicações em Q1</span></div>
    <p class="hint">Citações do OpenAlex (base aberta; os números costumam ficar entre o Scopus e o Google Scholar).
        Atualizadas na sincronização diária. Só as publicações visíveis contam.</p>
</section>

<div class="settings-grid">
    <section class="card">
        <h2>Quartis (SJR · Scimago)</h2>
        <?php if ($sjrRows): ?>
            <p class="notice">SJR <?= e(setting('sjr_year')) ?> importado a <?= e(date('d/m/Y', strtotime(setting('sjr_imported_at')))) ?>.</p>
        <?php else: ?>
            <p class="notice error">Ainda não importado: as publicações não mostram quartil.</p>
        <?php endif; ?>
        <ol class="steps">
            <li>Abra <a href="https://www.scimagojr.com/journalrank.php" target="_blank" rel="noopener">scimagojr.com/journalrank.php</a>
                (sem filtros, ano mais recente) e clique em <b>Download data</b>.</li>
            <li>Envie aqui o ficheiro <code>scimagojr AAAA.csv</code>. Repita uma vez por ano (sai em maio/junho).</li>
        </ol>
        <form method="post" action="/admin/research/sjr" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>Ficheiro da Scimago <input type="file" name="sjr_file" accept=".csv,text/csv"></label>
            <button class="btn primary">Importar</button>
            <button class="btn" name="from_data" value="1" title="Se o ficheiro for grande demais para o upload: envie-o pelo cPanel para data/scimagojr.csv">Importar de data/</button>
        </form>
        <p class="hint">A Scimago não deixa o site descarregar o ficheiro sozinho (proteção anti-robôs), por isso este passo é manual.</p>
    </section>
    <section class="card">
        <h2>Citações (OpenAlex)</h2>
        <form method="post" action="/admin/research/impact">
            <?= csrf_field() ?>
            <label>Chave da API do OpenAlex (opcional)
                <input name="openalex_key" value="<?= e(setting('openalex_key')) ?>" autocomplete="off" placeholder="sem chave funciona, com um limite diário partilhado">
            </label>
            <p class="hint">Gratuita em <a href="https://openalex.org/settings/api" target="_blank" rel="noopener">openalex.org/settings/api</a>.
                Dá um orçamento diário próprio ao site; sem ela, o limite é partilhado com o IP do alojamento.</p>
            <button class="btn">Guardar</button>
        </form>
        <h2 style="margin-top:18px">Impact Factor (JCR)</h2>
        <p class="hint">O Impact Factor e o quartil JCR são da Clarivate e não têm fonte aberta. Se tiver acesso ao JCR (ex.: pela instituição),
            escreva-os na tabela abaixo: passam a ter prioridade. Sem eles, o site mostra o quartil SJR e o
            <b>impacto a 2 anos</b> do OpenAlex (a mesma fórmula do Impact Factor, calculada com dados abertos).</p>
    </section>
</div>

<section class="card">
    <?php if (!$journals): ?>
        <p class="muted">Ainda sem revistas: clique em <b>Sincronizar agora</b> (as revistas vêm das publicações com DOI).</p>
    <?php else: ?>
        <table class="table journals-table">
            <thead><tr><th>Revista / série</th><th>Publ.</th><th>Citações</th><th>SJR</th><th>Impacto 2 anos<br><span class="muted">OpenAlex</span></th><th>Impact Factor · quartil JCR · ano</th></tr></thead>
            <tbody>
            <?php foreach ($journals as $j): $issn = $j['issn']; ?>
                <tr id="j<?= e($issn) ?>">
                    <td><?= e($j['name'] ?: $issn) ?>
                        <div class="muted small"><?= e(substr($issn, 0, 4) . '-' . substr($issn, 4)) ?><?= $j['kind'] && $j['kind'] !== 'journal' ? ' · ' . e($j['kind']) : '' ?></div></td>
                    <td><?= (int) $j['pubs'] ?></td>
                    <td><?= (int) $j['cites'] ?></td>
                    <td class="nowrap"><?php if ($j['sjr_quartile']): ?><span class="q q<?= e(substr($j['sjr_quartile'], 1)) ?>"><?= e($j['sjr_quartile']) ?></span> <?= e(format_metric($j['sjr'] !== null ? (float) $j['sjr'] : null, 2)) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td><?= $j['mean2y'] !== null ? e(format_metric((float) $j['mean2y'])) : '<span class="muted">—</span>' ?></td>
                    <td>
                        <form method="post" action="/admin/research/journal/<?= e($issn) ?>" class="jif-form">
                            <?= csrf_field() ?>
                            <input name="jif" value="<?= $j['jif'] !== null ? e((string) $j['jif']) : '' ?>" placeholder="IF" inputmode="decimal" aria-label="Impact Factor">
                            <select name="jif_quartile" aria-label="Quartil JCR">
                                <option value="">—</option>
                                <?php foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q): ?><option<?= $j['jif_quartile'] === $q ? ' selected' : '' ?>><?= $q ?></option><?php endforeach; ?>
                            </select>
                            <input name="jif_year" value="<?= e((string) ($j['jif_year'] ?? '')) ?>" placeholder="ano" inputmode="numeric" aria-label="Ano do JCR">
                            <button class="btn small">Guardar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
