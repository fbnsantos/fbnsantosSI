<?php
$orcid = research_orcid_id();
$auth = research_authenticus_id();
$kindKey = $tab === 'pubs' ? 'pub' : 'project';
$self = '/admin/research?' . http_build_query(array_filter(['tab' => $tab === 'projects' ? 'projetos' : '', 'q' => $q, 'tipo' => $type, 'estado' => $state]));
$link = fn(array $p) => '/admin/research?' . http_build_query(array_filter(array_merge(
    ['tab' => $tab === 'projects' ? 'projetos' : '', 'q' => $q, 'tipo' => $type, 'estado' => $state], $p)));
?>
<div class="head">
    <h1>Investigação</h1>
    <div class="actions">
        <?php if (has_research()): ?><a class="btn" href="/research" target="_blank">Ver no site ↗</a><?php endif; ?>
        <a class="btn" href="/admin/research/<?= $kindKey ?>/new">+ <?= $tab === 'pubs' ? 'Publicação' : 'Projeto' ?></a>
        <?php if (research_configured()): ?>
            <form method="post" action="/admin/research/sync" class="inline">
                <?= csrf_field() ?>
                <button class="btn primary">Sincronizar agora</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<p class="muted">Publicações e projetos importados do ORCID e do Authenticus, mostrados em <a href="/research" target="_blank">/research</a>.
    A sincronização corre sozinha uma vez por dia: só acrescenta o que é novo e nunca desfaz o que editar, esconder ou destacar aqui.</p>

<?php if ($log = setting('research_sync_log')): ?>
    <p class="notice<?= setting('research_sync_ok') === '0' ? ' error' : '' ?>">Última sincronização: <?= e($log) ?></p>
<?php endif; ?>

<details class="card"<?= research_configured() ? '' : ' open' ?>>
    <summary><h2 style="display:inline">Fontes e opções</h2>
        <span class="muted small"><?= $orcid ? 'ORCID ' . e($orcid) : 'sem ORCID' ?> · <?= $auth ? 'Authenticus ' . e($auth) : 'sem Authenticus' ?></span></summary>
    <form method="post" action="/admin/research/settings">
        <?= csrf_field() ?>
        <div class="settings-grid">
            <div>
                <label>ORCID iD
                    <input name="research_orcid" value="<?= e(setting('research_orcid') ?: $orcid) ?>" placeholder="0000-0002-8486-6113">
                </label>
                <p class="hint">Publicações (com autores) e projetos (secção <i>Funding</i>) do perfil público em orcid.org.
                    Só entra o que tiver visibilidade <b>pública</b> no ORCID.</p>
                <label>Authenticus
                    <input name="research_authenticus" value="<?= e($auth) ?>" placeholder="https://www.authenticus.pt/en/profileOfResearchers/publicationsList/90650">
                </label>
                <p class="hint">Cole o URL da sua página de publicações no Authenticus, o número, ou o identificador (R-XXX-XXX).
                    Traz as publicações validadas, com a indexação Scopus / Web of Science.</p>
            </div>
            <div>
                <label>Título da página
                    <input name="research_title" value="<?= e(setting('research_title')) ?>" placeholder="<?= e(t('Investigação')) ?>">
                </label>
                <label>Variantes do seu nome (uma por linha)
                    <textarea name="research_names" rows="5" placeholder="Santos, Filipe&#10;F. N. Santos"><?= e(setting('research_names')) ?></textarea>
                </label>
                <p class="hint">Aparecem a negrito na lista de autores. Preenchido sozinho a partir do ORCID e do Authenticus; se editar a lista, a sincronização deixa de mexer nela.</p>
                <label class="check"><input type="checkbox" name="research_auto_visible" <?= setting('research_auto_visible', '1') === '1' ? 'checked' : '' ?>>
                    Itens novos ficam logo visíveis no site</label>
                <p class="hint">Desligue se preferir rever cada publicação antes de aparecer.</p>
            </div>
        </div>
        <button class="btn primary">Guardar</button>
    </form>
</details>

<nav class="stats-range" aria-label="Separadores">
    <a class="btn<?= $tab === 'pubs' ? ' primary' : '' ?>" href="/admin/research">Publicações (<?= $counts['pubs'] ?>)</a>
    <a class="btn<?= $tab === 'projects' ? ' primary' : '' ?>" href="/admin/research?tab=projetos">Projetos (<?= $counts['projects'] ?>)</a>
</nav>

<form method="get" action="/admin/research" class="research-filter">
    <?php if ($tab === 'projects'): ?><input type="hidden" name="tab" value="projetos"><?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar título, autores, revista, DOI…">
    <?php if ($tab === 'pubs'): ?>
        <select name="tipo">
            <option value="">Todos os tipos</option>
            <?php foreach (PUB_TYPES as $k => $lbl): if (empty($counts['types'][$k])) continue; ?>
                <option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($lbl) ?> (<?= (int) $counts['types'][$k] ?>)</option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <select name="estado">
        <option value="">Todos</option>
        <option value="escondidos"<?= $state === 'escondidos' ? ' selected' : '' ?>>Escondidos</option>
        <option value="destacados"<?= $state === 'destacados' ? ' selected' : '' ?>>★ Destacados</option>
    </select>
    <button class="btn small">Filtrar</button>
    <?php if ($q !== '' || $type !== '' || $state !== ''): ?><a class="btn small" href="<?= $link(['q' => '', 'tipo' => '', 'estado' => '']) ?>">Limpar</a><?php endif; ?>
</form>

<section class="card">
    <?php if (!$items): ?>
        <p class="muted"><?= research_configured()
            ? ($q !== '' || $type !== '' || $state !== '' ? 'Nenhum resultado.' : 'Ainda nada importado. Clique em <b>Sincronizar agora</b>.')
            : 'Indique o seu ORCID iD e/ou o Authenticus em <b>Fontes e opções</b>.' ?></p>
    <?php else: ?>
        <form method="post" action="/admin/research/bulk" id="bulk">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="<?= $kindKey ?>">
            <input type="hidden" name="back" value="<?= e($self) ?>">
            <div class="bulk-bar">
                <label class="check"><input type="checkbox" data-check-all> Todos (<?= count($items) ?>)</label>
                <select name="action">
                    <option value="">Ação para os selecionados…</option>
                    <option value="show">Mostrar no site</option>
                    <option value="hide">Esconder do site</option>
                    <option value="feature">★ Destacar</option>
                    <option value="unfeature">Tirar destaque</option>
                </select>
                <button class="btn small">Aplicar</button>
            </div>
        </form>
        <table class="table research-table">
            <thead><tr><th></th><th><?= $tab === 'pubs' ? 'Publicação' : 'Projeto' ?></th><th><?= $tab === 'pubs' ? 'Ano' : 'Período' ?></th><th>Fonte</th><th>No site</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): $id = (int) $it['id']; ?>
                <tr class="<?= $it['visible'] ? '' : 'is-hidden' ?>">
                    <td><input type="checkbox" name="ids[]" value="<?= $id ?>" form="bulk" aria-label="Selecionar"></td>
                    <td>
                        <a href="/admin/research/<?= $kindKey ?>/<?= $id ?>"><?= e($tab === 'projects' && $it['acronym'] && !str_starts_with($it['title'], $it['acronym']) ? $it['acronym'] . ' — ' . $it['title'] : $it['title']) ?></a>
                        <div class="muted small">
                            <?php if ($tab === 'pubs'): ?>
                                <span class="badge"><?= e(PUB_TYPES[$it['type']] ?? $it['type']) ?></span>
                                <?= e(mb_strimwidth($it['venue'], 0, 80, '…')) ?>
                                <?php if ($it['indexed']): ?> · <?= e(str_replace(',', ' · ', $it['indexed'])) ?><?php endif; ?>
                                <?php if (!$it['authors']): ?> · <em>sem autores</em><?php endif; ?>
                            <?php else: ?>
                                <?= e($it['funder']) ?><?php if ($it['grant_number']): ?> · <?= e($it['grant_number']) ?><?php endif; ?>
                                <?php if (project_ongoing($it)): ?> · <span class="badge">Em curso</span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="muted nowrap"><?= e($tab === 'pubs' ? (string) ($it['year'] ?: '—') : project_years($it)) ?></td>
                    <td class="small nowrap"><?= e(str_replace([',', 'orcid', 'authenticus', 'manual'], [' + ', 'ORCID', 'Authenticus', 'Manual'], $it['sources'])) ?></td>
                    <td class="nowrap">
                        <form method="post" action="/admin/research/<?= $kindKey ?>/<?= $id ?>/visible" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($self) ?>">
                            <button class="btn small" title="Mostrar ou esconder no site"><?= $it['visible'] ? 'Visível' : 'Escondido' ?></button>
                        </form>
                        <form method="post" action="/admin/research/<?= $kindKey ?>/<?= $id ?>/featured" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($self) ?>">
                            <button class="btn small<?= $it['featured'] ? ' primary' : '' ?>" title="Destacar (aparece no topo da página)" aria-pressed="<?= $it['featured'] ? 'true' : 'false' ?>">★</button>
                        </form>
                    </td>
                    <td><a class="btn small" href="/admin/research/<?= $kindKey ?>/<?= $id ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<script>
document.querySelector('[data-check-all]')?.addEventListener('change', e => {
    document.querySelectorAll('input[name="ids[]"]').forEach(c => { c.checked = e.target.checked; });
});
</script>
