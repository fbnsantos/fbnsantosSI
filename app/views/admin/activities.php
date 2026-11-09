<div class="head">
    <h1>Palestras, prémios e imprensa</h1>
    <div class="actions">
        <?php if (has_activities()): ?><a class="btn" href="/activities" target="_blank">Ver no site ↗</a><?php endif; ?>
        <a class="btn primary" href="/admin/activities/new<?= $kind ? '?tipo=' . $kind : '' ?>">+ Novo (à mão)</a>
    </div>
</div>
<p class="muted">Cronologia de apresentações, prémios, notícias e entrevistas, mostrada em <a href="/activities" target="_blank">/activities</a>.
    Os marcados com ★ aparecem também na página inicial. Os prémios entram nos dados que o Google lê sobre si.</p>

<section class="card">
    <h2>Adicionar a partir de um link</h2>
    <form method="post" action="/admin/activities" class="highlight-add">
        <?= csrf_field() ?>
        <label>Link
            <input type="url" name="url" required placeholder="Notícia, página do evento ou do prémio, vídeo do YouTube…">
        </label>
        <label>Tipo
            <select name="kind">
                <?php foreach (ACTIVITY_KINDS as $k => [$plural, $one]): ?>
                    <option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($one) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary">Ler o link</button>
    </form>
    <p class="hint">O site lê o título, o meio ou organizador, a data e a imagem da página; depois revê e completa antes de guardar.
        Se o link for um vídeo do YouTube, fica como vídeo do item.</p>
</section>

<nav class="stats-range" aria-label="Filtrar">
    <a class="btn small<?= $kind === '' ? ' primary' : '' ?>" href="/admin/activities">Todos (<?= array_sum($counts) ?>)</a>
    <?php foreach (ACTIVITY_KINDS as $k => [$plural]): if (empty($counts[$k])) continue; ?>
        <a class="btn small<?= $kind === $k ? ' primary' : '' ?>" href="/admin/activities?tipo=<?= $k ?>"><?= e($plural) ?> (<?= (int) $counts[$k] ?>)</a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <?php if (!$items): ?>
        <p class="muted">Ainda nada. Cole acima o link de uma notícia, de um evento onde falou ou de um prémio.</p>
    <?php else: ?>
        <table class="table highlights-table">
            <thead><tr><th></th><th>Título</th><th>Tipo</th><th>Data</th><th>No site</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $a): $id = (int) $a['id']; ?>
                <tr class="<?= $a['visible'] ? '' : 'is-hidden' ?>">
                    <td><?php if ($a['image']): ?><img class="hl-thumb" src="<?= e($a['image']) ?>" alt="" loading="lazy"><?php endif; ?></td>
                    <td>
                        <a href="/admin/activities/<?= $id ?>"><?= e($a['title']) ?></a>
                        <div class="muted small"><?= e(implode(' · ', array_filter([$a['event'], $a['location']]))) ?><?= $a['video_url'] ? ' · ▶ vídeo' : '' ?></div>
                    </td>
                    <td><span class="badge"><?= e(ACTIVITY_KINDS[$a['kind']][1] ?? $a['kind']) ?></span></td>
                    <td class="muted nowrap"><?= e($a['item_date'] ?: '—') ?></td>
                    <td class="nowrap">
                        <form method="post" action="/admin/activities/<?= $id ?>/visible" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="tipo" value="<?= e($kind) ?>">
                            <button class="btn small" title="Mostrar ou esconder no site"><?= $a['visible'] ? 'Visível' : 'Escondido' ?></button>
                        </form>
                        <form method="post" action="/admin/activities/<?= $id ?>/featured" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="tipo" value="<?= e($kind) ?>">
                            <button class="btn small<?= $a['featured'] ? ' primary' : '' ?>" title="Destacar (aparece na página inicial)" aria-pressed="<?= $a['featured'] ? 'true' : 'false' ?>">★</button>
                        </form>
                    </td>
                    <td><a class="btn small" href="/admin/activities/<?= $id ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<details class="card">
    <summary><h2 style="display:inline">Opções</h2></summary>
    <form method="post" action="/admin/activities/settings">
        <?= csrf_field() ?>
        <label>Título da página
            <input name="activities_title" value="<?= e(setting('activities_title')) ?>" placeholder="<?= e(t('Palestras, prémios e imprensa')) ?>">
        </label>
        <label class="check"><input type="checkbox" name="activities_home" <?= setting('activities_home', '1') === '1' ? 'checked' : '' ?>>
            Mostrar na página inicial (os ★ destacados; se não houver, os 3 mais recentes)</label>
        <button class="btn primary">Guardar</button>
    </form>
</details>
