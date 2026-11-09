<?php
$id = (int) $item['id'];
$labels = [
    'talk' => ['Evento / organizador', 'ex.: IROS 2025 — keynote', 'Cidade, país'],
    'award' => ['Atribuído por', 'ex.: Ordem dos Engenheiros', 'Cidade, país (opcional)'],
    'press' => ['Meio de comunicação', 'ex.: Público, RTP, Expresso', ''],
    'interview' => ['Meio de comunicação / programa', 'ex.: Antena 1 — 90 Segundos de Ciência', ''],
    'other' => ['Organizador / contexto', '', 'Local (opcional)'],
];
[$eventLabel, $eventPh, $locPh] = $labels[$item['kind']] ?? $labels['other'];
?>
<form method="post" enctype="multipart/form-data" action="/admin/activities/<?= $id ?: 'new' ?>">
    <?= csrf_field() ?>
    <div class="head">
        <h1><?= e($title) ?></h1>
        <div class="actions">
            <a class="btn" href="/admin/activities">← Voltar</a>
            <?php if ($item['url']): ?><a class="btn" href="<?= e($item['url']) ?>" target="_blank" rel="noopener">Abrir link ↗</a><?php endif; ?>
            <button class="btn primary">Guardar</button>
        </div>
    </div>
    <div class="settings-grid">
        <section class="card">
            <label>Tipo
                <select name="kind">
                    <?php foreach (ACTIVITY_KINDS as $k => [$plural, $one]): ?>
                        <option value="<?= $k ?>"<?= $item['kind'] === $k ? ' selected' : '' ?>><?= e($one) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Título <textarea name="title" rows="2" maxlength="300" required placeholder="ex.: Robótica para vinhas de encosta (palestra convidada)"><?= e($item['title']) ?></textarea></label>
            <label><?= e($eventLabel) ?> <input name="event" value="<?= e($item['event']) ?>" maxlength="200" placeholder="<?= e($eventPh) ?>"></label>
            <label>Local <input name="location" value="<?= e($item['location']) ?>" maxlength="150" placeholder="<?= e($locPh) ?>"></label>
            <label>Data <input name="item_date" value="<?= e($item['item_date']) ?>" placeholder="AAAA-MM-DD, AAAA-MM ou AAAA" pattern="(\d{4}(-\d{2}(-\d{2})?)?|\d{1,2}/\d{1,2}/\d{4})"></label>
            <label>Texto curto <textarea name="description" rows="4" maxlength="1500" placeholder="Uma ou duas frases: o tema, o público, o que foi reconhecido…"><?= e($item['description']) ?></textarea></label>
            <label>Link <input type="url" name="url" value="<?= e($item['url']) ?>" placeholder="https://… (notícia, página do evento ou do prémio)"></label>
            <label class="check"><input type="checkbox" name="visible" <?= $item['visible'] ? 'checked' : '' ?>> Visível no site</label>
            <label class="check"><input type="checkbox" name="featured" <?= $item['featured'] ? 'checked' : '' ?>> ★ Destacar (aparece também na página inicial)</label>
        </section>
        <section class="card">
            <h2>Vídeo</h2>
            <?php if ($videos): ?>
                <label>Dos Destaques
                    <select name="video_pick">
                        <option value="">— nenhum —</option>
                        <?php foreach ($videos as $vurl => $vtitle): ?>
                            <option value="<?= e($vurl) ?>"<?= $item['video_url'] === $vurl ? ' selected' : '' ?>><?= e(mb_strimwidth($vtitle, 0, 80, '…')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label><?= $videos ? '…ou link do YouTube' : 'Link do YouTube' ?>
                <input name="video_url" value="<?= e(isset($videos[$item['video_url']]) ? '' : $item['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=…">
            </label>
            <p class="hint">O vídeo toca na própria página (sem cookies do YouTube até carregar em ▶).</p>
            <h2 style="margin-top:18px">Imagem</h2>
            <?php if ($item['image']): ?><img class="thumb" src="<?= e($item['image']) ?>" alt=""><?php endif; ?>
            <label>URL <input name="image" value="<?= e($item['image']) ?>" placeholder="/uploads/…"></label>
            <label>…ou enviar <input type="file" name="image_file" accept="image/*"></label>
            <p class="hint">Uma foto do momento (no palco, a receber o prémio) ou o recorte da notícia. Com vídeo e sem imagem, usa a miniatura do vídeo.</p>
        </section>
    </div>
</form>
<?php if ($id): ?>
    <form method="post" action="/admin/activities/<?= $id ?>/delete" class="card">
        <?= csrf_field() ?>
        <button class="btn danger" data-confirm="Apagar definitivamente este item? Não pode ser desfeito.">Apagar</button>
    </form>
<?php endif; ?>
