<?php
declare(strict_types=1);

const RESERVED_SLUGS = ['admin', 'blog', 'home', 'feed', 'sitemap', 'robots', 'assets', 'uploads', 'favicon'];
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW = 900; // 15 minutos

function route_admin(string $sub, string $method): void
{
    session_boot();
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    if ($method === 'POST') {
        csrf_check();
    }
    if ($sub === '/setup') {
        admin_setup($method);
        return;
    }
    if (user_count() === 0) {
        redirect('/admin/setup');
    }
    if ($sub === '/login') {
        admin_login($method);
        return;
    }
    if (!current_user_id()) {
        redirect('/admin/login');
    }

    if ($sub === '/') {
        admin_dashboard();
    } elseif ($sub === '/logout' && $method === 'POST') {
        session_destroy();
        redirect('/admin/login');
    } elseif (preg_match('#^/(pages|posts)$#', $sub, $m)) {
        admin_list($m[1]);
    } elseif (preg_match('#^/(pages|posts)/(new|\d+)$#', $sub, $m)) {
        $id = $m[2] === 'new' ? null : (int) $m[2];
        $method === 'POST' ? admin_save($m[1], $id) : admin_edit($m[1], $id);
    } elseif (preg_match('#^/(pages|posts)/(\d+)/delete$#', $sub, $m) && $method === 'POST') {
        // A página inicial não pode ser apagada.
        db()->prepare("DELETE FROM {$m[1]} WHERE id = ? AND slug <> 'home'")->execute([(int) $m[2]]);
        flash('Apagado.');
        redirect("/admin/{$m[1]}");
    } elseif ($sub === '/media') {
        admin_media();
    } elseif ($sub === '/media/upload' && $method === 'POST') {
        admin_media_upload();
    } elseif ($sub === '/media.json') {
        header('Content-Type: application/json');
        $rows = db()->query("SELECT path, original_name, alt FROM media WHERE mime LIKE 'image/%' ORDER BY id DESC LIMIT 200")->fetchAll();
        echo json_encode(array_map(fn($r) => ['title' => $r['alt'] ?: $r['original_name'], 'value' => $r['path']], $rows));
    } elseif (preg_match('#^/media/(\d+)$#', $sub, $m) && $method === 'POST') {
        db()->prepare('UPDATE media SET alt = ? WHERE id = ?')->execute([trim($_POST['alt'] ?? ''), (int) $m[1]]);
        flash('Texto alternativo guardado.');
        redirect('/admin/media');
    } elseif (preg_match('#^/media/(\d+)/delete$#', $sub, $m) && $method === 'POST') {
        delete_media((int) $m[1]);
        flash('Ficheiro apagado.');
        redirect('/admin/media');
    } elseif ($sub === '/settings') {
        admin_settings($method);
    } elseif ($sub === '/password') {
        admin_password($method);
    } elseif ($sub === '/update') {
        admin_update($method);
    } else {
        http_response_code(404);
        admin_render('message', ['title' => 'Não encontrado', 'message' => 'Esta página do backoffice não existe.']);
    }
}

function admin_render(string $view, array $vars = []): void
{
    render("admin/$view", $vars, 'admin/layout');
}

/* ---------- Atualizar o site a partir do GitHub (mesmo código do git.php) ---------- */

function admin_update(string $method): void
{
    global $CONFIG;
    require_once ROOT . '/app/deploy.php';
    $config = (array) $CONFIG;
    $result = null;
    if ($method === 'POST') {
        $result = deploy_run($config, 'backoffice');
    }
    admin_render('update', [
        'title' => 'Atualizar site',
        'branch' => deploy_branch($config),
        'version' => deploy_current_version($config),
        'result' => $result,
    ]);
}

/* ---------- Autenticação ---------- */

/** true se este IP já falhou demasiadas vezes (login e setup). */
function too_many_attempts(): bool
{
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - LOGIN_WINDOW]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([client_ip()]);
    return (int) $st->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_failed_attempt(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([client_ip(), time()]);
}

function admin_setup(string $method): void
{
    if (user_count() > 0) {
        redirect('/admin/login');
    }
    $key = (string) config('setup_key', '');
    $error = null;
    if ($key === '' || $key === 'mude-isto') {
        admin_render('message', ['title' => 'Configuração inicial',
            'message' => 'Defina um "setup_key" próprio em config.php (copie config.sample.php) antes de criar o administrador.']);
        return;
    }
    if ($method === 'POST') {
        $user = trim($_POST['username'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        if (too_many_attempts()) {
            $error = 'Demasiadas tentativas. Tente de novo dentro de 15 minutos.';
        } elseif (!hash_equals($key, (string) ($_POST['setup_key'] ?? ''))) {
            record_failed_attempt();
            $error = 'Chave de configuração errada.';
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $user)) {
            $error = 'Nome de utilizador inválido (3–64 caracteres, sem espaços).';
        } elseif (mb_strlen($pass) < 10) {
            $error = 'A palavra-passe deve ter pelo menos 10 caracteres.';
        } else {
            db()->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), now()]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) db()->lastInsertId();
            flash('Administrador criado. Bem-vindo!');
            redirect('/admin');
        }
    }
    admin_render('setup', ['title' => 'Configuração inicial', 'error' => $error, 'bare' => true]);
}

function admin_login(string $method): void
{
    if (current_user_id()) {
        redirect('/admin');
    }
    $error = null;
    if ($method === 'POST') {
        if (too_many_attempts()) {
            $error = 'Demasiadas tentativas. Tente de novo dentro de 15 minutos.';
        } else {
            $st = db()->prepare('SELECT * FROM users WHERE username = ?');
            $st->execute([trim($_POST['username'] ?? '')]);
            $user = $st->fetch();
            // Verifica sempre um hash (mesmo sem utilizador) para o tempo de resposta não revelar se o nome existe.
            $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
            if (password_verify((string) ($_POST['password'] ?? ''), $hash) && $user) {
                db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $user['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['uid'] = (int) $user['id'];
                redirect('/admin');
            }
            record_failed_attempt();
            $error = 'Utilizador ou palavra-passe errados.';
        }
    }
    admin_render('login', ['title' => 'Entrar', 'error' => $error, 'bare' => true]);
}

function admin_password(string $method): void
{
    $error = null;
    if ($method === 'POST') {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([current_user_id()]);
        $user = $st->fetch();
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), $user['password_hash'])) {
            $error = 'A palavra-passe atual está errada.';
        } elseif (mb_strlen($new) < 10) {
            $error = 'A nova palavra-passe deve ter pelo menos 10 caracteres.';
        } elseif ($new !== ($_POST['confirm'] ?? '')) {
            $error = 'As palavras-passe não coincidem.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('Palavra-passe alterada.');
            redirect('/admin');
        }
    }
    admin_render('password', ['title' => 'Palavra-passe', 'error' => $error]);
}

/* ---------- Painel ---------- */

function admin_dashboard(): void
{
    $counts = [
        'pages' => (int) db()->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
        'posts' => (int) db()->query('SELECT COUNT(*) FROM posts')->fetchColumn(),
        'drafts' => (int) db()->query('SELECT COUNT(*) FROM posts WHERE published = 0')->fetchColumn(),
        'media' => (int) db()->query('SELECT COUNT(*) FROM media')->fetchColumn(),
    ];
    $recent = db()->query("SELECT 'posts' AS type, id, title, updated_at FROM posts
        UNION ALL SELECT 'pages', id, title, updated_at FROM pages ORDER BY updated_at DESC LIMIT 8")->fetchAll();
    $checks = seo_checks();
    admin_render('dashboard', ['title' => 'Painel'] + compact('counts', 'recent', 'checks'));
}

/** Avisos simples de SEO mostrados no painel. */
function seo_checks(): array
{
    $c = [];
    if (!indexing_allowed()) {
        $c[] = ['warn', 'A indexação está desativada: o robots.txt bloqueia o Google. Ative-a em Definições quando o site estiver pronto.'];
    }
    if (str_contains(base_url(), 'localhost') || str_contains(base_url(), '127.0.0.1')) {
        $c[] = ['info', 'O URL do site é ' . base_url() . '. Em produção, defina base_url em config.php (https://fbnsantos.com).'];
    }
    if (!setting('google_site_verification')) {
        $c[] = ['info', 'Adicione o código de verificação do Google Search Console em Definições e submeta ' . abs_url('/sitemap.xml') . '.'];
    }
    if (!setting('author_image') && !setting('default_image')) {
        $c[] = ['info', 'Defina uma fotografia ou imagem de partilha em Definições (aparece no Google e nas redes sociais).'];
    }
    if (!social_links()) {
        $c[] = ['info', 'Adicione os seus perfis (LinkedIn, GitHub…) em Definições: ligam o site à sua identidade no Google.'];
    }
    $n = (int) db()->query("SELECT COUNT(*) FROM posts WHERE published = 1 AND meta_description = '' AND excerpt = ''")->fetchColumn();
    if ($n) {
        $c[] = ['info', "$n artigo(s) sem descrição/resumo: o Google usará um excerto automático."];
    }
    $n = (int) db()->query("SELECT COUNT(*) FROM media WHERE mime LIKE 'image/%' AND alt = ''")->fetchColumn();
    if ($n) {
        $c[] = ['info', "$n imagem(ns) sem texto alternativo na biblioteca de média."];
    }
    return $c;
}

/* ---------- Páginas e artigos ---------- */

function admin_list(string $type): void
{
    $order = $type === 'pages' ? "slug <> 'home', menu_order, title" : 'published_at DESC';
    $items = db()->query("SELECT * FROM $type ORDER BY $order")->fetchAll();
    admin_render('list', ['title' => $type === 'pages' ? 'Páginas' : 'Artigos'] + compact('type', 'items'));
}

function empty_item(string $type): array
{
    return [
        'id' => null, 'slug' => '', 'title' => '', 'meta_description' => '', 'content' => '',
        'cover_image' => '', 'published' => 1, 'noindex' => 0,
        'in_menu' => 1, 'menu_order' => 0,
        'excerpt' => '', 'tags' => '', 'published_at' => now(),
    ];
}

function admin_edit(string $type, ?int $id, ?array $item = null, array $errors = []): void
{
    if ($item === null) {
        if ($id === null) {
            $item = empty_item($type);
        } else {
            $st = db()->prepare("SELECT * FROM $type WHERE id = ?");
            $st->execute([$id]);
            $item = $st->fetch();
            if (!$item) {
                flash('Não encontrado.', 'error');
                redirect("/admin/$type");
            }
        }
    }
    $label = $type === 'pages' ? 'página' : 'artigo';
    $title = $id ? "Editar $label" : ($type === 'pages' ? 'Nova página' : 'Novo artigo');
    admin_render('edit', compact('type', 'item', 'errors', 'title', 'label'));
}

function admin_save(string $type, ?int $id): void
{
    $in = fn(string $k) => trim((string) ($_POST[$k] ?? ''));
    $item = [
        'title' => $in('title'),
        'slug' => slugify($in('slug') ?: $in('title')),
        'meta_description' => $in('meta_description'),
        'content' => sanitize_html((string) ($_POST['content'] ?? '')),
        'cover_image' => $in('cover_image'),
        'published' => isset($_POST['published']) ? 1 : 0,
        'noindex' => isset($_POST['noindex']) ? 1 : 0,
    ];
    if ($type === 'pages') {
        $item['in_menu'] = isset($_POST['in_menu']) ? 1 : 0;
        $item['menu_order'] = (int) $in('menu_order');
    } else {
        $item['excerpt'] = $in('excerpt');
        $item['tags'] = implode(', ', array_filter(array_map('trim', explode(',', $in('tags')))));
        $ts = strtotime($in('published_at'));
        $item['published_at'] = $ts ? date('Y-m-d H:i:s', $ts) : now();
    }

    $errors = [];
    if ($item['title'] === '') {
        $errors[] = 'O título é obrigatório.';
    }
    if ($item['slug'] === '') {
        $errors[] = 'O endereço (slug) é obrigatório.';
    }
    $isHome = $type === 'pages' && $id && $item['slug'] === 'home';
    if ($type === 'pages' && in_array($item['slug'], RESERVED_SLUGS, true) && !$isHome) {
        $errors[] = "O endereço \"/{$item['slug']}\" está reservado. Escolha outro.";
    }
    $st = db()->prepare("SELECT id FROM $type WHERE slug = ? AND id IS NOT ?");
    $st->execute([$item['slug'], $id]);
    if ($st->fetchColumn()) {
        $errors[] = 'Já existe outro conteúdo com este endereço.';
    }
    if ($item['cover_image'] !== '' && !safe_url($item['cover_image'], false)) {
        $errors[] = 'A imagem de capa deve ser um URL (https://…) ou um caminho começado por /.';
    }

    if (!$errors && !empty($_FILES['cover_file']['name'])) {
        try {
            $item['cover_image'] = handle_upload($_FILES['cover_file'], $item['title'])['url'];
        } catch (RuntimeException $ex) {
            $errors[] = 'Capa: ' . $ex->getMessage();
        }
    }

    if ($errors) {
        admin_edit($type, $id, ['id' => $id] + $item + empty_item($type), $errors);
        return;
    }

    $item['updated_at'] = now();
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($item)));
        db()->prepare("UPDATE $type SET $sets WHERE id = :id")->execute($item + ['id' => $id]);
    } else {
        $item['created_at'] = $item['updated_at'];
        $cols = array_keys($item);
        db()->prepare("INSERT INTO $type (" . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($item);
        $id = (int) db()->lastInsertId();
    }
    if ($item['published'] && !$item['noindex']) {
        indexnow_ping([public_url($type, $item)]);
    }
    flash('Guardado.');
    redirect("/admin/$type/$id");
}

function public_url(string $type, array $item): string
{
    if ($type === 'posts') {
        return '/blog/' . $item['slug'];
    }
    return $item['slug'] === 'home' ? '/' : '/' . $item['slug'];
}

/* ---------- Média ---------- */

function admin_media(): void
{
    $items = db()->query('SELECT * FROM media ORDER BY id DESC')->fetchAll();
    admin_render('media', ['title' => 'Imagens e ficheiros', 'items' => $items]);
}

/** Usado pelo formulário da biblioteca e pelo editor (resposta JSON {location}). */
function admin_media_upload(): void
{
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    $files = $_FILES['file'] ?? null;
    if (!$files) {
        $wantsJson ? json_error('Nenhum ficheiro.') : redirect('/admin/media');
    }
    // Normaliza envio múltiplo (name="file[]") e simples.
    $list = is_array($files['name'])
        ? array_map(fn($i) => array_combine(array_keys($files), array_column($files, $i)), array_keys($files['name']))
        : [$files];

    $done = 0;
    $errors = [];
    $last = null;
    foreach ($list as $f) {
        try {
            $last = handle_upload($f, trim($_POST['alt'] ?? ''));
            $done++;
        } catch (RuntimeException $ex) {
            $errors[] = ($f['name'] ?? '') . ': ' . $ex->getMessage();
        }
    }
    if ($wantsJson) {
        $last ? print(json_encode(['location' => $last['url']])) : json_error(implode(' ', $errors));
        return;
    }
    flash($errors ? implode(' ', $errors) : "$done ficheiro(s) enviado(s).", $errors ? 'error' : 'ok');
    redirect('/admin/media');
}

function json_error(string $msg): never
{
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => $msg]]);
    exit;
}

/* ---------- Definições ---------- */

const SETTING_FIELDS = [
    'site_name', 'site_tagline', 'site_description', 'site_lang', 'author_name', 'author_job',
    'author_headline', 'author_tagline', 'author_works_for', 'author_alumni_of', 'author_knows_about',
    'author_image', 'default_image', 'blog_title', 'footer_text', 'footer_links', 'profile_links', 'contact_email',
    'social_linkedin', 'social_github', 'social_x', 'social_youtube', 'social_instagram',
    'social_scholar', 'social_orcid',
    'google_site_verification', 'bing_site_verification', 'google_analytics_id',
];

function admin_settings(string $method): void
{
    if ($method === 'POST') {
        $st = db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?)
            ON CONFLICT(name) DO UPDATE SET value = excluded.value');
        foreach (SETTING_FIELDS as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            // Aceita a meta tag inteira colada do Search Console e extrai só o código.
            if (in_array($k, ['google_site_verification', 'bing_site_verification'], true)
                && preg_match('/content="([^"]+)"/', $v, $m)) {
                $v = $m[1];
            }
            $st->execute([$k, $v]);
        }
        $st->execute(['allow_indexing', isset($_POST['allow_indexing']) ? '1' : '0']);
        $st->execute(['indexnow_enabled', isset($_POST['indexnow_enabled']) ? '1' : '0']);
        foreach (['author_image_file' => 'author_image', 'default_image_file' => 'default_image'] as $field => $key) {
            if (!empty($_FILES[$field]['name'])) {
                try {
                    $st->execute([$key, handle_upload($_FILES[$field], setting('author_name'))['url']]);
                } catch (RuntimeException $ex) {
                    flash($ex->getMessage(), 'error');
                    redirect('/admin/settings');
                }
            }
        }
        flash('Definições guardadas.');
        redirect('/admin/settings');
    }
    admin_render('settings', ['title' => 'Definições']);
}
