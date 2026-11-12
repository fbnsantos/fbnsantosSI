<?php
/*
 * Documentos Markdown partilhados com assistentes de IA.
 *
 * Cada documento tem a sua própria chave: quem a tiver só vê e altera esse documento.
 *
 *   GET    /api/doc                    ler (JSON; ?format=md devolve só o Markdown)
 *   PUT    /api/doc                    substituir o texto todo (exige base_version)
 *   PATCH  /api/doc                    trocar excertos: {"edits": [{"find": "...", "replace": "..."}]}
 *   POST   /api/doc/append             acrescentar no fim ou no fim de uma secção ("section": "## Título")
 *   GET    /api/doc/history            versões anteriores
 *   GET    /api/doc/history/{versão}   texto de uma versão
 *   GET    /api/doc/openapi.json       especificação OpenAPI 3.1 (sem autenticação)
 *
 * Autenticação: "Authorization: Bearer <chave do documento>" (ou "X-API-Key"). Só para leitura (GET)
 * aceita-se também "?key=<chave>", para assistentes que apenas conseguem abrir um link.
 * Cada alteração (API ou backoffice) incrementa "version" e fica no histórico; a "base_version" evita
 * que duas pessoas/assistentes escrevam por cima uma da outra.
 */
declare(strict_types=1);

const DOC_MAX_BYTES = 2_000_000;
const DOC_MAX_REVISIONS = 200;
const DOC_ACCESS = ['write' => 'Ler e escrever', 'read' => 'Só ler'];

/* ---------- Modelo ---------- */

function doc_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM md_docs WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Gera uma chave nova; devolve [chave, colunas a gravar]. */
function doc_new_key(): array
{
    require_once ROOT . '/app/api.php';
    $key = 'md_' . bin2hex(random_bytes(24));
    return [$key, ['key_hash' => hash('sha256', $key), 'key_prefix' => substr($key, 0, 9), 'key_enc' => api_token_encrypt($key)]];
}

/** Chave em claro (cifrada com o setup_key), ou null se não puder ser recuperada. */
function doc_key(array $doc): ?string
{
    require_once ROOT . '/app/api.php';
    return $doc['key_enc'] !== '' ? api_token_decrypt($doc['key_enc']) : null;
}

/** Cria um documento; devolve [id, chave]. */
function doc_create(string $title, string $content = '', string $source = 'backoffice'): array
{
    [$key, $cols] = doc_new_key();
    $now = now();
    $row = ['title' => $title, 'content' => $content, 'updated_by' => $source, 'created_at' => $now, 'updated_at' => $now] + $cols;
    db()->prepare('INSERT INTO md_docs (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
        ->execute(array_values($row));
    $id = (int) db()->lastInsertId();
    doc_add_revision($id, 1, $title, $content, $source, 'Criado');
    return [$id, $key];
}

/**
 * Grava uma nova versão (título e/ou texto). Devolve o documento atualizado.
 * Se $baseVersion for dado e não for a versão atual, lança DocConflict (alguém alterou entretanto).
 */
function doc_save(array $doc, string $title, string $content, string $source, string $note = '', ?int $baseVersion = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $current = doc_find((int) $doc['id']);
        if ($current === null) {
            throw new RuntimeException('O documento já não existe.');
        }
        if ($baseVersion !== null && $baseVersion !== (int) $current['version']) {
            throw new DocConflict((int) $current['version']);
        }
        if ($title === $current['title'] && $content === $current['content']) {
            $pdo->commit();
            return $current;
        }
        $version = (int) $current['version'] + 1;
        $now = now();
        $pdo->prepare('UPDATE md_docs SET title = ?, content = ?, version = ?, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([$title, $content, $version, $source, $now, $current['id']]);
        doc_add_revision((int) $current['id'], $version, $title, $content, $source, $note);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return doc_find((int) $doc['id']);
}

function doc_add_revision(int $docId, int $version, string $title, string $content, string $source, string $note): void
{
    db()->prepare('INSERT INTO md_doc_revisions (doc_id, version, title, content, source, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$docId, $version, $title, $content, $source, mb_substr($note, 0, 300), now()]);
    // Guarda só as últimas versões.
    db()->prepare('DELETE FROM md_doc_revisions WHERE doc_id = ? AND id NOT IN
        (SELECT id FROM md_doc_revisions WHERE doc_id = ? ORDER BY id DESC LIMIT ' . DOC_MAX_REVISIONS . ')')
        ->execute([$docId, $docId]);
}

final class DocConflict extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct("O documento foi alterado entretanto (versão atual: $currentVersion).");
    }
}

/** Títulos do documento (ignora o que está dentro de blocos de código). */
function doc_outline(string $md): array
{
    $out = [];
    $fence = false;
    foreach (preg_split('/\R/', $md) as $i => $line) {
        if (preg_match('/^\s{0,3}(```|~~~)/', $line)) {
            $fence = !$fence;
        } elseif (!$fence && preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
            $out[] = ['level' => strlen($m[1]), 'title' => $m[2], 'line' => $i + 1];
        }
    }
    return $out;
}

/**
 * Acrescenta texto no fim do documento ou no fim de uma secção ($section = linha do título, ex.: "## Ideias").
 * Devolve null se a secção não existir.
 */
function doc_append_text(string $md, string $text, string $section = ''): ?string
{
    $text = trim($text, "\r\n");
    if ($section === '') {
        return rtrim($md) === '' ? $text . "\n" : rtrim($md) . "\n\n" . $text . "\n";
    }
    $want = preg_replace('/\s+/', ' ', trim($section));
    $lines = preg_split('/\R/', $md);
    $start = null;
    $level = 0;
    foreach (doc_outline($md) as $h) {
        if ($start === null && (str_repeat('#', $h['level']) . ' ' . $h['title'] === $want || $h['title'] === ltrim($want, '# '))) {
            $start = $h['line'];
            $level = $h['level'];
        } elseif ($start !== null && $h['level'] <= $level) {
            $end = $h['line'] - 1;
            break;
        }
    }
    if ($start === null) {
        return null;
    }
    $end ??= count($lines);
    // Insere depois da última linha não vazia da secção.
    $at = $end;
    while ($at > $start && trim($lines[$at - 1]) === '') {
        $at--;
    }
    // Um item acrescentado a uma lista continua a lista (sem linha em branco).
    $item = '/^\s*([-*+]|\d+[.)])\s/';
    $continues = preg_match($item, $lines[$at - 1]) && preg_match($item, $text);
    array_splice($lines, $at, 0, $continues ? [$text] : ['', $text]);
    return implode("\n", $lines);
}

/* ---------- API ---------- */

function route_api_doc(string $sub, string $method): never
{
    $sub = '/' . trim($sub, '/');
    if ($sub === '/openapi.json' && $method === 'GET') {
        api_send(doc_openapi());
    }
    $doc = doc_auth($method === 'GET');
    $source = 'API';

    if ($sub === '/') {
        match ($method) {
            'GET' => doc_api_get($doc),
            'PUT' => doc_api_replace($doc, $source),
            'PATCH' => doc_api_edit($doc, $source),
            default => api_error(405, 'Método não permitido. Use GET, PUT, PATCH ou POST /doc/append.'),
        };
    }
    if ($sub === '/append') {
        $method === 'POST' ? doc_api_append($doc, $source) : api_error(405, 'Use POST.');
    }
    if ($sub === '/history' && $method === 'GET') {
        $st = db()->prepare('SELECT version, title, source, note, created_at, length(content) AS chars FROM md_doc_revisions WHERE doc_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$doc['id']]);
        api_send(['items' => array_map(fn($r) => ['version' => (int) $r['version'], 'chars' => (int) $r['chars']] + $r, $st->fetchAll())]);
    }
    if (preg_match('#^/history/(\d+)$#', $sub, $m) && $method === 'GET') {
        $st = db()->prepare('SELECT version, title, content, source, note, created_at FROM md_doc_revisions WHERE doc_id = ? AND version = ?');
        $st->execute([$doc['id'], (int) $m[1]]);
        $r = $st->fetch() ?: api_error(404, "A versão {$m[1]} não existe (ou já saiu do histórico).");
        api_send(['version' => (int) $r['version']] + $r);
    }
    api_error(404, 'Endpoint não encontrado. Veja ' . abs_url('/api/doc/openapi.json'));
}

/** Autentica pela chave do documento. Em leituras aceita também ?key=. */
function doc_auth(bool $readOnly): array
{
    if (too_many_attempts()) {
        api_error(429, 'Demasiadas tentativas falhadas. Tente de novo dentro de 15 minutos.');
    }
    $key = api_token_from_request();
    if ($key === '' && $readOnly) {
        $key = trim((string) ($_GET['key'] ?? ''));
    }
    $doc = null;
    if ($key !== '') {
        $st = db()->prepare('SELECT * FROM md_docs WHERE key_hash = ?');
        $st->execute([hash('sha256', $key)]);
        $doc = $st->fetch() ?: null;
    }
    if (!$doc) {
        if ($key !== '') {
            record_failed_attempt();
        }
        header('WWW-Authenticate: Bearer');
        api_error(401, 'Chave do documento em falta ou inválida. Envie "Authorization: Bearer <chave>".');
    }
    if (!$readOnly && $doc['access'] !== 'write') {
        api_error(403, 'Este documento está em modo "só leitura" para assistentes.');
    }
    db()->prepare('UPDATE md_docs SET last_api_at = ? WHERE id = ?')->execute([now(), $doc['id']]);
    return $doc;
}

function doc_out(array $doc, bool $withContent = true): array
{
    $out = [
        'id' => (int) $doc['id'],
        'title' => $doc['title'],
        'version' => (int) $doc['version'],
        'access' => $doc['access'],
        'instructions' => $doc['instructions'],
        'updated_at' => $doc['updated_at'],
        'updated_by' => $doc['updated_by'],
        'chars' => mb_strlen($doc['content']),
        'outline' => doc_outline($doc['content']),
    ];
    return $withContent ? $out + ['content' => $doc['content']] : $out;
}

function doc_api_get(array $doc): never
{
    $md = ($_GET['format'] ?? '') === 'md' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/markdown');
    if ($md) {
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Doc-Version: ' . $doc['version']);
        echo $doc['content'];
        exit;
    }
    api_send(doc_out($doc));
}

/** Corpo do pedido: JSON, ou o próprio Markdown (Content-Type text/markdown ou text/plain; base_version e note na query). */
function doc_api_body(): array
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_starts_with($type, 'text/')) {
        return ['content' => (string) file_get_contents('php://input')] + array_intersect_key($_GET, array_flip(['base_version', 'note', 'title', 'section', 'author']));
    }
    return api_body();
}

function doc_text(array $in, string $field, bool $required = true): string
{
    $v = $in[$field] ?? null;
    if ($v === null && !$required) {
        return '';
    }
    if (!is_string($v) || ($required && trim($v) === '')) {
        api_error(422, "Campo \"$field\" em falta ou inválido: tem de ser texto.");
    }
    return str_replace("\r\n", "\n", $v);
}

function doc_base_version(array $in, bool $required): ?int
{
    if (!array_key_exists('base_version', $in)) {
        return $required ? api_error(422, 'Envie "base_version" com a "version" que leu (GET /doc), para não escrever por cima de alterações de outra pessoa.') : null;
    }
    $v = filter_var($in['base_version'], FILTER_VALIDATE_INT);
    return $v === false ? api_error(422, 'Campo "base_version" inválido: use um número inteiro.') : $v;
}

/** Grava pela API e responde (sem o texto, para poupar espaço). */
function doc_api_commit(array $doc, ?string $title, string $content, string $source, array $in, ?int $base): never
{
    if (strlen($content) > DOC_MAX_BYTES) {
        api_error(413, 'O documento ficaria demasiado grande (máximo ' . intdiv(DOC_MAX_BYTES, 1_000_000) . ' MB).');
    }
    $title = $title !== null ? mb_substr(trim($title), 0, 200) : $doc['title'];
    $note = is_string($in['note'] ?? null) ? trim($in['note']) : '';
    $author = is_string($in['author'] ?? null) ? mb_substr(trim(preg_replace('/\s+/', ' ', $in['author'])), 0, 60) : '';
    $source .= $author !== '' ? ": $author" : '';
    try {
        $doc = doc_save($doc, $title !== '' ? $title : $doc['title'], $content, $source, $note, $base);
    } catch (DocConflict $c) {
        api_error(409, $c->getMessage() . ' Leia de novo (GET /doc) e repita a alteração sobre a versão atual.', ['current_version' => $c->currentVersion]);
    }
    api_send(doc_out($doc, false) + ['message' => 'Guardado (versão ' . $doc['version'] . ').']);
}

function doc_api_replace(array $doc, string $source): never
{
    $in = doc_api_body();
    $base = doc_base_version($in, true);
    $title = array_key_exists('title', $in) ? doc_text($in, 'title') : null;
    doc_api_commit($doc, $title, doc_text($in, 'content', false), $source, $in, $base);
}

function doc_api_edit(array $doc, string $source): never
{
    $in = api_body();
    $base = doc_base_version($in, false);
    $edits = $in['edits'] ?? null;
    if (!is_array($edits) || !$edits || !array_is_list($edits)) {
        api_error(422, 'Envie "edits": [{"find": "texto exato atual", "replace": "texto novo"}, …].');
    }
    $content = $doc['content'];
    foreach ($edits as $i => $e) {
        $n = $i + 1;
        if (!is_array($e) || !is_string($e['find'] ?? null) || $e['find'] === '' || !is_string($e['replace'] ?? null)) {
            api_error(422, "Alteração $n: precisa de \"find\" (texto não vazio) e \"replace\" (texto, pode ser vazio para apagar).");
        }
        $find = str_replace("\r\n", "\n", $e['find']);
        $count = substr_count($content, $find);
        if ($count !== 1) {
            api_error(422, "Alteração $n: o texto de \"find\" aparece $count vezes no documento; tem de aparecer exatamente 1. "
                . ($count === 0 ? 'Copie-o exatamente do documento atual (GET /doc).' : 'Inclua mais contexto à volta para o tornar único.'));
        }
        $content = str_replace($find, str_replace("\r\n", "\n", $e['replace']), $content);
    }
    $title = array_key_exists('title', $in) ? doc_text($in, 'title') : null;
    doc_api_commit($doc, $title, $content, $source, $in, $base);
}

function doc_api_append(array $doc, string $source): never
{
    $in = doc_api_body();
    $base = doc_base_version($in, false);
    $section = is_string($in['section'] ?? null) ? $in['section'] : '';
    $content = doc_append_text($doc['content'], doc_text($in, 'content'), $section);
    if ($content === null) {
        api_error(422, "A secção \"$section\" não existe. Veja \"outline\" em GET /doc (ex.: \"## Ideias\").");
    }
    doc_api_commit($doc, null, $content, $source, $in, $base);
}

/* ---------- Instruções para o assistente ---------- */

function doc_prompt(array $doc, ?string $key): string
{
    $key ??= '<CHAVE_DO_DOCUMENTO>';
    $api = abs_url('/api/doc');
    $openapi = abs_url('/api/doc/openapi.json');
    $readUrl = $api . '?format=md&key=' . $key;
    $mode = $doc['access'] === 'write'
        ? 'Podes ler e alterar este documento.'
        : 'Só podes LER este documento (está em modo só leitura; pedidos de escrita dão erro 403). Propõe alterações na conversa.';
    $notes = trim($doc['instructions']) !== '' ? "\nSobre este documento (do autor)\n" . trim($doc['instructions']) . "\n" : '';
    return <<<TXT
    Tens acesso a um documento Markdown partilhado, "{$doc['title']}", através de uma API HTTP. {$mode}
    O documento é a fonte de verdade: lê-o sempre antes de responder ou de alterar, porque o autor e outros
    assistentes também o editam.
    {$notes}
    Ligação
    - URL base: {$api}
    - Chave (só dá acesso a este documento): {$key}
    - Autenticação: cabeçalho "Authorization: Bearer {$key}"
    - Especificação OpenAPI: {$openapi}
    - Se só consegues abrir links (sem cabeçalhos), lê o texto em: {$readUrl}

    Ler
    - GET {$api}                  JSON: title, version, outline (títulos e linhas), content (Markdown), instructions
    - GET {$api}?format=md        só o Markdown (a versão vem no cabeçalho X-Doc-Version)
    - GET {$api}/history          últimas versões (quem alterou, nota, data)
    - GET {$api}/history/{versão} texto de uma versão anterior

    Escrever (JSON, Content-Type: application/json)
    - Acrescentar: POST {$api}/append
        {"content": "texto em Markdown", "section": "## Título da secção (opcional)", "note": "o que fizeste", "author": "Claude"}
      Sem "section" acrescenta no fim; com "section" acrescenta no fim dessa secção.
    - Alterar excertos: PATCH {$api}
        {"base_version": 12, "edits": [{"find": "texto exato atual", "replace": "texto novo"}], "note": "…"}
      Cada "find" tem de aparecer exatamente uma vez no documento (inclui contexto se for preciso);
      "replace": "" apaga o excerto.
    - Substituir tudo: PUT {$api}
        {"base_version": 12, "content": "documento completo", "title": "opcional", "note": "…"}
    - "base_version" é a "version" que leste. Se alguém alterou o documento entretanto recebes 409:
      lê de novo e repete a alteração sobre a versão atual. Nunca reenvies às cegas.

    Regras
    - Prefere "append" e "PATCH" (alterações pequenas); usa PUT só para reorganizar o documento inteiro.
    - Mantém a estrutura e o estilo existentes (títulos ##, listas, tabelas). Não apagues conteúdo do autor
      sem eu pedir explicitamente; se achares que algo está errado, propõe a alteração primeiro.
    - Escreve sempre uma "note" curta a dizer o que mudaste e porquê, e "author" com o teu nome
      (ex.: "Claude", "ChatGPT"): ficam no histórico.
    - Depois de escrever, diz-me o que mudaste e a nova "version".
    - Não partilhes a chave nem a incluas no documento.

    Exemplo (curl)
    curl -s -H "Authorization: Bearer {$key}" {$api}
    curl -s -X POST {$api}/append -H "Authorization: Bearer {$key}" -H "Content-Type: application/json" \
      -d '{"content": "- Nova ideia", "section": "## Ideias", "note": "Acrescentei uma ideia", "author": "Claude"}'
    TXT;
}

function doc_openapi(): array
{
    $json = fn(array $schema) => ['content' => ['application/json' => ['schema' => $schema]]];
    $ref = fn(string $n) => ['$ref' => "#/components/schemas/$n"];
    $err = ['description' => 'Erro', ...$json($ref('Error'))];
    $note = ['type' => 'string', 'description' => 'Nota curta sobre a alteração (fica no histórico)'];
    $author = ['type' => 'string', 'description' => 'Nome do assistente (ex.: Claude), mostrado no histórico'];
    $base = ['type' => 'integer', 'description' => 'A "version" lida antes de alterar. Se o documento mudou entretanto, a resposta é 409.'];
    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'Documento Markdown partilhado',
            'version' => '1.0.0',
            'description' => 'Ler e contribuir para um documento Markdown. Cada documento tem a sua chave: Authorization: Bearer <chave>.',
        ],
        'servers' => [['url' => abs_url('/api/doc')]],
        'security' => [['bearerAuth' => []]],
        'paths' => [
            '/' => [
                'get' => ['operationId' => 'getDocument', 'summary' => 'Ler o documento (título, versão, índice de títulos e texto Markdown)',
                    'parameters' => [['name' => 'format', 'in' => 'query', 'description' => '"md" devolve só o Markdown (text/markdown)', 'schema' => ['type' => 'string', 'enum' => ['json', 'md']]]],
                    'responses' => ['200' => ['description' => 'OK', ...$json($ref('Document'))], '401' => $err]],
                'patch' => ['operationId' => 'editDocument', 'summary' => 'Trocar excertos exatos do texto (cada "find" tem de aparecer uma só vez)',
                    'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['edits'], 'properties' => [
                        'base_version' => $base,
                        'edits' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['find', 'replace'], 'properties' => [
                            'find' => ['type' => 'string', 'description' => 'Texto exato que existe no documento'],
                            'replace' => ['type' => 'string', 'description' => 'Texto novo ("" apaga)'],
                        ]]],
                        'note' => $note,
                        'author' => $author,
                    ]])],
                    'responses' => ['200' => ['description' => 'Guardado', ...$json($ref('Saved'))], '409' => $err, '422' => $err, '403' => $err]],
                'put' => ['operationId' => 'replaceDocument', 'summary' => 'Substituir o texto todo (usar só para reorganizar)',
                    'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['base_version', 'content'], 'properties' => [
                        'base_version' => $base,
                        'content' => ['type' => 'string', 'description' => 'Documento completo em Markdown'],
                        'title' => ['type' => 'string'],
                        'note' => $note,
                        'author' => $author,
                    ]])],
                    'responses' => ['200' => ['description' => 'Guardado', ...$json($ref('Saved'))], '409' => $err, '422' => $err, '403' => $err]],
            ],
            '/append' => [
                'post' => ['operationId' => 'appendToDocument', 'summary' => 'Acrescentar texto no fim do documento ou no fim de uma secção',
                    'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['content'], 'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Texto em Markdown'],
                        'section' => ['type' => 'string', 'description' => 'Linha do título da secção, ex.: "## Ideias" (opcional)'],
                        'base_version' => $base,
                        'note' => $note,
                        'author' => $author,
                    ]])],
                    'responses' => ['200' => ['description' => 'Guardado', ...$json($ref('Saved'))], '409' => $err, '422' => $err, '403' => $err]],
            ],
            '/history' => [
                'get' => ['operationId' => 'listVersions', 'summary' => 'Últimas versões do documento',
                    'responses' => ['200' => ['description' => 'OK', ...$json(['type' => 'object'])]]],
            ],
            '/history/{version}' => [
                'get' => ['operationId' => 'getVersion', 'summary' => 'Texto de uma versão anterior',
                    'parameters' => [['name' => 'version', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'OK', ...$json(['type' => 'object'])], '404' => $err]],
            ],
        ],
        'components' => [
            'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            'schemas' => [
                'Saved' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'version' => ['type' => 'integer'],
                    'outline' => ['type' => 'array', 'items' => ['type' => 'object']], 'message' => ['type' => 'string'],
                ]],
                'Document' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'description' => 'Enviar como base_version ao alterar'],
                    'access' => ['type' => 'string', 'enum' => ['write', 'read']],
                    'instructions' => ['type' => 'string', 'description' => 'Notas do autor para o assistente'],
                    'updated_at' => ['type' => 'string'],
                    'updated_by' => ['type' => 'string'],
                    'chars' => ['type' => 'integer'],
                    'outline' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'level' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'line' => ['type' => 'integer'],
                    ]]],
                    'content' => ['type' => 'string', 'description' => 'Texto em Markdown'],
                ]],
                'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => [
                    'message' => ['type' => 'string'],
                    'details' => ['type' => 'object'],
                ]]]],
            ],
        ],
    ];
}
