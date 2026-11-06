<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$CONFIG = require (is_file(ROOT . '/config.php') ? ROOT . '/config.php' : ROOT . '/config.sample.php');
date_default_timezone_set($CONFIG['timezone'] ?? 'Europe/Lisbon');

// Erros vão para o log do servidor, nunca para o visitante (revelariam caminhos e código).
if (PHP_SAPI !== 'cli-server' && PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/sanitize.php';
require __DIR__ . '/media.php';
require __DIR__ . '/seo.php';
require __DIR__ . '/ogimage.php';
require __DIR__ . '/geoip.php';
require __DIR__ . '/stats.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}
