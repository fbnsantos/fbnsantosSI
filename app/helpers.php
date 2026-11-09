<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function base_url(): string
{
    $url = getenv('SITE_URL') ?: (string) config('base_url', '');
    if ($url === '') {
        $url = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($url, '/');
}

/** Converte um caminho relativo (/blog/x) num URL absoluto. */
function abs_url(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

/** Cache das definições (por referência, para setting_set() o manter atualizado). */
function &settings_cache(): ?array
{
    static $cache = null;
    return $cache;
}

function settings(): array
{
    $cache = &settings_cache();
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = (string) $row['value'];
        }
    }
    return $cache;
}

function setting(string $name, string $default = ''): string
{
    $v = settings()[$name] ?? '';
    return $v !== '' ? $v : $default;
}

function render(string $view, array $vars = [], string $layout = 'layout'): void
{
    extract($vars);
    ob_start();
    require ROOT . "/app/views/$view.php";
    $content = ob_get_clean();
    require ROOT . "/app/views/$layout.php";
}

function slugify(string $s): string
{
    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];
    $s = strtr(mb_strtolower(trim($s), 'UTF-8'), $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return substr(trim($s, '-'), 0, 80);
}

/** Texto simples a partir de HTML, cortado numa fronteira de palavra. */
function text_excerpt(string $html, int $len = 160): string
{
    $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    $cut = mb_substr($text, 0, $len - 1);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $len * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " ,.;:-") . '…';
}

/** Português ou inglês, conforme o idioma do site (Definições → Idioma). */
function site_is_pt(): bool
{
    return str_starts_with(strtolower(setting('site_lang', 'pt-PT')), 'pt');
}

/** Textos fixos do site público (o backoffice é sempre em português). */
function t(string $key, mixed ...$args): string
{
    static $en = [
        'Início' => 'Home',
        'Saltar para o conteúdo' => 'Skip to content',
        'Últimos artigos' => 'Latest posts',
        'Ver todos os artigos →' => 'See all posts →',
        'Perfis' => 'Profiles',
        'Contactar' => 'Get in touch',
        '%d min de leitura' => '%d min read',
        'atualizado a' => 'updated',
        'Página %d de %d' => 'Page %d of %d',
        '— página %d' => '— page %d',
        '← Mais recentes' => '← Newer',
        'Mais antigos →' => 'Older →',
        'Ainda não há artigos publicados.' => 'No posts published yet.',
        'Artigos de %s.' => 'Posts by %s.',
        'Página não encontrada' => 'Page not found',
        'O endereço que procura não existe ou foi movido.' => 'The page you are looking for does not exist or has moved.',
        'Voltar ao início' => 'Back to home',
        'Pré-visualização — não publicado.' => 'Preview — not published.',
        'Destaques' => 'Highlights',
        'Vídeos, publicações e projetos de %s.' => 'Videos, posts and projects by %s.',
        'Ver todos os destaques →' => 'See all highlights →',
        'Ainda não há destaques.' => 'No highlights yet.',
        'Ver no YouTube' => 'Watch on YouTube',
        'Ver no LinkedIn' => 'View on LinkedIn',
        'Ver no Instagram' => 'View on Instagram',
        'Abrir' => 'Open',
        'Filtrar' => 'Filter',
        'Tudo' => 'All',
        'Outros' => 'Other',
        'Siga também:' => 'Follow me on:',
        'Partilhar:' => 'Share:',
        'Investigação' => 'Research',
        'Publicações' => 'Publications',
        'Projetos' => 'Projects',
        'Artigos em revista' => 'Journal articles',
        'Artigos em conferência' => 'Conference papers',
        'Capítulos de livro' => 'Book chapters',
        'Livros' => 'Books',
        'Teses' => 'Theses',
        'Patentes' => 'Patents',
        'Indexadas Scopus / WoS' => 'Indexed in Scopus / WoS',
        'Publicações em destaque' => 'Selected publications',
        'Pesquisar' => 'Search',
        'Pesquisar título, autor, revista…' => 'Search title, author, venue…',
        'Nenhum resultado.' => 'No results.',
        'Sem data' => 'Undated',
        'Em curso' => 'Ongoing',
        'Concluído' => 'Completed',
        'Financiamento:' => 'Funding:',
        'Fontes:' => 'Sources:',
        'todas' => 'all',
        'todos' => 'all',
        'Publicações científicas de %s: artigos em revista, conferências e capítulos de livro.' => 'Scientific publications by %s: journal articles, conference papers and book chapters.',
        'Projetos de investigação e desenvolvimento de %s.' => 'Research and development projects by %s.',
    ];
    $s = site_is_pt() ? $key : ($en[$key] ?? $key);
    return $args ? sprintf($s, ...$args) : $s;
}

function format_date(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $t = strtotime($datetime);
    if (!site_is_pt()) {
        return date('j F Y', $t);
    }
    $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
        'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return date('j', $t) . ' de ' . $months[(int) date('n', $t) - 1] . ' de ' . date('Y', $t);
}

function iso_date(?string $datetime): string
{
    return $datetime ? date('c', strtotime($datetime)) : '';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function reading_minutes(string $html): int
{
    $words = str_word_count(strip_tags($html));
    return max(1, (int) round($words / 220));
}

/* ---------- Sessão, autenticação e CSRF (só usados no backoffice) ---------- */

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('fbns_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Utilizador autenticado, sem abrir sessão para visitantes anónimos. */
function current_user_id(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['fbns_admin'])) {
        return null;
    }
    session_boot();
    return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Pedido inválido (CSRF). Volte atrás e recarregue a página.');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    session_boot();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
