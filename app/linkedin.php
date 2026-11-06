<?php
/**
 * Publicar artigos e páginas no perfil pessoal do LinkedIn (API oficial, produto "Share on LinkedIn").
 *
 * Configuração (uma vez): criar uma app em https://www.linkedin.com/developers/apps, juntar os produtos
 * "Share on LinkedIn" e "Sign In with LinkedIn using OpenID Connect", e indicar o URL de redirecionamento
 * /admin/linkedin/callback. O Client ID e o Client Secret colam-se em Backoffice → Redes sociais.
 *
 * O LinkedIn dá autorizações de 60 dias: depois disso é preciso clicar outra vez em "Ligar ao LinkedIn".
 * A API não permite ler as publicações do próprio perfil, por isso o caminho inverso (LinkedIn → site)
 * faz-se colando o link da publicação em Destaques.
 */

const LINKEDIN_SCOPES = 'openid profile w_member_social';

function linkedin_redirect_uri(): string
{
    return abs_url('/admin/linkedin/callback');
}

function linkedin_configured(): bool
{
    return setting('linkedin_client_id') !== '' && linkedin_secret() !== '';
}

function linkedin_connected(): bool
{
    return linkedin_token() !== '' && (int) setting('linkedin_expires') > time() && setting('linkedin_sub') !== '';
}

/* Segredo e token ficam cifrados na base de dados (mesma chave das chaves da API, derivada do setup_key). */
function linkedin_secret(): string
{
    require_once ROOT . '/app/api.php';
    return setting('linkedin_client_secret') !== '' ? (api_token_decrypt(setting('linkedin_client_secret')) ?? '') : '';
}

function linkedin_token(): string
{
    require_once ROOT . '/app/api.php';
    return setting('linkedin_token') !== '' ? (api_token_decrypt(setting('linkedin_token')) ?? '') : '';
}

function linkedin_save_app(string $clientId, string $secret): void
{
    require_once ROOT . '/app/api.php';
    setting_set('linkedin_client_id', trim($clientId));
    if (trim($secret) !== '') {
        setting_set('linkedin_client_secret', api_token_encrypt(trim($secret)));
    }
}

function linkedin_auth_url(): string
{
    session_boot();
    $_SESSION['linkedin_state'] = bin2hex(random_bytes(16));
    return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
        'response_type' => 'code',
        'client_id' => setting('linkedin_client_id'),
        'redirect_uri' => linkedin_redirect_uri(),
        'state' => $_SESSION['linkedin_state'],
        'scope' => LINKEDIN_SCOPES,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Recebe o regresso do LinkedIn. Devolve null se ligou, ou a mensagem de erro. */
function linkedin_callback(array $q): ?string
{
    session_boot();
    $state = $_SESSION['linkedin_state'] ?? '';
    unset($_SESSION['linkedin_state']);
    if (!empty($q['error'])) {
        return 'O LinkedIn recusou a ligação: ' . ($q['error_description'] ?? $q['error']);
    }
    if (!is_string($q['state'] ?? null) || $state === '' || !hash_equals($state, $q['state']) || !is_string($q['code'] ?? null)) {
        return 'Pedido inválido. Tente ligar outra vez.';
    }
    $r = http_request('POST', 'https://www.linkedin.com/oauth/v2/accessToken',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $q['code'],
            'redirect_uri' => linkedin_redirect_uri(),
            'client_id' => setting('linkedin_client_id'),
            'client_secret' => linkedin_secret(),
        ]));
    $tok = json_decode($r['body'], true);
    if ($r['status'] !== 200 || empty($tok['access_token'])) {
        return 'Não foi possível obter a autorização (' . $r['status'] . '): ' . ($tok['error_description'] ?? 'resposta inesperada');
    }
    $u = http_request('GET', 'https://api.linkedin.com/v2/userinfo', ['Authorization: Bearer ' . $tok['access_token']]);
    $me = json_decode($u['body'], true);
    if ($u['status'] !== 200 || empty($me['sub'])) {
        return 'Ligado, mas não foi possível ler o perfil (' . $u['status'] . '). Confirme que a app tem o produto "Sign In with LinkedIn using OpenID Connect".';
    }
    require_once ROOT . '/app/api.php';
    setting_set('linkedin_token', api_token_encrypt($tok['access_token']));
    setting_set('linkedin_expires', (string) (time() + (int) ($tok['expires_in'] ?? 0)));
    setting_set('linkedin_sub', (string) $me['sub']);
    setting_set('linkedin_name', (string) ($me['name'] ?? ''));
    return null;
}

function linkedin_disconnect(): void
{
    foreach (['linkedin_token', 'linkedin_expires', 'linkedin_sub', 'linkedin_name'] as $k) {
        setting_set($k, '');
    }
}

/** Pedido à API REST do LinkedIn (versões mensais AAAAMM; usa-se uma de há dois meses, sempre suportada). */
function linkedin_api(string $method, string $path, ?array $json = null): array
{
    return http_request($method, 'https://api.linkedin.com' . $path, [
        'Authorization: Bearer ' . linkedin_token(),
        'LinkedIn-Version: ' . date('Ym', strtotime('first day of -2 months')),
        'X-Restli-Protocol-Version: 2.0.0',
        'Content-Type: application/json',
    ], $json === null ? null : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 30);
}

/** Envia uma imagem para o LinkedIn e devolve o URN (urn:li:image:…) ou null. */
function linkedin_upload_image(string $bytes): ?string
{
    $r = linkedin_api('POST', '/rest/images?action=initializeUpload', [
        'initializeUploadRequest' => ['owner' => 'urn:li:person:' . setting('linkedin_sub')],
    ]);
    $v = json_decode($r['body'], true)['value'] ?? null;
    if (empty($v['uploadUrl']) || empty($v['image'])) {
        return null;
    }
    $up = http_request('PUT', $v['uploadUrl'], ['Authorization: Bearer ' . linkedin_token(), 'Content-Type: application/octet-stream'], $bytes, 60);
    return $up['status'] >= 200 && $up['status'] < 300 ? $v['image'] : null;
}

/** Imagem do cartão: capa, senão a primeira imagem do texto, senão a imagem de partilha gerada. */
function linkedin_card_image(array $row): ?string
{
    $src = $row['cover_image'] ?: (content_images($row['content'])[0] ?? '');
    if ($src !== '') {
        if (str_starts_with($src, '/uploads/') && is_file(ROOT . rawurldecode($src))) {
            return (string) file_get_contents(ROOT . rawurldecode($src));
        }
        $abs = str_starts_with($src, '/') ? abs_url($src) : $src;
        $r = http_request('GET', $abs, [], null, 15, 8_000_000);
        if ($r['status'] === 200) {
            return $r['body'];
        }
    }
    if (og_available()) {
        ob_start();
        imagepng(render_og_image($row['title']));
        return (string) ob_get_clean();
    }
    return null;
}

/**
 * O texto das publicações usa o formato "little text" do LinkedIn: alguns caracteres têm de ser escapados
 * e as hashtags escrevem-se {hashtag|\#|nome}.
 */
function linkedin_commentary(string $text): string
{
    $parts = preg_split('/(#[\p{L}\p{N}_]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $out = '';
    foreach ($parts as $i => $part) {
        $out .= $i % 2 === 1
            ? '{hashtag|\#|' . substr($part, 1) . '}'
            : preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $part);
    }
    return $out;
}

/** Texto sugerido para a publicação: título, resumo e hashtags a partir das etiquetas. */
function linkedin_default_text(string $type, array $row): string
{
    $summary = $row['excerpt'] ?? '';
    $summary = $summary ?: ($row['meta_description'] ?: text_excerpt($row['content'], 280));
    $tags = array_filter(array_map('trim', explode(',', $row['tags'] ?? '')));
    $hashtags = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^\p{L}\p{N}]+/u', '', ucwords($t)), $tags));
    return trim($row['title'] . "\n\n" . $summary . ($hashtags !== '' ? "\n\n" . $hashtags : ''));
}

/** Publica no LinkedIn um cartão com link para o artigo/página. Devolve [true, urn] ou [false, erro]. */
function linkedin_share(string $type, array $row, string $text): array
{
    if (!linkedin_connected()) {
        return [false, 'O LinkedIn não está ligado (ou a autorização de 60 dias expirou).'];
    }
    $article = [
        'source' => abs_url(public_url($type, $row)),
        'title' => mb_substr($row['title'], 0, 200),
        'description' => mb_substr($row['meta_description'] ?: ($row['excerpt'] ?? '') ?: text_excerpt($row['content'], 250), 0, 250),
    ];
    $bytes = linkedin_card_image($row);
    if ($bytes && ($urn = linkedin_upload_image($bytes))) {
        $article['thumbnail'] = $urn;
    }
    $r = linkedin_api('POST', '/rest/posts', [
        'author' => 'urn:li:person:' . setting('linkedin_sub'),
        'commentary' => linkedin_commentary($text),
        'visibility' => 'PUBLIC',
        'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
        'content' => ['article' => $article],
        'lifecycleState' => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
    ]);
    if ($r['status'] !== 201) {
        $msg = json_decode($r['body'], true)['message'] ?? substr($r['body'], 0, 200);
        if ($r['status'] === 401) {
            linkedin_disconnect();
            $msg = 'A autorização expirou ou foi revogada. Ligue outra vez ao LinkedIn.';
        }
        return [false, "Erro do LinkedIn ({$r['status']}): $msg"];
    }
    $urn = $r['headers']['x-restli-id'] ?? $r['headers']['x-linkedin-id'] ?? '';
    db()->prepare("INSERT INTO social_shares (content_type, content_id, network, external_id, created_at) VALUES (?, ?, 'linkedin', ?, ?)")
        ->execute([$type, (int) $row['id'], $urn, now()]);
    return [true, $urn];
}

function linkedin_post_url(string $urn): string
{
    return $urn !== '' ? 'https://www.linkedin.com/feed/update/' . $urn . '/' : 'https://www.linkedin.com/in/me/recent-activity/all/';
}

/** Partilha manual (sem API): abre o LinkedIn com o texto e o link já escritos. */
function linkedin_compose_url(string $text, string $url): string
{
    return 'https://www.linkedin.com/feed/?shareActive=true&text=' . rawurlencode($text . "\n\n" . $url);
}

function social_shares(string $type, int $id): array
{
    $st = db()->prepare('SELECT * FROM social_shares WHERE content_type = ? AND content_id = ? ORDER BY id DESC');
    $st->execute([$type, $id]);
    return $st->fetchAll();
}
