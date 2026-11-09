<?php
/* Backoffice: Investigação (publicações e projetos sincronizados do ORCID e do Authenticus). */
declare(strict_types=1);

const RESEARCH_TABLES = ['pub' => 'publications', 'project' => 'projects'];

/** Devolve true se tratou o pedido. */
function route_admin_research(string $sub, string $method): bool
{
    if ($sub === '/research') {
        admin_research();
    } elseif ($sub === '/research/settings' && $method === 'POST') {
        research_save_settings();
    } elseif ($sub === '/research/sync' && $method === 'POST') {
        $msg = research_sync(true);
        flash('Sincronizado. ' . $msg, setting('research_sync_ok') === '1' ? 'ok' : 'error');
        redirect('/admin/research');
    } elseif ($sub === '/research/bulk' && $method === 'POST') {
        research_bulk();
    } elseif (preg_match('#^/research/(pub|project)/(new|\d+)$#', $sub, $m)) {
        $method === 'POST' ? research_item_save($m[1], $m[2]) : research_item_edit($m[1], $m[2]);
    } elseif (preg_match('#^/research/(pub|project)/(\d+)/(visible|featured)$#', $sub, $m) && $method === 'POST') {
        $table = RESEARCH_TABLES[$m[1]];
        db()->prepare("UPDATE $table SET {$m[3]} = 1 - {$m[3]} WHERE id = ?")->execute([(int) $m[2]]);
        redirect(research_back($m[1]));
    } elseif (preg_match('#^/research/(pub|project)/(\d+)/delete$#', $sub, $m) && $method === 'POST') {
        research_item_delete($m[1], (int) $m[2]);
    } else {
        return false;
    }
    return true;
}

/** Volta à lista onde se estava (mantém filtros). */
function research_back(string $kind): string
{
    $back = (string) ($_POST['back'] ?? '');
    return preg_match('#^/admin/research(\?[\w=&%+.\-]*)?$#', $back) ? $back : '/admin/research' . ($kind === 'project' ? '?tab=projetos' : '');
}

function admin_research(): void
{
    research_sync_in_background();
    $tab = ($_GET['tab'] ?? '') === 'projetos' ? 'projects' : 'pubs';
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['tipo'] ?? '');
    $state = (string) ($_GET['estado'] ?? '');
    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = $tab === 'pubs' ? '(title LIKE ? OR authors LIKE ? OR venue LIKE ? OR doi LIKE ?)' : '(title LIKE ? OR acronym LIKE ? OR funder LIKE ? OR grant_number LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if ($tab === 'pubs' && isset(PUB_TYPES[$type])) {
        $where[] = 'type = ?';
        $args[] = $type;
    }
    if ($state === 'escondidos') {
        $where[] = 'visible = 0';
    } elseif ($state === 'destacados') {
        $where[] = 'featured = 1';
    }
    $sql = $tab === 'pubs'
        ? 'SELECT * FROM publications' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY year DESC, pub_date DESC, id DESC'
        : 'SELECT * FROM projects' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY (end_date = \'\' OR end_date >= date(\'now\')) DESC, start_date DESC, id DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    admin_render('research', [
        'title' => 'Investigação',
        'tab' => $tab,
        'items' => $st->fetchAll(),
        'q' => $q,
        'type' => $type,
        'state' => $state,
        'counts' => [
            'pubs' => (int) db()->query('SELECT COUNT(*) FROM publications')->fetchColumn(),
            'projects' => (int) db()->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
            'types' => db()->query('SELECT type, COUNT(*) FROM publications GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR),
        ],
    ]);
}

function research_save_settings(): void
{
    $in = fn(string $k) => trim((string) ($_POST[$k] ?? ''));
    $orcid = $in('research_orcid');
    if ($orcid !== '' && !preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i', $orcid, $m)) {
        flash('ORCID iD inválido (formato 0000-0000-0000-0000).', 'error');
        redirect('/admin/research');
    }
    setting_set('research_orcid', $orcid !== '' ? strtoupper($m[1]) : '');
    $auth = $in('research_authenticus');
    $authId = $auth === '' ? '' : authenticus_resolve($auth);
    if ($authId === null) {
        flash('Não reconheci o Authenticus: cole o URL da sua lista de publicações (…/publicationsList/12345) ou o identificador R-XXX-XXX.', 'error');
        redirect('/admin/research');
    }
    setting_set('research_authenticus', $authId);
    setting_set('research_auto_visible', isset($_POST['research_auto_visible']) ? '1' : '0');
    setting_set('research_title', mb_substr($in('research_title'), 0, 80));
    $names = implode("\n", array_filter(array_map('trim', preg_split('/\R/', mb_substr($in('research_names'), 0, 2000)) ?: [])));
    if ($names !== implode("\n", setting_lines('research_names'))) {
        setting_set('research_names_auto', $names === '' ? '1' : '0'); // editado à mão: a sincronização deixa de mexer
    }
    setting_set('research_names', $names);
    setting_set('research_sync_at', '0'); // fontes podem ter mudado: sincroniza já a seguir
    flash('Guardado. A sincronização corre em segundo plano; recarregue a página daqui a um minuto.');
    redirect('/admin/research');
}

function research_bulk(): void
{
    $kind = ($_POST['kind'] ?? '') === 'project' ? 'project' : 'pub';
    $table = RESEARCH_TABLES[$kind];
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $actions = ['show' => 'visible = 1', 'hide' => 'visible = 0', 'feature' => 'featured = 1', 'unfeature' => 'featured = 0'];
    $action = (string) ($_POST['action'] ?? '');
    if ($ids && isset($actions[$action])) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE $table SET {$actions[$action]} WHERE id IN ($marks)")->execute($ids);
        flash(count($ids) . ' alterado(s).');
    } else {
        flash('Selecione pelo menos um item e uma ação.', 'error');
    }
    redirect(research_back($kind));
}

function research_item(string $kind, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM ' . RESEARCH_TABLES[$kind] . ' WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function research_item_edit(string $kind, string $id): void
{
    $item = $id === 'new' ? null : research_item($kind, (int) $id);
    if ($id !== 'new' && !$item) {
        flash('Não encontrado.', 'error');
        redirect('/admin/research');
    }
    $blank = $kind === 'pub'
        ? ['id' => 0, 'title' => '', 'authors' => '', 'venue' => '', 'year' => (int) date('Y'), 'pub_date' => '', 'type' => 'article',
            'doi' => '', 'url' => '', 'indexed' => '', 'sources' => 'manual', 'featured' => 0, 'visible' => 1]
        : ['id' => 0, 'title' => '', 'acronym' => '', 'funder' => '', 'type' => '', 'start_date' => '', 'end_date' => '', 'grant_number' => '',
            'url' => '', 'description' => '', 'role' => '', 'sources' => 'manual', 'featured' => 0, 'visible' => 1];
    admin_render('research-edit', [
        'title' => ($item ? 'Editar ' : 'Nova ') . ($kind === 'pub' ? 'publicação' : 'projeto'),
        'kind' => $kind,
        'item' => $item ?? $blank,
    ]);
}

function research_item_save(string $kind, string $id): void
{
    $in = fn(string $k, int $max = 500) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $title = research_clean($in('title'));
    $norm = research_norm($title);
    $back = $id === 'new' ? "/admin/research/$kind/new" : "/admin/research/$kind/$id";
    if ($norm === '') {
        flash('O título é obrigatório.', 'error');
        redirect($back);
    }
    $table = RESEARCH_TABLES[$kind];
    $st = db()->prepare("SELECT id FROM $table WHERE norm = ? AND id <> ?");
    $st->execute([$norm, $id === 'new' ? 0 : (int) $id]);
    if ($other = $st->fetchColumn()) {
        flash('Já existe um item com este título.', 'error');
        redirect("/admin/research/$kind/$other");
    }
    $url = $in('url');
    $url = $url !== '' && safe_url($url, true) ? $url : '';
    $date = fn(string $k) => preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $in($k, 10)) ? $in($k, 10) : '';
    if ($kind === 'pub') {
        $type = $in('type');
        $pubDate = $date('pub_date');
        $f = [
            'title' => $title, 'norm' => $norm, 'authors' => $in('authors', 3000), 'venue' => $in('venue', 300),
            'year' => (int) ($in('year', 4) ?: substr($pubDate, 0, 4)), 'pub_date' => $pubDate,
            'type' => isset(PUB_TYPES[$type]) ? $type : 'other', 'doi' => research_doi($in('doi')), 'url' => $url,
            'indexed' => implode(',', array_intersect((array) ($_POST['indexed'] ?? []), ['Scopus', 'WoS'])),
        ];
    } else {
        $f = [
            'title' => $title, 'norm' => $norm, 'acronym' => $in('acronym', 40), 'funder' => $in('funder', 200), 'type' => $in('type', 40),
            'start_date' => $date('start_date'), 'end_date' => $date('end_date'), 'grant_number' => $in('grant_number', 120),
            'url' => $url, 'description' => $in('description', 1500), 'role' => $in('role', 120),
        ];
    }
    $f['featured'] = isset($_POST['featured']) ? 1 : 0;
    $f['visible'] = isset($_POST['visible']) ? 1 : 0;
    $f['updated_at'] = now();
    if ($id === 'new') {
        $f['sources'] = 'manual';
        $f['created_at'] = now();
        $cols = implode(', ', array_keys($f));
        db()->prepare("INSERT INTO $table ($cols) VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    } else {
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db()->prepare("UPDATE $table SET $cols WHERE id = ?")->execute([...array_values($f), (int) $id]);
    }
    flash('Guardado.');
    redirect('/admin/research' . ($kind === 'project' ? '?tab=projetos' : ''));
}

/** Itens sincronizados são escondidos (a sincronização voltaria a criá-los); só os manuais são apagados. */
function research_item_delete(string $kind, int $id): void
{
    $item = research_item($kind, $id);
    if ($item) {
        $table = RESEARCH_TABLES[$kind];
        if ($item['sources'] === 'manual') {
            db()->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            flash('Apagado.');
        } else {
            db()->prepare("UPDATE $table SET visible = 0 WHERE id = ?")->execute([$id]);
            flash('Escondido do site (vem do ORCID/Authenticus: se fosse apagado, voltava na próxima sincronização).');
        }
    }
    redirect('/admin/research' . ($kind === 'project' ? '?tab=projetos' : ''));
}
