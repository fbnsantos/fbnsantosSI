<?php
/*
 * Deploy: põe o servidor igual ao ramo do GitHub (git fetch + reset --hard).
 * Usado por git.php (webhook / token) e pelo backoffice (/admin/update).
 * Só toca em ficheiros do repositório: config.php, data/ e uploads/ não estão no git e ficam intactos.
 * Requer a constante ROOT definida.
 */
declare(strict_types=1);

/** Executa um comando git na pasta do projeto e devolve a saída (stdout + stderr). */
function deploy_git(string $args): string
{
    return (string) shell_exec('git -c safe.directory=' . escapeshellarg(ROOT) . ' -C ' . escapeshellarg(ROOT) . " $args 2>&1");
}

/** Prepara o ambiente para o git; devolve uma mensagem de erro ou null se estiver tudo bem. */
function deploy_prepare(array $config): ?string
{
    if (!function_exists('shell_exec')) {
        return "shell_exec está desativado neste servidor.\n";
    }
    if (!preg_match('/^[A-Za-z0-9._\/-]+$/', deploy_branch($config))) {
        return "deploy_branch inválido.\n";
    }
    // Em alguns alojamentos o PHP corre sem HOME, e o git precisa dele.
    if (!empty($config['deploy_home'])) {
        putenv('HOME=' . $config['deploy_home']);
    } elseif (!getenv('HOME') && function_exists('posix_getpwuid')) {
        putenv('HOME=' . (posix_getpwuid(posix_geteuid())['dir'] ?? ROOT));
    }
    return null;
}

function deploy_branch(array $config): string
{
    return (string) ($config['deploy_branch'] ?? 'main');
}

/** Versão atual (último commit) instalada no servidor. */
function deploy_current_version(array $config): string
{
    if (deploy_prepare($config) !== null) {
        return '';
    }
    return trim(deploy_git("log -1 --format='%h %s (%cr)'"));
}

/**
 * Faz o deploy. Devolve ['ok' => bool, 'output' => string].
 * $source identifica quem pediu (webhook, manual, backoffice…) para o registo.
 */
function deploy_run(array $config, string $source): array
{
    if ($error = deploy_prepare($config)) {
        return ['ok' => false, 'output' => $error];
    }
    $branch = deploy_branch($config);
    $out = '';
    $ok = true;
    foreach ([
        'fetch --prune origin ' . escapeshellarg($branch),
        'reset --hard ' . escapeshellarg("origin/$branch"),
        "log -1 --format='%h %s (%cr)'",
    ] as $args) {
        $result = deploy_git($args);
        $out .= "\$ git $args\n$result\n";
        if (preg_match('/^(fatal|error):/m', $result)) {
            $ok = false;
            break;
        }
    }

    // Garante que o PHP usa o código novo de imediato.
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    @file_put_contents(ROOT . '/data/deploy.log', date('c') . " [$source]\n$out\n", FILE_APPEND | LOCK_EX);
    return ['ok' => $ok, 'output' => $out];
}
