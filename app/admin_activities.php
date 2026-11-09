<?php
/* Backoffice: Palestras, prémios e imprensa. */
declare(strict_types=1);

/** Devolve true se tratou o pedido. */
function route_admin_activities(string $sub, string $method): bool
{
    if ($sub === '/activities') {
        $method === 'POST' ? activity_add() : admin_activities();
    } elseif ($sub === '/activities/settings' && $method === 'POST') {
        setting_set('activities_title', mb_substr(trim((string) ($_POST['activities_title'] ?? '')), 0, 80));
        setting_set('activities_home', isset($_POST['activities_home']) ? '1' : '0');
        flash('Guardado.');
        redirect('/admin/activities');
    } elseif (preg_match('#^/activities/(new|\d+)$#', $sub, $m)) {
        $method === 'POST' ? activity_save($m[1]) : activity_edit($m[1]);
    } elseif (preg_match('#^/activities/(\d+)/(visible|featured)$#', $sub, $m) && $method === 'POST') {
        db()->prepare("UPDATE activities SET {$m[2]} = 1 - {$m[2]} WHERE id = ?")->execute([(int) $m[1]]);
        redirect('/admin/activities' . (preg_match('/^\w+$/', (string) ($_POST['tipo'] ?? '')) && $_POST['tipo'] !== '' ? '?tipo=' . $_POST['tipo'] : ''));
    } elseif (preg_match('#^/activities/(\d+)/delete$#', $sub, $m) && $method === 'POST') {
        db()->prepare('DELETE FROM activities WHERE id = ?')->execute([(int) $m[1]]);
        flash('Apagado.');
        redirect('/admin/activities');
    } else {
        return false;
    }
    return true;
}

function admin_activities(): void
{
    $kind = (string) ($_GET['tipo'] ?? '');
    $kind = isset(ACTIVITY_KINDS[$kind]) ? $kind : '';
    $st = db()->prepare('SELECT * FROM activities' . ($kind ? ' WHERE kind = ?' : '') . " ORDER BY item_date = '', item_date DESC, id DESC");
    $st->execute($kind ? [$kind] : []);
    admin_render('activities', [
        'title' => 'Palestras, prémios e imprensa',
        'items' => $st->fetchAll(),
        'kind' => $kind,
        'counts' => db()->query('SELECT kind, COUNT(*) FROM activities GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR),
    ]);
}

/** "Adicionar a partir de um link": lê a página e abre o formulário já preenchido. */
function activity_add(): void
{
    $kind = (string) ($_POST['kind'] ?? 'talk');
    $kind = isset(ACTIVITY_KINDS[$kind]) ? $kind : 'talk';
    $url = trim((string) ($_POST['url'] ?? ''));
    $item = activity_blank($kind);
    if ($url !== '') {
        $url = highlight_normalize_url($url);
        if (!preg_match('#^https?://\S+$#i', $url) || !safe_url($url, true)) {
            flash('Cole um endereço completo (https://…).', 'error');
            redirect('/admin/activities');
        }
        $meta = highlight_fetch_meta($url);
        $isVideo = youtube_id($url) !== null;
        $item = array_merge($item, [
            'title' => mb_substr($meta['title'], 0, 300),
            'event' => mb_substr($isVideo ? $meta['description'] : $meta['site'], 0, 200),
            'description' => $isVideo ? '' : mb_substr($meta['description'], 0, 1000),
            'item_date' => $meta['date'] ? substr($meta['date'], 0, 10) : '',
            'url' => $isVideo ? '' : $url,
            'video_url' => $isVideo ? $url : '',
            'image' => highlight_store_image($meta['image'], $meta['title']),
        ]);
        flash($meta['title'] !== ''
            ? 'Preenchido a partir do link: reveja e complete (data, local, tipo) e clique em Guardar.'
            : 'O site não conseguiu ler a página: preencha os campos à mão e clique em Guardar.', $meta['title'] !== '' ? 'ok' : 'error');
    }
    admin_render('activity-edit', ['title' => 'Novo item', 'item' => $item, 'videos' => activity_video_options()]);
}

function activity_blank(string $kind = 'talk'): array
{
    return ['id' => 0, 'kind' => $kind, 'title' => '', 'event' => '', 'location' => '', 'item_date' => '', 'url' => '',
        'description' => '', 'image' => '', 'video_url' => '', 'featured' => 0, 'visible' => 1];
}

function activity_edit(string $id): void
{
    $item = null;
    if ($id !== 'new') {
        $st = db()->prepare('SELECT * FROM activities WHERE id = ?');
        $st->execute([(int) $id]);
        $item = $st->fetch() ?: null;
        if (!$item) {
            flash('Não encontrado.', 'error');
            redirect('/admin/activities');
        }
    }
    admin_render('activity-edit', [
        'title' => $item ? 'Editar' : 'Novo item',
        'item' => $item ?? activity_blank((string) ($_GET['tipo'] ?? 'talk')),
        'videos' => activity_video_options(),
    ]);
}

function activity_save(string $id): void
{
    $in = fn(string $k, int $max = 300) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $title = $in('title');
    if ($title === '') {
        flash('O título é obrigatório.', 'error');
        redirect('/admin/activities/' . $id);
    }
    $kind = $in('kind');
    $url = $in('url', 1000);
    $url = $url !== '' && safe_url($url, true) ? $url : '';
    $video = $in('video_url', 500) ?: $in('video_pick', 500);
    $video = $video !== '' && youtube_id($video) ? 'https://www.youtube.com/watch?v=' . youtube_id($video) : '';
    $image = $in('image', 500);
    $image = $image !== '' && safe_url($image, false) ? $image : '';
    if (!empty($_FILES['image_file']['name'])) {
        try {
            $image = handle_upload($_FILES['image_file'], $title)['url'];
        } catch (RuntimeException $ex) {
            flash('Imagem: ' . $ex->getMessage(), 'error');
        }
    }
    if ($image === '' && $video !== '') {
        // Usa a imagem do destaque, se o vídeo já lá estiver; senão copia a miniatura do YouTube.
        $st = db()->prepare("SELECT image FROM highlights WHERE url = ? AND image <> ''");
        $st->execute([$video]);
        $image = (string) ($st->fetchColumn() ?: highlight_store_image('https://i.ytimg.com/vi/' . youtube_id($video) . '/hqdefault.jpg', $title));
    }
    $f = [
        'kind' => isset(ACTIVITY_KINDS[$kind]) ? $kind : 'other',
        'title' => $title,
        'event' => $in('event', 200),
        'location' => $in('location', 150),
        'item_date' => activity_clean_date($in('item_date', 10)),
        'url' => $url,
        'description' => $in('description', 1500),
        'image' => $image,
        'video_url' => $video,
        'featured' => isset($_POST['featured']) ? 1 : 0,
        'visible' => isset($_POST['visible']) ? 1 : 0,
        'updated_at' => now(),
    ];
    if ($id === 'new') {
        $f['created_at'] = now();
        db()->prepare('INSERT INTO activities (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_fill(0, count($f), '?')) . ')')
            ->execute(array_values($f));
    } else {
        db()->prepare('UPDATE activities SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')
            ->execute([...array_values($f), (int) $id]);
    }
    if ($f['visible']) {
        indexnow_ping(['/activities']);
    }
    flash('Guardado.');
    redirect('/admin/activities');
}
