<?php
declare(strict_types=1);

const UPLOAD_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
    'application/pdf' => 'pdf',
];

const MAX_IMAGE_WIDTH = 2000;

/**
 * Guarda um ficheiro enviado em uploads/AAAA/MM e regista-o na tabela media.
 * Imagens muito grandes são reduzidas (se a extensão GD estiver disponível).
 */
function handle_upload(array $file, string $alt = '', bool $fromHttpUpload = true): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($file['error'] ?? null) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ficheiro demasiado grande para o servidor (upload_max_filesize).',
            UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro enviado.',
            default => 'Erro no envio do ficheiro.',
        });
    }
    $maxBytes = (int) config('max_upload_mb', 10) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('Ficheiro maior que ' . config('max_upload_mb', 10) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(UPLOAD_MIMES[$mime])) {
        throw new RuntimeException('Tipo de ficheiro não permitido (' . $mime . '). Use JPG, PNG, GIF, WebP, AVIF ou PDF.');
    }

    $dir = 'uploads/' . date('Y/m');
    $abs = ROOT . '/' . $dir;
    if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
        throw new RuntimeException('Não foi possível criar a pasta de uploads.');
    }
    $base = slugify(pathinfo((string) $file['name'], PATHINFO_FILENAME)) ?: 'ficheiro';
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . UPLOAD_MIMES[$mime];
    $target = "$abs/$name";

    // $fromHttpUpload = false: ficheiro temporário criado pelo próprio servidor (ex.: base64 recebido pela API).
    $moved = $fromHttpUpload ? move_uploaded_file($file['tmp_name'], $target) : rename($file['tmp_name'], $target);
    if (!$moved) {
        throw new RuntimeException('Não foi possível guardar o ficheiro.');
    }
    chmod($target, 0644);

    [$w, $h] = [null, null];
    if (str_starts_with($mime, 'image/')) {
        resize_if_needed($target, $mime);
        $info = @getimagesize($target);
        if ($info) {
            [$w, $h] = [$info[0], $info[1]];
        }
    }

    $path = "/$dir/$name";
    db()->prepare('INSERT INTO media (path, original_name, mime, size, width, height, alt, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$path, (string) $file['name'], $mime, filesize($target), $w, $h, $alt, now()]);

    return ['id' => (int) db()->lastInsertId(), 'url' => $path, 'mime' => $mime, 'width' => $w, 'height' => $h];
}

function resize_if_needed(string $file, string $mime): void
{
    if (!function_exists('imagecreatetruecolor') || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return;
    }
    $info = @getimagesize($file);
    if (!$info || $info[0] <= MAX_IMAGE_WIDTH) {
        return;
    }
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file),
        'image/png' => @imagecreatefrompng($file),
        'image/webp' => @imagecreatefromwebp($file),
    };
    if (!$src) {
        return;
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $src = apply_exif_orientation($src, $file);
    }
    $dst = imagescale($src, MAX_IMAGE_WIDTH);
    if (!$dst) {
        return;
    }
    if ($mime !== 'image/jpeg') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    match ($mime) {
        'image/jpeg' => imagejpeg($dst, $file, 85),
        'image/png' => imagepng($dst, $file, 8),
        'image/webp' => imagewebp($dst, $file, 85),
    };
}

function apply_exif_orientation(GdImage $img, string $file): GdImage
{
    $exif = @exif_read_data($file);
    $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => $img,
    };
    return $rotated ?: $img;
}

function delete_media(int $id): void
{
    $m = db()->prepare('SELECT path FROM media WHERE id = ?');
    $m->execute([$id]);
    $path = $m->fetchColumn();
    if (!$path) {
        return;
    }
    $file = realpath(ROOT . $path);
    $uploads = realpath(ROOT . '/uploads');
    if ($file && $uploads && str_starts_with($file, $uploads . DIRECTORY_SEPARATOR)) {
        unlink($file);
    }
    db()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
}
