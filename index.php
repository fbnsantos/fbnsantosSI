<?php
declare(strict_types=1);

// Servidor de desenvolvimento (php -S): servir diretamente só os ficheiros estáticos públicos
// (assets/ e uploads/); app/, data/, .git, config.php… nunca são servidos.
if (PHP_SAPI === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $uri;
    if (preg_match('#^/(assets|uploads)/#', $uri) && !str_contains($uri, '..')
        && is_file($file) && !preg_match('/\.(php\d?|phtml|phar)$/i', $file)) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$qs = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';

// Um só endereço por página: esquema e domínio do base_url (https, com/sem www).
$canonical = parse_url(base_url());
if (config('canonical_redirect', true) && PHP_SAPI !== 'cli-server' && !empty($canonical['host'])) {
    $wrongHost = strcasecmp($_SERVER['HTTP_HOST'] ?? '', $canonical['host'] . (isset($canonical['port']) ? ':' . $canonical['port'] : '')) !== 0;
    $wrongScheme = ($canonical['scheme'] ?? '') === 'https' && !is_https();
    if ($wrongHost || $wrongScheme) {
        redirect(base_url() . ($_SERVER['REQUEST_URI'] ?? '/'), 301);
    }
}

// URLs canónicos em minúsculas e sem barra final.
// (o ltrim impede redirecionamentos abertos do tipo //outro-site ou /\outro-site)
$clean = '/' . ltrim(rtrim(strtolower($path), '/'), '/\\');
if ($clean !== $path && !str_starts_with($path, '/admin')) {
    redirect($clean . $qs, 301);
}

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require ROOT . '/app/admin.php';
    route_admin(substr($path, 6) ?: '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');
} else {
    require ROOT . '/app/site.php';
    route_site($path);
}
