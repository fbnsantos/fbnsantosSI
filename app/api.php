<?php
/*
 * API REST para publicar a partir de outras ferramentas (ChatGPT, Claude, scripts…).
 *
 *   GET  /api                     informação (sem autenticação)
 *   GET  /api/openapi.json        especificação OpenAPI 3.1 (sem autenticação)
 *   GET  /api/site                dados do site e etiquetas existentes
 *   GET|POST            /api/posts        listar / criar artigos
 *   GET|PATCH|DELETE    /api/posts/{id}   ler / alterar / apagar um artigo (POST também altera)
 *   GET|POST            /api/pages        idem para páginas
 *   GET|PATCH|DELETE    /api/pages/{id}
 *   GET|POST            /api/media        listar / enviar imagens (multipart "file" ou JSON base64)
 *
 * Autenticação: "Authorization: Bearer <chave>" (ou "X-API-Key: <chave>").
 * As chaves criam-se no backoffice em "API / IA"; guarda-se só o hash SHA-256.
 * Os dados passam pelas mesmas validações e pelo mesmo filtro de HTML do backoffice.
 */
declare(strict_types=1);

require_once ROOT . '/app/admin.php';

const API_FIELDS = [
    'pages' => ['title', 'slug', 'meta_description', 'content', 'cover_image', 'published', 'noindex', 'in_menu', 'menu_order'],
    'posts' => ['title', 'slug', 'meta_description', 'excerpt', 'content', 'cover_image', 'tags', 'published', 'noindex', 'published_at'],
];

function route_api(string $sub, string $method): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $sub = '/' . trim($sub, '/');
    $m = [];

    try {
        if ($sub === '/' && $method === 'GET') {
            api_send(api_index());
        }
        if ($sub === '/openapi.json' && $method === 'GET') {
            api_send(api_openapi());
        }

        api_auth();

        if ($sub === '/site' && $method === 'GET') {
            api_send(api_site());
        }
        if (preg_match('#^/(pages|posts)$#', $sub, $m)) {
            match ($method) {
                'GET' => api_list($m[1]),
                'POST' => api_save($m[1], null),
                default => api_error(405, 'Método não permitido.'),
            };
        }
        if (preg_match('#^/(pages|posts)/(\d+)$#', $sub, $m)) {
            $id = (int) $m[2];
            match ($method) {
                'GET' => api_send(api_out($m[1], api_find_or_404($m[1], $id), true)),
                'PATCH', 'PUT', 'POST' => api_save($m[1], $id),
                'DELETE' => api_delete($m[1], $id),
                default => api_error(405, 'Método não permitido.'),
            };
        }
        if ($sub === '/media') {
            match ($method) {
                'GET' => api_media_list(),
                'POST' => api_media_upload(),
                default => api_error(405, 'Método não permitido.'),
            };
        }
        api_error(404, 'Endpoint não encontrado. Veja ' . abs_url('/api/openapi.json'));
    } catch (Throwable $e) {
        error_log('API: ' . $e);
        api_error(500, 'Erro interno do servidor.');
    }
}

/* ---------- Respostas ---------- */

function api_send(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(int $code, string $message, array $details = []): never
{
    api_send(['error' => ['message' => $message] + ($details ? ['details' => $details] : [])], $code);
}

/* ---------- Autenticação ---------- */

function api_token_from_request(): string
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $h = (string) $v;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)\s*$/i', $h, $m)) {
        return $m[1];
    }
    return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function api_auth(): array
{
    if (too_many_attempts()) {
        api_error(429, 'Demasiadas tentativas falhadas. Tente de novo dentro de 15 minutos.');
    }
    $token = api_token_from_request();
    $row = false;
    if ($token !== '') {
        $st = db()->prepare('SELECT * FROM api_tokens WHERE token_hash = ?');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
    }
    if (!$row) {
        if ($token !== '') {
            record_failed_attempt();
        }
        header('WWW-Authenticate: Bearer');
        api_error(401, 'Chave da API em falta ou inválida. Envie o cabeçalho "Authorization: Bearer <chave>".');
    }
    db()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([now(), $row['id']]);
    return $row;
}

/** Cria uma chave nova; devolve a chave em claro (só é mostrada uma vez). */
function api_create_token(string $name): string
{
    $token = 'fbn_' . bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO api_tokens (name, token_hash, prefix, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$name, hash('sha256', $token), substr($token, 0, 10), now()]);
    return $token;
}

/* ---------- Conteúdos ---------- */

function api_body(): array
{
    $raw = (string) file_get_contents('php://input');
    if (trim($raw) === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error(400, 'O corpo do pedido tem de ser JSON válido (Content-Type: application/json).');
    }
    return $data;
}

function api_find(string $type, int $id): ?array
{
    $st = db()->prepare("SELECT * FROM $type WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function api_find_or_404(string $type, int $id): array
{
    return api_find($type, $id) ?? api_error(404, ($type === 'posts' ? 'Artigo' : 'Página') . " $id não existe.");
}

function api_status(string $type, array $row): string
{
    if (!$row['published']) {
        return 'draft';
    }
    return $type === 'posts' && $row['published_at'] > now() ? 'scheduled' : 'published';
}

/** Converte uma linha da base de dados no formato devolvido pela API. */
function api_out(string $type, array $row, bool $withContent = false): array
{
    $out = [
        'id' => (int) $row['id'],
        'type' => $type === 'posts' ? 'post' : 'page',
        'status' => api_status($type, $row),
        'title' => $row['title'],
        'slug' => $row['slug'],
        'url' => abs_url(public_url($type, $row)),
        'admin_url' => abs_url("/admin/$type/{$row['id']}"),
        'meta_description' => $row['meta_description'],
        'cover_image' => $row['cover_image'],
        'published' => (bool) $row['published'],
        'noindex' => (bool) $row['noindex'],
    ];
    if ($type === 'posts') {
        $out['excerpt'] = $row['excerpt'];
        $out['tags'] = array_values(array_filter(array_map('trim', explode(',', $row['tags']))));
        $out['published_at'] = $row['published_at'];
    } else {
        $out['in_menu'] = (bool) $row['in_menu'];
        $out['menu_order'] = (int) $row['menu_order'];
    }
    if ($withContent) {
        $out['content'] = $row['content'];
    }
    return $out + ['created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
}

function api_list(string $type): never
{
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $where = '1 = 1';
    $args = [];
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where .= ' AND title LIKE ?';
        $args[] = '%' . $q . '%';
    }
    $order = $type === 'posts' ? 'published_at DESC' : "slug <> 'home', menu_order, title";

    $st = db()->prepare("SELECT COUNT(*) FROM $type WHERE $where");
    $st->execute($args);
    $total = (int) $st->fetchColumn();

    $st = db()->prepare("SELECT * FROM $type WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset");
    $st->execute($args);
    $items = array_map(fn($r) => api_out($type, $r), $st->fetchAll());
    api_send(compact('items', 'total', 'limit', 'offset'));
}

/** Valida e normaliza um campo recebido. */
function api_value(string $field, mixed $v): mixed
{
    $bad = fn(string $why) => api_error(422, "Campo \"$field\" inválido: $why");
    switch ($field) {
        case 'published':
        case 'noindex':
        case 'in_menu':
            $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $b === null ? $bad('use true ou false.') : (int) $b;
        case 'menu_order':
            return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : $bad('use um número inteiro.');
        case 'tags':
            $list = is_array($v) ? $v : (is_string($v) ? explode(',', $v) : $bad('use uma lista de textos.'));
            return implode(', ', array_filter(array_map(fn($t) => trim((string) $t), $list), 'strlen'));
        case 'published_at':
            $ts = is_string($v) ? strtotime($v) : false;
            return $ts ? date('Y-m-d H:i:s', $ts) : $bad('use uma data, por exemplo 2026-11-05T10:00:00.');
        case 'content':
            return is_string($v) ? sanitize_html($v) : $bad('tem de ser HTML (texto).');
        default:
            return is_scalar($v) ? trim((string) $v) : $bad('tem de ser texto.');
    }
}

function api_save(string $type, ?int $id): never
{
    $in = api_body();
    $fields = array_flip(API_FIELDS[$type]);
    if ($id) {
        $existing = api_find_or_404($type, $id);
        $item = array_intersect_key($existing, $fields);
    } else {
        $existing = null;
        // Por omissão, o que chega pela API fica em rascunho até ser pedido "published": true.
        $item = array_intersect_key(empty_item($type), $fields);
        $item['published'] = 0;
    }
    foreach (API_FIELDS[$type] as $k) {
        if (array_key_exists($k, $in)) {
            $item[$k] = api_value($k, $in[$k]);
        }
    }
    if (!$id || array_key_exists('slug', $in)) {
        $item['slug'] = slugify($item['slug'] ?: $item['title']);
    }
    if ($existing && $existing['slug'] === 'home') {
        $item['slug'] = 'home'; // a página inicial está sempre em /
    }

    $errors = content_errors($type, $item, $id);
    if ($errors) {
        api_error(422, 'Dados inválidos.', $errors);
    }
    $newId = content_store($type, $item, $id);
    $out = api_out($type, api_find($type, $newId), true);
    $ignored = array_values(array_diff(array_keys($in), API_FIELDS[$type], ['id', 'type', 'csrf']));
    if ($ignored) {
        $out['ignored_fields'] = $ignored;
    }
    api_send($out, $id ? 200 : 201);
}

function api_delete(string $type, int $id): never
{
    $row = api_find_or_404($type, $id);
    if ($type === 'pages' && $row['slug'] === 'home') {
        api_error(422, 'A página inicial não pode ser apagada.');
    }
    db()->prepare("DELETE FROM $type WHERE id = ?")->execute([$id]);
    api_send(['deleted' => true, 'id' => $id]);
}

function api_site(): array
{
    $tags = [];
    foreach (db()->query("SELECT tags FROM posts WHERE tags <> ''")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        foreach (explode(',', $t) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
    }
    arsort($tags);
    return [
        'name' => setting('site_name'),
        'tagline' => setting('site_tagline'),
        'author' => setting('author_name'),
        'language' => setting('site_lang', 'pt-PT'),
        'url' => base_url(),
        'blog_url' => abs_url('/blog'),
        'posts' => (int) db()->query('SELECT COUNT(*) FROM posts')->fetchColumn(),
        'pages' => (int) db()->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
        'tags' => array_keys($tags),
    ];
}

/* ---------- Média ---------- */

function api_media_out(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'path' => $r['path'],
        'url' => abs_url($r['path']),
        'mime' => $r['mime'],
        'width' => $r['width'] !== null ? (int) $r['width'] : null,
        'height' => $r['height'] !== null ? (int) $r['height'] : null,
        'alt' => $r['alt'],
    ];
}

function api_media_list(): never
{
    $items = db()->query('SELECT * FROM media ORDER BY id DESC LIMIT 100')->fetchAll();
    api_send(['items' => array_map('api_media_out', $items)]);
}

function api_media_upload(): never
{
    try {
        if (!empty($_FILES['file'])) {
            $r = handle_upload($_FILES['file'], trim((string) ($_POST['alt'] ?? '')));
        } else {
            $in = api_body();
            $data = (string) ($in['data'] ?? '');
            $data = preg_replace('#^data:[^;,]*;base64,#', '', $data);
            $bin = base64_decode($data, true);
            if ($bin === false || $bin === '') {
                api_error(422, 'Envie "file" (multipart) ou JSON com "data" em base64 e "filename".');
            }
            $tmp = tempnam(ROOT . '/data', 'api');
            try {
                file_put_contents($tmp, $bin);
                $r = handle_upload([
                    'name' => (string) ($in['filename'] ?? 'imagem'),
                    'tmp_name' => $tmp,
                    'size' => strlen($bin),
                    'error' => UPLOAD_ERR_OK,
                ], trim((string) ($in['alt'] ?? '')), false);
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
        }
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    api_send(api_media_out(api_find('media', $r['id'])), 201);
}

/* ---------- Descrição ---------- */

function api_index(): array
{
    return [
        'name' => setting('site_name') . ' API',
        'version' => '1',
        'openapi' => abs_url('/api/openapi.json'),
        'auth' => 'Authorization: Bearer <chave> (criada no backoffice em API / IA)',
    ];
}

function api_openapi(): array
{
    $ref = fn(string $n) => ['$ref' => "#/components/schemas/$n"];
    $json = fn(array $schema) => ['content' => ['application/json' => ['schema' => $schema]]];
    $err = ['description' => 'Erro', ...$json($ref('Error'))];
    $idParam = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
    $listParams = [
        ['name' => 'q', 'in' => 'query', 'description' => 'Procurar no título', 'schema' => ['type' => 'string']],
        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
        ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 0]],
    ];

    $crud = function (string $type, string $one, string $many, string $Name) use ($ref, $json, $err, $idParam, $listParams): array {
        return [
            "/$type" => [
                'get' => ['operationId' => "list$Name" . 's', 'summary' => "Listar $many (sem o conteúdo HTML)",
                    'parameters' => $listParams,
                    'responses' => ['200' => ['description' => 'Lista', ...$json(['type' => 'object', 'properties' => [
                        'items' => ['type' => 'array', 'items' => $ref($Name)],
                        'total' => ['type' => 'integer'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer'],
                    ]])], '401' => $err]],
                'post' => ['operationId' => "create$Name", 'summary' => "Criar $one (fica em rascunho, a não ser que published seja true)",
                    'requestBody' => ['required' => true, ...$json($ref("{$Name}Input"))],
                    'responses' => ['201' => ['description' => 'Criado', ...$json($ref($Name))], '422' => $err, '401' => $err]],
            ],
            "/$type/{id}" => [
                'parameters' => [$idParam],
                'get' => ['operationId' => "get$Name", 'summary' => "Ler $one com o conteúdo HTML",
                    'responses' => ['200' => ['description' => 'OK', ...$json($ref($Name))], '404' => $err]],
                'patch' => ['operationId' => "update$Name", 'summary' => "Alterar $one: só os campos enviados mudam",
                    'requestBody' => ['required' => true, ...$json($ref("{$Name}Input"))],
                    'responses' => ['200' => ['description' => 'Atualizado', ...$json($ref($Name))], '422' => $err, '404' => $err]],
                'delete' => ['operationId' => "delete$Name", 'summary' => "Apagar $one (definitivo)",
                    'responses' => ['200' => ['description' => 'Apagado', ...$json(['type' => 'object', 'properties' => [
                        'deleted' => ['type' => 'boolean'], 'id' => ['type' => 'integer'],
                    ]])], '404' => $err]],
            ],
        ];
    };

    $common = [
        'title' => ['type' => 'string', 'description' => 'Título'],
        'slug' => ['type' => 'string', 'description' => 'Endereço (opcional; gerado a partir do título)'],
        'meta_description' => ['type' => 'string', 'description' => 'Descrição para o Google (até ~155 caracteres)'],
        'content' => ['type' => 'string', 'description' => 'Corpo em HTML: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, pre, code, img, figure, figcaption, table. Um link do YouTube sozinho num <p> vira vídeo. Sem scripts, estilos ou iframes.'],
        'cover_image' => ['type' => 'string', 'description' => 'Imagem de capa: URL https:// ou caminho devolvido por uploadMedia'],
        'published' => ['type' => 'boolean', 'description' => 'true = público. Por omissão os novos ficam em rascunho (false).'],
        'noindex' => ['type' => 'boolean', 'description' => 'true = esconder do Google'],
    ];
    $postInput = $common + [
        'excerpt' => ['type' => 'string', 'description' => 'Resumo curto mostrado na lista do blog'],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Etiquetas'],
        'published_at' => ['type' => 'string', 'description' => 'Data de publicação (ISO 8601). Uma data futura agenda o artigo.'],
    ];
    $pageInput = $common + [
        'in_menu' => ['type' => 'boolean', 'description' => 'Mostrar no menu do site'],
        'menu_order' => ['type' => 'integer', 'description' => 'Ordem no menu (menor primeiro)'],
    ];
    $meta = [
        'id' => ['type' => 'integer'],
        'type' => ['type' => 'string'],
        'status' => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'published']],
        'url' => ['type' => 'string', 'description' => 'Endereço público (rascunhos só são visíveis com sessão no backoffice)'],
        'admin_url' => ['type' => 'string', 'description' => 'Editar no backoffice'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ];

    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => setting('site_name') . ' API',
            'version' => '1.0.0',
            'description' => 'Criar e editar artigos e páginas de ' . base_url() . '. Autenticação: Authorization: Bearer <chave>.',
        ],
        'servers' => [['url' => abs_url('/api')]],
        'security' => [['bearerAuth' => []]],
        'paths' => [
            '/site' => ['get' => ['operationId' => 'getSite', 'summary' => 'Dados do site, idioma e etiquetas já usadas',
                'responses' => ['200' => ['description' => 'OK', ...$json(['type' => 'object'])], '401' => $err]]],
            ...$crud('posts', 'um artigo do blog', 'artigos do blog', 'Post'),
            ...$crud('pages', 'uma página', 'páginas', 'Page'),
            '/media' => [
                'get' => ['operationId' => 'listMedia', 'summary' => 'Listar imagens e ficheiros enviados',
                    'responses' => ['200' => ['description' => 'OK', ...$json(['type' => 'object', 'properties' => [
                        'items' => ['type' => 'array', 'items' => $ref('Media')],
                    ]])]]],
                'post' => ['operationId' => 'uploadMedia', 'summary' => 'Enviar imagem (JPG, PNG, GIF, WebP, AVIF) ou PDF em base64',
                    'requestBody' => ['required' => true, ...$json(['type' => 'object', 'required' => ['data', 'filename'], 'properties' => [
                        'data' => ['type' => 'string', 'description' => 'Conteúdo do ficheiro em base64'],
                        'filename' => ['type' => 'string', 'description' => 'Nome do ficheiro, ex.: foto.jpg'],
                        'alt' => ['type' => 'string', 'description' => 'Texto alternativo da imagem'],
                    ]])],
                    'responses' => ['201' => ['description' => 'Enviado', ...$json($ref('Media'))], '422' => $err]],
            ],
        ],
        'components' => [
            'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            'schemas' => [
                'PostInput' => ['type' => 'object', 'properties' => $postInput],
                'PageInput' => ['type' => 'object', 'properties' => $pageInput],
                'Post' => ['type' => 'object', 'properties' => $meta + $postInput],
                'Page' => ['type' => 'object', 'properties' => $meta + $pageInput],
                'Media' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'path' => ['type' => 'string'], 'url' => ['type' => 'string'],
                    'mime' => ['type' => 'string'], 'width' => ['type' => ['integer', 'null']],
                    'height' => ['type' => ['integer', 'null']], 'alt' => ['type' => 'string'],
                ]],
                'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => [
                    'message' => ['type' => 'string'],
                    'details' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]]]],
            ],
        ],
    ];
}
