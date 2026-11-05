<?php
// Copie este ficheiro para config.php e ajuste os valores.
return [
    // URL público do site, sem barra final. Usado em canonical, sitemap, robots.txt, Open Graph e RSS.
    // Pode ser sobreposto pela variável de ambiente SITE_URL (útil em desenvolvimento).
    'base_url' => 'https://fbnsantos.com',

    // Redireciona (301) http:// e www./outros domínios para o base_url, para o Google ver um só endereço.
    // Desligue se estiver a testar o site noutro domínio.
    'canonical_redirect' => true,

    // Chave pedida em /admin/setup para criar o primeiro utilizador.
    // Troque por um valor longo e aleatório antes de publicar o site.
    'setup_key' => 'mude-isto',

    // Caminho da base de dados SQLite (deve ficar protegida pelo .htaccess).
    'db_path' => __DIR__ . '/data/site.sqlite',

    'timezone' => 'Europe/Lisbon',

    // Tamanho máximo de cada upload, em MB.
    'max_upload_mb' => 10,

    // Deploy automático (git.php). Vazio = desativado.
    // Gere um valor aleatório longo, por exemplo: php -r 'echo bin2hex(random_bytes(24));'
    'deploy_secret' => '',
    'deploy_branch' => 'main',
    // Pasta pessoal no servidor, se o git se queixar de HOME (ex.: '/home2/utilizador'). Normalmente vazio.
    'deploy_home' => '',
];
