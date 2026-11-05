<?php
declare(strict_types=1);

/*
 * Imagem de partilha (Open Graph) 1200×630 gerada com o título de cada página,
 * usada quando a página não tem imagem de capa. Fica em cache em data/og/.
 * O URL é assinado para ninguém gerar imagens com texto arbitrário no domínio.
 */

const OG_W = 1200;
const OG_H = 630;

function og_available(): bool
{
    return function_exists('imagettftext') && is_file(ROOT . '/app/fonts/Fraunces-SemiBold.ttf');
}

function og_secret(): string
{
    $secret = setting('og_secret');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        db()->prepare('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)')->execute(['og_secret', $secret]);
    }
    return $secret;
}

/** Inclui nome, frase e foto na assinatura, para a imagem mudar quando as definições mudam. */
function og_signature(string $title): string
{
    $state = implode('|', [$title, setting('site_name'), setting('author_tagline'), setting('author_image'), setting('site_tagline')]);
    return substr(hash_hmac('sha256', $state, og_secret()), 0, 20);
}

function og_image_url(string $title): string
{
    $title = mb_substr(trim($title), 0, 140);
    return '/og.png?' . http_build_query(['t' => $title, 's' => og_signature($title)]);
}

function serve_og_image(): void
{
    $title = (string) ($_GET['t'] ?? '');
    if (!og_available() || !hash_equals(og_signature($title), (string) ($_GET['s'] ?? ''))) {
        http_response_code(404);
        return;
    }
    $dir = ROOT . '/data/og';
    $file = $dir . '/' . og_signature($title) . '.png';
    if (!is_file($file)) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        imagepng(render_og_image($title), $file, 6);
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

function render_og_image(string $title): GdImage
{
    $serif = ROOT . '/app/fonts/Fraunces-SemiBold.ttf';
    $sans = ROOT . '/app/fonts/Inter-Medium.ttf';
    $im = imagecreatetruecolor(OG_W, OG_H);
    imageantialias($im, true);
    $hex = fn(string $h) => imagecolorallocate($im, hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2)));
    $bg = $hex('#fcf8f5');
    $tint = $hex('#f5e4da');
    $brick = $hex('#b5532f');
    $dark = $hex('#2b1f1a');
    $muted = $hex('#7b6a62');

    imagefilledrectangle($im, 0, 0, OG_W, OG_H, $bg);
    // Padrão de tijolos discreto.
    for ($y = 0, $row = 0; $y < OG_H; $y += 32, $row++) {
        imageline($im, 0, $y, OG_W, $y, $tint);
        for ($x = ($row % 2) * 32; $x < OG_W; $x += 64) {
            imageline($im, $x, $y, $x, $y + 32, $tint);
        }
    }
    imagefilledrectangle($im, 0, OG_H - 18, OG_W, OG_H, $brick);

    // Foto do autor à direita, com sombra cor de tijolo.
    $textRight = OG_W - 80;
    $photo = setting('author_image');
    if ($photo && ($src = load_local_image($photo))) {
        $size = 260;
        $cx = OG_W - 80 - $size;
        $cy = (int) ((OG_H - $size) / 2) - 10;
        imagefilledrectangle($im, $cx + 16, $cy + 16, $cx + $size + 16, $cy + $size + 16, $brick);
        $square = min(imagesx($src), imagesy($src));
        imagecopyresampled($im, $src, $cx, $cy, (int) ((imagesx($src) - $square) / 2), (int) ((imagesy($src) - $square) / 2), $size, $size, $square, $square);
        $textRight = $cx - 60;
    }

    // Nome do site no topo.
    imagefilledrectangle($im, 80, 74, 104, 98, $brick);
    imagettftext($im, 24, 0, 120, 97, $muted, $sans, setting('site_name'));

    // Título com quebras de linha (até 4 linhas).
    $size = mb_strlen($title) > 60 ? 50 : 62;
    $lines = wrap_ttf($title, $serif, $size, $textRight - 80);
    if (count($lines) > 4) {
        $lines = array_slice($lines, 0, 4);
        $lines[3] = rtrim($lines[3], ' .,;:') . '…';
    }
    $lineH = (int) ($size * 1.3);
    $y = (int) (OG_H / 2 - (count($lines) * $lineH) / 2 + $size * 0.9);
    foreach ($lines as $line) {
        imagettftext($im, $size, 0, 80, $y, $dark, $serif, $line);
        $y += $lineH;
    }

    // Frase e domínio em baixo.
    $tagline = setting('site_tagline');
    if ($tagline !== '') {
        foreach (array_slice(wrap_ttf($tagline, $sans, 22, $textRight - 80), 0, 1) as $line) {
            imagettftext($im, 22, 0, 80, OG_H - 96, $brick, $sans, $line);
        }
    }
    imagettftext($im, 20, 0, 80, OG_H - 56, $muted, $sans, (string) parse_url(base_url(), PHP_URL_HOST));
    return $im;
}

function wrap_ttf(string $text, string $font, int $size, int $maxWidth): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
        $try = $line === '' ? $word : "$line $word";
        $box = imagettfbbox($size, 0, $font, $try);
        if ($line !== '' && $box[2] - $box[0] > $maxWidth) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

function load_local_image(string $src): ?GdImage
{
    if (!local_image_size($src)) {
        return null;
    }
    $data = @file_get_contents(ROOT . rawurldecode($src));
    $im = $data ? @imagecreatefromstring($data) : false;
    return $im ?: null;
}
