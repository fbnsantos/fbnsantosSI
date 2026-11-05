<?php
/*
 * Deploy automático: põe o servidor igual ao ramo do GitHub (git fetch + reset).
 *
 * Duas formas de o chamar (o segredo está em config.php → deploy_secret, nunca no git):
 *   1. Webhook do GitHub (recomendado): Settings → Webhooks → Payload URL https://fbnsantos.com/git.php,
 *      Content type application/json, Secret = deploy_secret, evento "push".
 *   2. À mão no browser: https://fbnsantos.com/git.php?token=<deploy_secret>
 *
 * Só toca em ficheiros do repositório: config.php, data/ e uploads/ não estão no git e ficam intactos.
 */
declare(strict_types=1);

define('ROOT', __DIR__);
$config = is_file(ROOT . '/config.php') ? require ROOT . '/config.php' : [];
$secret = (string) ($config['deploy_secret'] ?? '');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Sem segredo configurado, o deploy não existe.
if (strlen($secret) < 20) {
    http_response_code(404);
    exit;
}

$body = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($signature !== '') {
    $authorized = hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $signature);
} else {
    $token = $_GET['token'] ?? '';
    $authorized = is_string($token) && hash_equals($secret, $token);
}
if (!$authorized) {
    sleep(2); // trava tentativas de adivinhar o segredo
    http_response_code(403);
    exit("Forbidden\n");
}

$branch = (string) ($config['deploy_branch'] ?? 'main');
$event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'manual');
if ($event === 'ping') {
    exit("pong\n");
}
if ($event === 'push') {
    $ref = json_decode($body, true)['ref'] ?? '';
    if ($ref !== "refs/heads/$branch") {
        exit("Ignorado: push para $ref (só faço deploy de $branch)\n");
    }
}
if (!function_exists('shell_exec')) {
    http_response_code(500);
    exit("shell_exec está desativado neste servidor.\n");
}
if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
    http_response_code(500);
    exit("deploy_branch inválido.\n");
}

// Em alguns alojamentos o PHP corre sem HOME, e o git precisa dele.
if (!empty($config['deploy_home'])) {
    putenv('HOME=' . $config['deploy_home']);
} elseif (!getenv('HOME') && function_exists('posix_getpwuid')) {
    putenv('HOME=' . (posix_getpwuid(posix_geteuid())['dir'] ?? ROOT));
}

$git = 'git -c safe.directory=' . escapeshellarg(ROOT) . ' -C ' . escapeshellarg(ROOT);
$out = '';
foreach ([
    "$git fetch --prune origin " . escapeshellarg($branch),
    "$git reset --hard " . escapeshellarg("origin/$branch"),
    "$git log -1 --format='%h %s (%cr)'",
] as $cmd) {
    $out .= '$ ' . preg_replace('/^git .*? -C \S+ /', 'git ', $cmd) . "\n" . shell_exec("$cmd 2>&1") . "\n";
}

// Garante que o PHP usa o código novo de imediato.
if (function_exists('opcache_reset')) {
    opcache_reset();
}

@file_put_contents(ROOT . '/data/deploy.log', date('c') . " [$event]\n$out\n", FILE_APPEND | LOCK_EX);
echo $out, "Deploy concluído.\n";
