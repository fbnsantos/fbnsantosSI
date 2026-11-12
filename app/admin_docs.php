<?php
/* Backoffice: documentos Markdown partilhados com assistentes de IA (API em app/docs.php). */
declare(strict_types=1);

require_once __DIR__ . '/docs.php';

/** Devolve true se tratou o pedido. */
function route_admin_docs(string $sub, string $method): bool
{
    if ($sub === '/docs') {
        $method === 'POST' ? admin_doc_create() : admin_docs();
    } elseif (preg_match('#^/docs/(\d+)$#', $sub, $m)) {
        $method === 'POST' ? admin_doc_save((int) $m[1]) : admin_doc_edit(admin_doc_or_404((int) $m[1]));
    } elseif (preg_match('#^/docs/(\d+)/version$#', $sub, $m)) {
        // Consultado pelo editor para avisar quando um assistente altera o documento.
        $doc = admin_doc_or_404((int) $m[1]);
        header('Content-Type: application/json');
        echo json_encode(['version' => (int) $doc['version'], 'updated_by' => $doc['updated_by'], 'updated_at' => $doc['updated_at']]);
    } elseif (preg_match('#^/docs/(\d+)/download$#', $sub, $m)) {
        $doc = admin_doc_or_404((int) $m[1]);
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . (slugify($doc['title']) ?: 'documento') . '.md"');
        echo $doc['content'];
    } elseif (preg_match('#^/docs/(\d+)/key$#', $sub, $m) && $method === 'POST') {
        $doc = admin_doc_or_404((int) $m[1]);
        [$key, $cols] = doc_new_key();
        db()->prepare('UPDATE md_docs SET key_hash = ?, key_prefix = ?, key_enc = ? WHERE id = ?')
            ->execute([$cols['key_hash'], $cols['key_prefix'], $cols['key_enc'], $doc['id']]);
        $_SESSION['md_doc_key'] = [$doc['id'] => $key];
        flash('Chave nova criada. A anterior deixou de funcionar: atualize as instruções nos assistentes.');
        redirect("/admin/docs/{$doc['id']}#assistente");
    } elseif (preg_match('#^/docs/(\d+)/delete$#', $sub, $m) && $method === 'POST') {
        db()->prepare('DELETE FROM md_docs WHERE id = ?')->execute([(int) $m[1]]);
        flash('Documento apagado.');
        redirect('/admin/docs');
    } elseif (preg_match('#^/docs/(\d+)/history$#', $sub, $m)) {
        $doc = admin_doc_or_404((int) $m[1]);
        $st = db()->prepare('SELECT id, version, title, source, note, created_at, length(content) AS chars FROM md_doc_revisions WHERE doc_id = ? ORDER BY id DESC');
        $st->execute([$doc['id']]);
        $rev = null;
        if (isset($_GET['v'])) {
            $sv = db()->prepare('SELECT * FROM md_doc_revisions WHERE doc_id = ? AND id = ?');
            $sv->execute([$doc['id'], (int) $_GET['v']]);
            $rev = $sv->fetch() ?: null;
        }
        admin_render('doc-history', ['title' => 'Histórico: ' . $doc['title'], 'doc' => $doc, 'revisions' => $st->fetchAll(), 'rev' => $rev]);
    } elseif (preg_match('#^/docs/(\d+)/history/(\d+)/restore$#', $sub, $m) && $method === 'POST') {
        $doc = admin_doc_or_404((int) $m[1]);
        $st = db()->prepare('SELECT * FROM md_doc_revisions WHERE doc_id = ? AND id = ?');
        $st->execute([$doc['id'], (int) $m[2]]);
        $rev = $st->fetch();
        if ($rev) {
            doc_save($doc, $rev['title'], $rev['content'], 'backoffice', 'Reposta a versão ' . $rev['version']);
            flash('Versão ' . $rev['version'] . ' reposta (como nova versão; nada se perdeu).');
        }
        redirect("/admin/docs/{$doc['id']}");
    } else {
        return false;
    }
    return true;
}

function admin_doc_or_404(int $id): array
{
    $doc = doc_find($id);
    if (!$doc) {
        flash('Documento não encontrado.', 'error');
        redirect('/admin/docs');
    }
    return $doc;
}

function admin_docs(): void
{
    $docs = db()->query('SELECT id, title, access, key_prefix, version, updated_by, updated_at, last_api_at, length(content) AS chars
        FROM md_docs ORDER BY updated_at DESC')->fetchAll();
    admin_render('docs', ['title' => 'Documentos MD', 'docs' => $docs, 'openapiUrl' => abs_url('/api/doc/openapi.json')]);
}

function admin_doc_create(): void
{
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
    if ($title === '') {
        flash('Dê um título ao documento.', 'error');
        redirect('/admin/docs');
    }
    [$id, $key] = doc_create($title, "# $title\n\n");
    $_SESSION['md_doc_key'] = [$id => $key];
    flash('Documento criado, com a sua própria chave para os assistentes.');
    redirect("/admin/docs/$id");
}

function admin_doc_edit(array $doc, ?array $conflict = null): void
{
    // Chave em claro: decifrada, ou acabada de criar (se o setup_key não permitir cifrar, só se vê uma vez).
    $fresh = $_SESSION['md_doc_key'][$doc['id']] ?? null;
    unset($_SESSION['md_doc_key']);
    $key = $fresh ?? doc_key($doc);
    admin_render('doc-edit', [
        'title' => $doc['title'],
        'doc' => $doc,
        'key' => $key,
        'prompt' => doc_prompt($doc, $key),
        'conflict' => $conflict,
        'apiUrl' => abs_url('/api/doc'),
        'openapiUrl' => abs_url('/api/doc/openapi.json'),
    ]);
}

function admin_doc_save(int $id): void
{
    $doc = admin_doc_or_404($id);
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200) ?: $doc['title'];
    $content = str_replace("\r\n", "\n", (string) ($_POST['content'] ?? ''));
    $access = isset(DOC_ACCESS[$_POST['access'] ?? '']) ? $_POST['access'] : $doc['access'];
    $instructions = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['instructions'] ?? ''))), 0, 4000);
    if (strlen($content) > DOC_MAX_BYTES) {
        flash('O documento é demasiado grande (máximo ' . intdiv(DOC_MAX_BYTES, 1_000_000) . ' MB).', 'error');
        redirect("/admin/docs/$id");
    }
    db()->prepare('UPDATE md_docs SET access = ?, instructions = ? WHERE id = ?')->execute([$access, $instructions, $id]);
    $doc = ['access' => $access, 'instructions' => $instructions] + $doc;

    // Se um assistente alterou o documento enquanto estava a editar, não escreve por cima sem confirmação:
    // volta a mostrar o texto com um aviso; guardar outra vez (já com a versão atual) confirma.
    try {
        doc_save($doc, $title, $content, 'backoffice', trim((string) ($_POST['note'] ?? '')), (int) ($_POST['base_version'] ?? 0));
    } catch (DocConflict $c) {
        $current = doc_find($id);
        admin_doc_edit(['title' => $title, 'content' => $content] + $current, [
            'version' => $c->currentVersion,
            'by' => $current['updated_by'],
            'at' => $current['updated_at'],
        ]);
        return;
    }
    flash('Guardado.');
    redirect("/admin/docs/$id");
}
