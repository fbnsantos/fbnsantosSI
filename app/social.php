<?php
/**
 * Destaques: vídeos do YouTube, publicações do LinkedIn e do Instagram, e outros links,
 * mostrados em /highlights e na página inicial.
 *
 * - Adiciona-se colando o URL no backoffice; o título, texto e imagem são lidos da página (Open Graph)
 *   ou, no YouTube, do oEmbed oficial. Se a rede não os devolver, preenchem-se à mão.
 * - As imagens são copiadas para uploads/: o site não carrega nada do LinkedIn/Instagram/YouTube
 *   (privacidade, e os links de imagem do Instagram expiram ao fim de dias).
 * - O canal do YouTube é importado automaticamente pelo feed RSS público (sem chave de API).
 */

const HIGHLIGHT_KINDS = ['youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'instagram' => 'Instagram', 'link' => 'Link'];
const HTTP_USER_AGENT = 'Mozilla/5.0 (compatible; fbnsantos.com link preview; +https://fbnsantos.com)';
const YOUTUBE_SYNC_EVERY = 6 * 3600;

/* ---------- HTTP ---------- */

/**
 * Pedido HTTP simples (cURL se existir, senão streams).
 * Devolve ['status' => int, 'headers' => [nome em minúsculas => valor], 'body' => string].
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15, int $maxBytes = 3_000_000): array
{
    $result = ['status' => 0, 'headers' => [], 'body' => ''];
    $headers[] = 'User-Agent: ' . HTTP_USER_AGENT;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $buffer = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$result) {
                if (preg_match('/^HTTP\/\S+\s+\d+/', $line)) {
                    $result['headers'] = []; // novo pedido depois de um redirecionamento
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $result['headers'][strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, $maxBytes) {
                $buffer .= $chunk;
                return strlen($buffer) > $maxBytes ? 0 : strlen($chunk); // 0 interrompe
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_exec($ch);
        $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $result['body'] = $buffer;
        curl_close($ch);
        return $result;
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 1,
    ]]);
    $data = @file_get_contents($url, false, $ctx, 0, $maxBytes);
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
            $result['status'] = (int) $m[1];
            $result['headers'] = [];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $result['headers'][strtolower(trim($k))] = trim($v);
        }
    }
    $result['body'] = $data === false ? '' : $data;
    return $result;
}

/** Só URLs http(s) para servidores públicos (evita usar o servidor para espreitar a rede interna). */
function public_http_url(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
        return false;
    }
    $host = trim($p['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function setting_set(string $name, string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')
        ->execute([$name, $value]);
    $cache = &settings_cache();
    if ($cache !== null) {
        $cache[$name] = $value;
    }
}

/* ---------- Destaques ---------- */

function highlight_kind(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return match (true) {
        youtube_id($url) !== null => 'youtube',
        (bool) preg_match('/(^|\.)linkedin\.com$|^lnkd\.in$/', $host) => 'linkedin',
        (bool) preg_match('/(^|\.)instagram\.com$|^instagr\.am$/', $host) => 'instagram',
        default => 'link',
    };
}

/** URL na forma canónica (os vídeos do YouTube ficam sempre watch?v=ID, para não haver duplicados). */
function highlight_normalize_url(string $url): string
{
    $url = trim($url);
    if ($id = youtube_id($url)) {
        return 'https://www.youtube.com/watch?v=' . $id;
    }
    // Tira parâmetros de rastreio (utm_*, LinkedIn "rcm", Instagram "igsh").
    $p = parse_url($url);
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        $q = array_filter($q, fn($k) => !preg_match('/^(utm_|rcm$|igsh$|igshid$|trk|trackingId$)/i', (string) $k), ARRAY_FILTER_USE_KEY);
        $url = strtok($url, '?') . ($q ? '?' . http_build_query($q) : '');
    }
    return $url;
}

/**
 * Lê título, descrição, imagem e data de um URL.
 * Devolve ['title', 'description', 'image' (URL remoto), 'date' (Y-m-d H:i:s ou '')].
 */
function highlight_fetch_meta(string $url): array
{
    $meta = ['title' => '', 'description' => '', 'image' => '', 'date' => '', 'site' => ''];
    if ($id = youtube_id($url)) {
        $r = http_request('GET', 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url), [], null, 10);
        $o = json_decode($r['body'], true);
        if ($r['status'] === 200 && is_array($o)) {
            $meta['title'] = (string) ($o['title'] ?? '');
            $meta['description'] = (string) ($o['author_name'] ?? '');
            $meta['site'] = 'YouTube';
        }
        $meta['image'] = "https://i.ytimg.com/vi/$id/hqdefault.jpg";
        return $meta;
    }
    if (!public_http_url($url)) {
        return $meta;
    }
    $r = http_request('GET', $url, ['Accept: text/html', 'Accept-Language: en,pt;q=0.8'], null, 12, 1_500_000);
    if ($r['status'] !== 200 || $r['body'] === '') {
        return $meta;
    }
    $html = $r['body'];
    $tags = [];
    if (preg_match_all('/<meta\s[^>]*>/i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('/(?:property|name|itemprop)\s*=\s*["\']([^"\']+)["\']/i', $tag, $k)
                && preg_match('/content\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $v)) {
                $key = strtolower($k[1]);
                $tags[$key] ??= html_entity_decode($v[2] !== '' ? $v[2] : ($v[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }
    $title = $tags['og:title'] ?? $tags['twitter:title'] ?? '';
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $t)) {
        $title = html_entity_decode(trim($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $meta['title'] = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    $meta['site'] = trim($tags['og:site_name'] ?? $tags['application-name'] ?? '');
    $meta['description'] = trim(preg_replace('/\s+/u', ' ', $tags['og:description'] ?? $tags['description'] ?? $tags['twitter:description'] ?? '') ?? '');
    $image = $tags['og:image'] ?? $tags['og:image:url'] ?? $tags['twitter:image'] ?? '';
    if ($image !== '' && str_starts_with($image, '//')) {
        $image = 'https:' . $image;
    }
    $meta['image'] = preg_match('#^https?://#i', $image) ? $image : '';
    $date = $tags['article:published_time'] ?? $tags['og:published_time'] ?? $tags['datepublished'] ?? $tags['uploaddate'] ?? '';
    if ($date !== '' && ($ts = strtotime($date))) {
        $meta['date'] = date('Y-m-d H:i:s', $ts);
    }
    return $meta;
}

/** Copia uma imagem remota para uploads/. Devolve o caminho local ou '' se falhar. */
function highlight_store_image(string $remote, string $alt): string
{
    if ($remote === '' || !public_http_url($remote)) {
        return '';
    }
    $r = http_request('GET', $remote, ['Accept: image/*'], null, 15, 8_000_000);
    if ($r['status'] !== 200 || strlen($r['body']) < 200) {
        return '';
    }
    $tmp = ROOT . '/data/img-' . bin2hex(random_bytes(6));
    file_put_contents($tmp, $r['body']);
    try {
        $name = slugify($alt) ?: 'destaque';
        return handle_upload(['name' => $name . '.jpg', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $alt, false)['url'];
    } catch (RuntimeException) {
        return '';
    } finally {
        @unlink($tmp);
    }
}

/** Itens visíveis, destacados primeiro e depois os mais recentes. */
function highlights_list(int $limit = 60, string $kind = ''): array
{
    $sql = 'SELECT * FROM highlights WHERE visible = 1' . ($kind !== '' ? ' AND kind = ?' : '')
        . ' ORDER BY featured DESC, item_date DESC LIMIT ' . $limit;
    $st = db()->prepare($sql);
    $st->execute($kind !== '' ? [$kind] : []);
    return $st->fetchAll();
}

function has_highlights(): bool
{
    static $has = null;
    return $has ??= (bool) db()->query('SELECT 1 FROM highlights WHERE visible = 1 LIMIT 1')->fetchColumn();
}

function highlight_cta(string $kind): string
{
    return match ($kind) {
        'youtube' => t('Ver no YouTube'),
        'linkedin' => t('Ver no LinkedIn'),
        'instagram' => t('Ver no Instagram'),
        default => t('Abrir'),
    };
}

/* ---------- YouTube: importação automática do canal ---------- */

/** ID do canal (UC…) a partir do URL nas Definições (/channel/UC…, /@nome) ou do ID guardado. */
function youtube_channel_id(): string
{
    $saved = setting('youtube_channel_id');
    if (preg_match('/^UC[\w-]{22}$/', $saved)) {
        return $saved;
    }
    $url = setting('social_youtube');
    if (preg_match('#/channel/(UC[\w-]{22})#', $url, $m)) {
        return $m[1];
    }
    return '';
}

/** Descobre o ID do canal a partir de um URL do tipo youtube.com/@nome. */
function youtube_resolve_channel(string $input): string
{
    $input = trim($input);
    if (preg_match('/(UC[\w-]{22})/', $input, $m)) {
        return $m[1];
    }
    if (preg_match('#^@?([\w.-]{3,})$#', $input, $m)) {
        $input = 'https://www.youtube.com/@' . $m[1];
    }
    if (!preg_match('#^https://(www\.|m\.)?youtube\.com/#i', $input)) {
        return '';
    }
    $r = http_request('GET', $input, ['Accept-Language: en'], null, 12, 2_000_000);
    foreach (['/"externalId":"(UC[\w-]{22})"/', '/"channelId":"(UC[\w-]{22})"/', '#youtube\.com/channel/(UC[\w-]{22})#'] as $re) {
        if (preg_match($re, $r['body'], $m)) {
            return $m[1];
        }
    }
    return '';
}

/**
 * Importa os vídeos novos do canal (o feed traz os 15 mais recentes).
 * Sem $force só corre de 6 em 6 horas. Devolve o número de vídeos novos ou a mensagem de erro.
 */
function youtube_sync(bool $force = false): int|string
{
    $channel = youtube_channel_id();
    if ($channel === '') {
        return 'Indique o canal do YouTube em Redes sociais.';
    }
    if (!$force && (int) setting('youtube_sync_at') > time() - YOUTUBE_SYNC_EVERY) {
        return 0;
    }
    setting_set('youtube_sync_at', (string) time()); // antes do pedido: evita importações em paralelo
    $r = http_request('GET', 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel, [], null, 12);
    if ($r['status'] !== 200 || !str_contains($r['body'], '<feed')) {
        return 'O YouTube não devolveu o feed do canal (' . $r['status'] . ').';
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($r['body']);
    libxml_use_internal_errors($prev);
    if (!$xml) {
        return 'Feed do YouTube inválido.';
    }
    $visible = setting('youtube_auto_visible', '1') === '1' ? 1 : 0;
    $exists = db()->prepare('SELECT 1 FROM highlights WHERE url = ?');
    $insert = db()->prepare("INSERT INTO highlights (kind, url, title, description, image, item_date, visible, source, created_at)
        VALUES ('youtube', ?, ?, ?, ?, ?, ?, 'youtube', ?)");
    $new = 0;
    foreach ($xml->entry as $entry) {
        $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $media = $entry->children('http://search.yahoo.com/mrss/');
        $id = (string) $yt->videoId;
        if (!preg_match('/^[\w-]{11}$/', $id)) {
            continue;
        }
        $url = 'https://www.youtube.com/watch?v=' . $id;
        $exists->execute([$url]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $title = (string) $entry->title;
        $description = text_excerpt(nl2br(e((string) $media->group->description)), 280);
        $ts = strtotime((string) $entry->published) ?: time();
        $image = highlight_store_image("https://i.ytimg.com/vi/$id/hqdefault.jpg", $title);
        $insert->execute([$url, $title, $description, $image, date('Y-m-d H:i:s', $ts), $visible, now()]);
        $new++;
    }
    return $new;
}

/** Corre a importação do YouTube depois de a página já ter sido enviada ao visitante (sem o fazer esperar). */
function youtube_sync_in_background(): void
{
    if (youtube_channel_id() === '' || (int) setting('youtube_sync_at') > time() - YOUTUBE_SYNC_EVERY) {
        return;
    }
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try {
            youtube_sync();
        } catch (Throwable $e) {
            error_log('YouTube: ' . $e->getMessage());
        }
    });
}
