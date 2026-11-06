<?php
/* Backoffice: Destaques, Redes sociais (YouTube, LinkedIn) e partilha de artigos no LinkedIn. */
declare(strict_types=1);

/** Devolve true se tratou o pedido. */
function route_admin_social(string $sub, string $method): bool
{
    if ($sub === '/highlights') {
        $method === 'POST' ? highlight_add() : admin_highlights();
    } elseif (preg_match('#^/highlights/(\d+)$#', $sub, $m)) {
        $method === 'POST' ? highlight_save((int) $m[1]) : highlight_edit((int) $m[1]);
    } elseif (preg_match('#^/highlights/(\d+)/(visible|featured)$#', $sub, $m) && $method === 'POST') {
        db()->prepare("UPDATE highlights SET {$m[2]} = 1 - {$m[2]} WHERE id = ?")->execute([(int) $m[1]]);
        redirect('/admin/highlights');
    } elseif (preg_match('#^/highlights/(\d+)/delete$#', $sub, $m) && $method === 'POST') {
        highlight_delete((int) $m[1]);
    } elseif ($sub === '/social') {
        admin_social($method);
    } elseif ($sub === '/social/youtube-sync' && $method === 'POST') {
        $r = youtube_sync(true);
        is_int($r) ? flash($r === 0 ? 'Nenhum vídeo novo no canal.' : "$r vídeo(s) novo(s) importado(s) para Destaques.") : flash($r, 'error');
        redirect((string) ($_POST['back'] ?? '') === 'highlights' ? '/admin/highlights' : '/admin/social');
    } elseif ($sub === '/linkedin/connect' && $method === 'POST') {
        if (!linkedin_configured()) {
            flash('Primeiro guarde o Client ID e o Client Secret da app do LinkedIn.', 'error');
            redirect('/admin/social');
        }
        redirect(linkedin_auth_url());
    } elseif ($sub === '/linkedin/callback') {
        $error = linkedin_callback($_GET);
        $error === null ? flash('LinkedIn ligado: já pode publicar os artigos no seu perfil.') : flash($error, 'error');
        redirect('/admin/social');
    } elseif ($sub === '/linkedin/disconnect' && $method === 'POST') {
        linkedin_disconnect();
        flash('LinkedIn desligado neste site. Para retirar o acesso por completo: LinkedIn → Definições → Dados → Serviços autorizados.');
        redirect('/admin/social');
    } elseif (preg_match('#^/(pages|posts)/(\d+)/linkedin$#', $sub, $m) && $method === 'POST') {
        share_to_linkedin($m[1], (int) $m[2]);
    } else {
        return false;
    }
    return true;
}

/* ---------- Destaques ---------- */

function admin_highlights(): void
{
    youtube_sync_in_background();
    $kind = (string) ($_GET['tipo'] ?? '');
    $kind = isset(HIGHLIGHT_KINDS[$kind]) ? $kind : '';
    $st = db()->prepare('SELECT * FROM highlights' . ($kind ? ' WHERE kind = ?' : '') . ' ORDER BY featured DESC, item_date DESC');
    $st->execute($kind ? [$kind] : []);
    admin_render('highlights', [
        'title' => 'Destaques',
        'items' => $st->fetchAll(),
        'kind' => $kind,
        'channel' => youtube_channel_id(),
    ]);
}

function highlight_add(): void
{
    $url = highlight_normalize_url((string) ($_POST['url'] ?? ''));
    if (!preg_match('#^https?://[^\s]+$#i', $url) || !safe_url($url, true)) {
        flash('Cole um endereço completo (https://…).', 'error');
        redirect('/admin/highlights');
    }
    $st = db()->prepare('SELECT id FROM highlights WHERE url = ?');
    $st->execute([$url]);
    if ($id = $st->fetchColumn()) {
        flash('Este link já está nos destaques.', 'error');
        redirect("/admin/highlights/$id");
    }
    $meta = highlight_fetch_meta($url);
    $image = '';
    if (!empty($_FILES['image_file']['name'])) {
        try {
            $image = handle_upload($_FILES['image_file'], $meta['title'])['url'];
        } catch (RuntimeException $ex) {
            flash('Imagem: ' . $ex->getMessage(), 'error');
        }
    }
    $image = $image ?: highlight_store_image($meta['image'], $meta['title']);
    db()->prepare("INSERT INTO highlights (kind, url, title, description, image, item_date, featured, visible, source, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'manual', ?)")->execute([
        highlight_kind($url), $url, mb_substr($meta['title'], 0, 300), mb_substr($meta['description'], 0, 600), $image,
        $meta['date'] ?: now(), isset($_POST['featured']) ? 1 : 0, now(),
    ]);
    $id = (int) db()->lastInsertId();
    flash($meta['title'] !== ''
        ? 'Adicionado. Reveja o título, o texto e a imagem.'
        : 'Adicionado, mas a rede não deixou ler a pré-visualização. Escreva o título e o texto e envie uma imagem (ex.: captura de ecrã).',
        $meta['title'] !== '' ? 'ok' : 'error');
    redirect("/admin/highlights/$id");
}

function highlight_edit(int $id): void
{
    $st = db()->prepare('SELECT * FROM highlights WHERE id = ?');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) {
        flash('Não encontrado.', 'error');
        redirect('/admin/highlights');
    }
    admin_render('highlight-edit', ['title' => 'Editar destaque', 'item' => $item]);
}

function highlight_save(int $id): void
{
    $in = fn(string $k) => trim((string) ($_POST[$k] ?? ''));
    $image = $in('image');
    if ($image !== '' && !safe_url($image, false)) {
        $image = '';
    }
    if (!empty($_FILES['image_file']['name'])) {
        try {
            $image = handle_upload($_FILES['image_file'], $in('title'))['url'];
        } catch (RuntimeException $ex) {
            flash('Imagem: ' . $ex->getMessage(), 'error');
            redirect("/admin/highlights/$id");
        }
    }
    $kind = $in('kind');
    $ts = strtotime($in('item_date'));
    db()->prepare('UPDATE highlights SET kind = ?, title = ?, description = ?, image = ?, item_date = ?, featured = ?, visible = ? WHERE id = ?')
        ->execute([
            isset(HIGHLIGHT_KINDS[$kind]) ? $kind : 'link',
            mb_substr($in('title'), 0, 300), mb_substr($in('description'), 0, 600), $image,
            $ts ? date('Y-m-d H:i:s', $ts) : now(),
            isset($_POST['featured']) ? 1 : 0, isset($_POST['visible']) ? 1 : 0, $id,
        ]);
    flash('Guardado.');
    redirect('/admin/highlights');
}

function highlight_delete(int $id): void
{
    $st = db()->prepare('SELECT source FROM highlights WHERE id = ?');
    $st->execute([$id]);
    if ($st->fetchColumn() === 'youtube') {
        // Se fosse apagado, voltava na próxima importação do canal: fica só escondido.
        db()->prepare('UPDATE highlights SET visible = 0, featured = 0 WHERE id = ?')->execute([$id]);
        flash('Vídeo escondido (os vídeos importados do canal não se apagam, senão voltavam na próxima importação).');
    } else {
        db()->prepare('DELETE FROM highlights WHERE id = ?')->execute([$id]);
        flash('Removido dos destaques.');
    }
    redirect('/admin/highlights');
}

/* ---------- Redes sociais ---------- */

function admin_social(string $method): void
{
    if ($method === 'POST') {
        $section = (string) ($_POST['section'] ?? '');
        if ($section === 'youtube') {
            $input = trim((string) ($_POST['youtube_channel'] ?? ''));
            $channel = $input === '' ? '' : youtube_resolve_channel($input);
            if ($input !== '' && $channel === '') {
                flash('Não encontrei o canal. Cole o URL do canal (youtube.com/@nome ou youtube.com/channel/UC…).', 'error');
                redirect('/admin/social');
            }
            setting_set('youtube_channel_id', $channel);
            setting_set('youtube_auto_visible', isset($_POST['youtube_auto_visible']) ? '1' : '0');
            setting_set('youtube_sync_at', '0');
            $r = $channel !== '' ? youtube_sync(true) : 0;
            flash(is_int($r) ? 'Guardado.' . ($r ? " $r vídeo(s) importado(s) para Destaques." : '') : $r, is_int($r) ? 'ok' : 'error');
        } elseif ($section === 'linkedin') {
            require_once ROOT . '/app/api.php';
            if (api_cipher_key() === null) {
                flash('Defina primeiro um setup_key próprio no config.php: é usado para cifrar o segredo do LinkedIn.', 'error');
                redirect('/admin/social');
            }
            linkedin_save_app((string) ($_POST['linkedin_client_id'] ?? ''), (string) ($_POST['linkedin_client_secret'] ?? ''));
            flash('Dados da app do LinkedIn guardados.');
        } elseif ($section === 'highlights') {
            setting_set('highlights_title', trim((string) ($_POST['highlights_title'] ?? '')));
            setting_set('highlights_home', isset($_POST['highlights_home']) ? '1' : '0');
            flash('Guardado.');
        }
        redirect('/admin/social');
    }
    admin_render('social', [
        'title' => 'Redes sociais',
        'channel' => youtube_channel_id(),
        'videos' => (int) db()->query("SELECT COUNT(*) FROM highlights WHERE source = 'youtube'")->fetchColumn(),
        'shares' => db()->query("SELECT s.*, COALESCE(p.title, g.title) AS title FROM social_shares s
            LEFT JOIN posts p ON s.content_type = 'posts' AND p.id = s.content_id
            LEFT JOIN pages g ON s.content_type = 'pages' AND g.id = s.content_id
            ORDER BY s.id DESC LIMIT 10")->fetchAll(),
    ]);
}

/* ---------- Partilhar um artigo/página no LinkedIn ---------- */

function share_to_linkedin(string $type, int $id): void
{
    $st = db()->prepare("SELECT * FROM $type WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !$row['published'] || ($type === 'posts' && $row['published_at'] > now())) {
        flash('Só se pode partilhar conteúdo já publicado no site.', 'error');
        redirect("/admin/$type/$id");
    }
    $text = trim((string) ($_POST['text'] ?? '')) ?: linkedin_default_text($type, $row);
    [$ok, $result] = linkedin_share($type, $row, mb_substr($text, 0, 2900));
    $ok ? flash('Publicado no LinkedIn.') : flash($result, 'error');
    redirect("/admin/$type/$id#partilhar");
}
