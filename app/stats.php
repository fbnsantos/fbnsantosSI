<?php
/*
 * Estatísticas de visitas, sem cookies nem serviços externos.
 *
 * - Cada visualização de página/artigo é registada na tabela page_views.
 * - Não se guarda o IP: o "visitante" é um hash diário (IP + navegador + segredo + dia), que permite contar
 *   visitantes únicos num dia mas não seguir ninguém entre dias.
 * - Da origem guarda-se só o domínio (ex.: www.google.com), nunca o URL completo.
 * - Robôs (Google, ChatGPT, Bing…) não contam como visitas: ficam em bot_hits, só com o total por dia.
 * - Visitas com sessão iniciada no backoffice não contam.
 * - Os registos com mais de 13 meses são apagados automaticamente.
 */
declare(strict_types=1);

const STATS_RETENTION_DAYS = 400;

/** Robôs conhecidos (nome mostrado => padrão no User-Agent). A ordem importa: o primeiro que corresponde ganha. */
const STATS_BOTS = [
    'Googlebot' => 'googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|storebot-google',
    'Bingbot' => 'bingbot|bingpreview|msnbot|adidxbot',
    'GPTBot / ChatGPT' => 'gptbot|chatgpt-user|oai-searchbot',
    'ClaudeBot' => 'claudebot|claude-user|claude-searchbot|anthropic-ai',
    'PerplexityBot' => 'perplexitybot|perplexity-user',
    'Applebot' => 'applebot',
    'DuckDuckBot' => 'duckduckbot|duckassistbot',
    'YandexBot' => 'yandex',
    'Baiduspider' => 'baiduspider',
    'Meta / Facebook' => 'facebookexternalhit|facebookbot|meta-externalagent|meta-externalfetcher',
    'LinkedInBot' => 'linkedinbot',
    'Twitter / X' => 'twitterbot',
    'Ahrefs' => 'ahrefs',
    'Semrush' => 'semrush',
    'Common Crawl' => 'ccbot',
    'Bytespider' => 'bytespider',
    'Amazonbot' => 'amazonbot',
    'Outros robôs' => 'bot|crawl|spider|slurp|scrap|preview|monitor|uptime|pingdom|lighthouse|headless|phantom|curl|wget|python|httpclient|okhttp|java/|go-http|libwww|axios|node-fetch|fetch|scan|check|whatsapp|telegram|discord|skype|slack|embedly|validator',
];

/** Origens conhecidas: domínio (ou parte final do domínio) => [nome, canal]. */
const STATS_SOURCES = [
    'chatgpt.com' => ['ChatGPT', 'ai'], 'chat.openai.com' => ['ChatGPT', 'ai'],
    'claude.ai' => ['Claude', 'ai'], 'perplexity.ai' => ['Perplexity', 'ai'],
    'gemini.google.com' => ['Gemini', 'ai'], 'copilot.microsoft.com' => ['Copilot', 'ai'],
    'scholar.google' => ['Google Scholar', 'search'],
    'google' => ['Google', 'search'], 'bing.com' => ['Bing', 'search'], 'duckduckgo.com' => ['DuckDuckGo', 'search'],
    'yahoo.com' => ['Yahoo', 'search'], 'ecosia.org' => ['Ecosia', 'search'], 'search.brave.com' => ['Brave Search', 'search'],
    'yandex' => ['Yandex', 'search'], 'baidu.com' => ['Baidu', 'search'], 'startpage.com' => ['Startpage', 'search'],
    'qwant.com' => ['Qwant', 'search'],
    'linkedin.com' => ['LinkedIn', 'social'], 'lnkd.in' => ['LinkedIn', 'social'],
    'facebook.com' => ['Facebook', 'social'], 'fb.com' => ['Facebook', 'social'], 'instagram.com' => ['Instagram', 'social'],
    't.co' => ['X / Twitter', 'social'], 'x.com' => ['X / Twitter', 'social'], 'twitter.com' => ['X / Twitter', 'social'],
    'youtube.com' => ['YouTube', 'social'], 'reddit.com' => ['Reddit', 'social'], 'bsky.app' => ['Bluesky', 'social'],
    'threads.net' => ['Threads', 'social'], 'mastodon' => ['Mastodon', 'social'],
    'github.com' => ['GitHub', 'referral'], 'researchgate.net' => ['ResearchGate', 'referral'],
    'orcid.org' => ['ORCID', 'referral'], 'inesctec.pt' => ['INESC TEC', 'referral'], 'up.pt' => ['Universidade do Porto', 'referral'],
];

const STATS_CHANNELS = [
    'direct' => 'Direto (sem origem)',
    'search' => 'Motores de pesquisa',
    'ai' => 'Assistentes de IA',
    'social' => 'Redes sociais',
    'email' => 'Email / newsletter',
    'referral' => 'Outros sites',
];

/**
 * Regista uma visualização. Chamado pelas páginas públicas antes de as mostrar.
 * Nunca deixa um erro nas estatísticas estragar a página.
 */
function track_view(string $type, ?int $id = null): void
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || PHP_SAPI === 'cli') {
            return;
        }
        // Pré-carregamentos do navegador não são visitas.
        $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ($_SERVER['HTTP_PURPOSE'] ?? '') . ($_SERVER['HTTP_X_MOZ'] ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return;
        }
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $bot = stats_bot_name($ua);
        $day = date('Y-m-d');
        if ($bot !== null) {
            db()->prepare('INSERT INTO bot_hits (day, bot, hits) VALUES (?, ?, 1)
                ON CONFLICT(day, bot) DO UPDATE SET hits = hits + 1')->execute([$day, $bot]);
            return;
        }
        if (current_user_id()) {
            return; // o próprio autor não conta
        }

        [$refHost, $source, $channel] = stats_origin();
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $visitor = substr(hash('sha256', $day . '|' . config('setup_key', '') . '|' . client_ip() . '|' . $ua), 0, 16);

        db()->prepare('INSERT INTO page_views (created_at, day, path, content_type, content_id, visitor,
                referrer_host, source, channel, country, language, device, os, browser)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                now(), $day, mb_substr($path, 0, 300), $type, $id, $visitor,
                $refHost, $source, $channel, stats_country(), stats_language(),
                stats_device($ua), stats_os($ua), stats_browser($ua),
            ]);

        if (random_int(1, 200) === 1) {
            $limit = date('Y-m-d', strtotime('-' . STATS_RETENTION_DAYS . ' days'));
            db()->prepare('DELETE FROM page_views WHERE day < ?')->execute([$limit]);
            db()->prepare('DELETE FROM bot_hits WHERE day < ?')->execute([$limit]);
        }
    } catch (Throwable $e) {
        error_log('Estatísticas: ' . $e->getMessage());
    }
}

function stats_bot_name(string $ua): ?string
{
    if (trim($ua) === '') {
        return 'Outros robôs';
    }
    foreach (STATS_BOTS as $name => $pattern) {
        if (preg_match('#' . $pattern . '#i', $ua)) {
            return $name;
        }
    }
    return null;
}

/** Devolve [domínio de origem, nome da origem, canal]. */
function stats_origin(): array
{
    $param = fn(string $k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
    $utm = $param('utm_source') ?: $param('ref');
    $medium = strtolower($param('utm_medium'));
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string) (parse_url($ref, PHP_URL_HOST) ?: ''));
    $host = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $host);
    $own = strtolower((string) (parse_url(base_url(), PHP_URL_HOST) ?: ''));
    $own = preg_replace('/^www\./', '', $own);

    if ($host !== '' && ($host === $own || $host === ($_SERVER['HTTP_HOST'] ?? ''))) {
        return [$host, '', 'internal']; // navegação dentro do site
    }

    // utm_source (ex.: o ChatGPT acrescenta ?utm_source=chatgpt.com) tem prioridade sobre o Referer.
    $known = stats_known_source($utm !== '' ? strtolower($utm) : $host);
    if ($medium === 'email' || $medium === 'newsletter') {
        return [$host, $utm !== '' ? mb_substr($utm, 0, 80) : ($known[0] ?? $host), 'email'];
    }
    if ($known) {
        return [$host, $known[0], $known[1]];
    }
    if ($utm !== '') {
        return [$host, mb_substr($utm, 0, 80), 'referral'];
    }
    if ($host === '') {
        return ['', '', 'direct'];
    }
    return [mb_substr($host, 0, 120), $host, 'referral'];
}

function stats_known_source(string $hostOrName): ?array
{
    if ($hostOrName === '') {
        return null;
    }
    // Corresponde a partes inteiras do domínio: "google" apanha google.com e google.pt; "up.pt" apanha sigarra.up.pt;
    // "t.co" não apanha t.com.
    foreach (STATS_SOURCES as $needle => $info) {
        if (preg_match('#(^|\.)' . preg_quote($needle, '#') . '(\.|$)#', $hostOrName)) {
            return $info;
        }
    }
    return null;
}

function stats_country(): string
{
    // Disponível se o site estiver atrás da Cloudflare ou se o alojamento tiver GeoIP.
    $c = (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '');
    return preg_match('/^[A-Z]{2}$/', strtoupper($c)) && !in_array(strtoupper($c), ['XX', 'T1'], true) ? strtoupper($c) : '';
}

function stats_language(): string
{
    $al = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    return preg_match('/^\s*([a-z]{2,3})/i', $al, $m) ? strtolower($m[1]) : '';
}

function stats_device(string $ua): string
{
    if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
        return 'Tablet';
    }
    return preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua) ? 'Telemóvel' : 'Computador';
}

function stats_os(string $ua): string
{
    return match (true) {
        (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
        stripos($ua, 'Android') !== false => 'Android',
        stripos($ua, 'Windows') !== false => 'Windows',
        stripos($ua, 'CrOS') !== false => 'ChromeOS',
        (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
        stripos($ua, 'Linux') !== false => 'Linux',
        default => 'Outro',
    };
}

function stats_browser(string $ua): string
{
    return match (true) {
        stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgiOS') !== false => 'Edge',
        stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false => 'Opera',
        stripos($ua, 'SamsungBrowser') !== false => 'Samsung Internet',
        stripos($ua, 'Firefox') !== false || stripos($ua, 'FxiOS') !== false => 'Firefox',
        stripos($ua, 'Chrome') !== false || stripos($ua, 'CriOS') !== false => 'Chrome',
        stripos($ua, 'Safari') !== false => 'Safari',
        default => 'Outro',
    };
}

/* ---------- Consultas para o backoffice ---------- */

/** Visualizações totais por conteúdo, para as listas de páginas e artigos: [id => n]. */
function stats_views_by_content(string $type): array
{
    $st = db()->prepare('SELECT content_id, COUNT(*) FROM page_views WHERE content_type = ? AND content_id IS NOT NULL GROUP BY content_id');
    $st->execute([$type]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Todos os números da página de estatísticas para os últimos $days dias (incluindo hoje). */
function stats_report(int $days): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $prevFrom = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
    $q = function (string $sql, array $args = []) {
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st;
    };
    $count = fn(string $a, string $b) => $q('SELECT COUNT(*) AS views, COUNT(DISTINCT day || visitor) AS visitors
        FROM page_views WHERE day >= ? AND day < ?', [$a, $b])->fetch();

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $now = $count($from, $tomorrow);
    $prev = $count($prevFrom, $from);
    $entries = (int) $q("SELECT COUNT(*) FROM page_views WHERE day >= ? AND channel <> 'internal'", [$from])->fetchColumn();

    // Série diária, com os dias sem visitas a zero.
    $byDay = [];
    foreach ($q('SELECT day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM page_views WHERE day >= ? GROUP BY day', [$from]) as $r) {
        $byDay[$r['day']] = $r;
    }
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $series[] = ['day' => $d, 'views' => (int) ($byDay[$d]['views'] ?? 0), 'visitors' => (int) ($byDay[$d]['visitors'] ?? 0)];
    }

    $group = fn(string $col, string $where = '', int $limit = 10) => $q("SELECT $col AS label, COUNT(*) AS views,
            COUNT(DISTINCT day || visitor) AS visitors
        FROM page_views WHERE day >= ? $where GROUP BY $col ORDER BY views DESC LIMIT $limit", [$from])->fetchAll();

    $pages = $q("SELECT v.path AS label, COUNT(*) AS views, COUNT(DISTINCT v.day || v.visitor) AS visitors,
            COALESCE(p.title, po.title) AS title
        FROM page_views v
        LEFT JOIN pages p ON v.content_type = 'page' AND p.id = v.content_id
        LEFT JOIN posts po ON v.content_type = 'post' AND po.id = v.content_id
        WHERE v.day >= ? AND v.content_type <> '404'
        GROUP BY v.path ORDER BY views DESC LIMIT 15", [$from])->fetchAll();

    $channels = [];
    foreach ($group('channel', "AND channel <> 'internal'", 20) as $r) {
        $channels[] = ['label' => STATS_CHANNELS[$r['label']] ?? $r['label']] + $r;
    }

    return [
        'days' => $days,
        'from' => $from,
        'views' => (int) $now['views'],
        'visitors' => (int) $now['visitors'],
        'prev_views' => (int) $prev['views'],
        'prev_visitors' => (int) $prev['visitors'],
        'entries' => $entries,
        'series' => $series,
        'pages' => $pages,
        'channels' => $channels,
        'sources' => $group("CASE WHEN source <> '' THEN source ELSE referrer_host END", "AND channel NOT IN ('internal', 'direct')", 15),
        'not_found' => $group('path', "AND content_type = '404'", 10),
        'countries' => $group("CASE WHEN country <> '' THEN country ELSE '?' END"),
        'languages' => $group("CASE WHEN language <> '' THEN language ELSE '?' END"),
        'devices' => $group('device'),
        'os' => $group('os'),
        'browsers' => $group('browser'),
        'bots' => $q('SELECT bot AS label, SUM(hits) AS views FROM bot_hits WHERE day >= ? GROUP BY bot ORDER BY views DESC', [$from])->fetchAll(),
    ];
}

function stats_views_since(string $day): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM page_views WHERE day >= ?');
    $st->execute([$day]);
    return (int) $st->fetchColumn();
}
