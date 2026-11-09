<?php
$id = (int) $item['id'];
$action = '/admin/research/' . $kind . '/' . ($id ?: 'new');
$back = '/admin/research' . ($kind === 'project' ? '?tab=projetos' : '');
$synced = $item['sources'] !== 'manual';
?>
<form method="post" action="<?= $action ?>">
    <?= csrf_field() ?>
    <div class="head">
        <h1><?= e($title) ?></h1>
        <div class="actions">
            <a class="btn" href="<?= $back ?>">← Voltar</a>
            <?php if ($item['url']): ?><a class="btn" href="<?= e($item['url']) ?>" target="_blank" rel="noopener">Abrir original ↗</a><?php endif; ?>
            <button class="btn primary">Guardar</button>
        </div>
    </div>
    <?php if ($synced && $id): ?>
        <p class="muted">Importado de <?= e(str_replace([',', 'orcid', 'authenticus'], [' e ', 'ORCID', 'Authenticus'], $item['sources'])) ?>.
            As suas alterações ficam: a sincronização só preenche campos vazios.</p>
    <?php endif; ?>
    <div class="settings-grid">
        <section class="card">
            <label>Título <textarea name="title" rows="3" maxlength="500" required><?= e($item['title']) ?></textarea></label>
            <?php if ($kind === 'pub'): ?>
                <label>Autores <textarea name="authors" rows="3" maxlength="3000" placeholder="Apelido, Nome; Apelido, Nome"><?= e($item['authors']) ?></textarea></label>
                <label>Revista / conferência / livro <input name="venue" value="<?= e($item['venue']) ?>" maxlength="300"></label>
                <label>Tipo
                    <select name="type">
                        <?php foreach (PUB_TYPES as $k => $lbl): ?>
                            <option value="<?= $k ?>"<?= $item['type'] === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <label>Sigla <input name="acronym" value="<?= e($item['acronym']) ?>" maxlength="40" placeholder="ROMOVI"></label>
                <label>Financiador <input name="funder" value="<?= e($item['funder']) ?>" maxlength="200" placeholder="FCT, Comissão Europeia (Horizonte Europa)…"></label>
                <label>O seu papel <input name="role" value="<?= e($item['role']) ?>" maxlength="120" placeholder="Investigador responsável, coordenador, membro…"></label>
                <label>Descrição curta <textarea name="description" rows="4" maxlength="1500"><?= e($item['description']) ?></textarea></label>
            <?php endif; ?>
        </section>
        <section class="card">
            <?php if ($kind === 'pub'): ?>
                <label>Ano <input type="number" name="year" min="1950" max="2100" value="<?= e((string) ($item['year'] ?: '')) ?>"></label>
                <label>Data (opcional) <input name="pub_date" value="<?= e($item['pub_date']) ?>" placeholder="AAAA-MM-DD" pattern="\d{4}(-\d{2}(-\d{2})?)?"></label>
                <label>DOI <input name="doi" value="<?= e($item['doi']) ?>" placeholder="10.1000/xyz123"></label>
                <label>Link <input type="url" name="url" value="<?= e($item['url']) ?>" placeholder="https://…"></label>
                <p class="hint">Se houver DOI, o título liga a doi.org; senão, a este link.</p>
                <p><b>Indexado em</b><br>
                    <?php foreach (['Scopus', 'WoS'] as $ix): ?>
                        <label class="check" style="display:inline-flex;margin-right:16px"><input type="checkbox" name="indexed[]" value="<?= $ix ?>" <?= in_array($ix, explode(',', $item['indexed']), true) ? 'checked' : '' ?>> <?= $ix === 'WoS' ? 'Web of Science' : $ix ?></label>
                    <?php endforeach; ?>
                </p>
            <?php else: ?>
                <label>Início <input name="start_date" value="<?= e($item['start_date']) ?>" placeholder="AAAA-MM" pattern="\d{4}(-\d{2}(-\d{2})?)?"></label>
                <label>Fim <input name="end_date" value="<?= e($item['end_date']) ?>" placeholder="AAAA-MM (vazio = em curso)" pattern="\d{4}(-\d{2}(-\d{2})?)?"></label>
                <label>Referência / n.º do contrato <input name="grant_number" value="<?= e($item['grant_number']) ?>" maxlength="120"></label>
                <label>Link do projeto <input type="url" name="url" value="<?= e($item['url']) ?>" placeholder="https://…"></label>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="visible" <?= $item['visible'] ? 'checked' : '' ?>> Visível no site</label>
            <label class="check"><input type="checkbox" name="featured" <?= $item['featured'] ? 'checked' : '' ?>> ★ Destacar (aparece no topo)</label>
        </section>
    </div>
</form>
<?php if ($id): ?>
    <form method="post" action="/admin/research/<?= $kind ?>/<?= $id ?>/delete" class="card">
        <?= csrf_field() ?>
        <?php if ($synced): ?>
            <button class="btn danger" data-confirm="Esconder do site?">Esconder do site</button>
            <span class="hint">Vem do ORCID/Authenticus: se fosse apagado, voltava na próxima sincronização. Para o remover de vez, retire-o lá.</span>
        <?php else: ?>
            <button class="btn danger" data-confirm="Apagar definitivamente? Não pode ser desfeito.">Apagar</button>
        <?php endif; ?>
    </form>
<?php endif; ?>
