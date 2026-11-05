<?php
/*
 * Deploy automático: põe o servidor igual ao ramo do GitHub (git fetch + reset).
 *
 * Duas formas de o chamar (o segredo está em config.php → deploy_secret, nunca no git):
 *   1. Webhook do GitHub (recomendado): Settings → Webhooks → Payload URL https://fbnsantos.com/git.php,
 *      Content type application/json, Secret = deploy_secret, evento "push".
 *   2. À mão no browser: https://fbnsantos.com/git.php?token=<deploy_secret>
 *   3. No backoffice: menu "Atualizar" (/admin/update), sem precisar do segredo.
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

require ROOT . '/app/deploy.php';
$result = deploy_run($config, $event);
if (!$result['ok']) {
    http_response_code(500);
}
echo $result['output'], $result['ok'] ? "Deploy concluído.\n" : "Deploy falhou.\n";
