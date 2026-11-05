<?php
declare(strict_types=1);

/*
 * Limpa o HTML vindo do editor: só passam tags e atributos seguros,
 * iframes só do YouTube (convertidos para youtube-nocookie) e um link
 * do YouTube sozinho num parágrafo vira vídeo incorporado.
 */

const SAFE_TAGS = [
    'p' => ['style'], 'br' => [], 'hr' => [],
    'h2' => ['style', 'id'], 'h3' => ['style', 'id'], 'h4' => ['style', 'id'],
    'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
    'sub' => [], 'sup' => [], 'mark' => [], 'small' => [], 'span' => ['style'],
    'a' => ['href', 'title', 'target', 'rel'],
    'img' => ['src', 'alt', 'title', 'width', 'height', 'style'],
    'figure' => ['class'], 'figcaption' => [],
    'ul' => ['class'], 'ol' => ['start'], 'li' => [],
    'blockquote' => [], 'pre' => [], 'code' => [],
    'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
    'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
    'div' => ['class'],
];

const DROP_TAGS = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea',
    'select', 'noscript', 'template', 'svg', 'math', 'link', 'meta', 'base', 'head', 'title'];

const SAFE_CLASSES = ['video', 'image', 'highlights', 'align-left', 'align-center', 'align-right'];

function sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = (new DOMXPath($doc))->query('//div[@id="__root"]')->item(0);
    if (!$root instanceof DOMElement) {
        return '';
    }
    sanitize_children($root, $doc);
    autoembed_youtube($root, $doc);
    lift_videos_out_of_paragraphs($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

function sanitize_children(DOMNode $parent, DOMDocument $doc): void
{
    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node instanceof DOMElement) {
            sanitize_element($node, $doc);
        } elseif (!$node instanceof DOMText) {
            $parent->removeChild($node); // comentários, PIs, etc.
        }
    }
}

function sanitize_element(DOMElement $el, DOMDocument $doc): void
{
    $tag = strtolower($el->tagName);
    $parent = $el->parentNode;

    if (in_array($tag, DROP_TAGS, true)) {
        $parent->removeChild($el);
        return;
    }

    // Vídeos: iframe solto ou já embrulhado em <div class="video">.
    if ($tag === 'iframe' || ($tag === 'div' && str_contains(' ' . $el->getAttribute('class') . ' ', ' video '))) {
        $iframe = $tag === 'iframe' ? $el : $el->getElementsByTagName('iframe')->item(0);
        $id = $iframe ? youtube_id($iframe->getAttribute('src')) : null;
        if ($id) {
            $parent->replaceChild(youtube_embed_node($doc, $id), $el);
        } else {
            $parent->removeChild($el);
        }
        return;
    }

    sanitize_children($el, $doc);

    if ($tag === 'h1') {
        $el = rename_element($el, 'h2', $doc);
        $tag = 'h2';
    }
    if (!isset(SAFE_TAGS[$tag])) {
        // Tag desconhecida: mantém o conteúdo, remove a tag.
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
        return;
    }

    foreach (iterator_to_array($el->attributes) as $attr) {
        $name = strtolower($attr->name);
        $value = trim($attr->value);
        if (!in_array($name, SAFE_TAGS[$tag], true)) {
            $el->removeAttribute($attr->name);
            continue;
        }
        $keep = match ($name) {
            'href' => safe_url($value, true),
            'src' => safe_url($value, false),
            'style' => safe_style($value),
            'class' => implode(' ', array_intersect(preg_split('/\s+/', $value) ?: [], SAFE_CLASSES)),
            'target' => $value === '_blank' ? '_blank' : null,
            'width', 'height', 'colspan', 'rowspan', 'start' => ctype_digit($value) ? $value : null,
            default => $value,
        };
        if ($keep === null || $keep === '') {
            $el->removeAttribute($attr->name);
        } elseif ($keep !== $value) {
            $el->setAttribute($attr->name, $keep);
        }
    }

    if ($tag === 'a') {
        $href = $el->getAttribute('href');
        if ($el->getAttribute('target') === '_blank' || preg_match('#^https?://#i', $href) && !str_starts_with($href, base_url())) {
            $el->setAttribute('rel', 'noopener');
        }
    }
    if ($tag === 'img') {
        if (!$el->hasAttribute('src')) {
            $parent->removeChild($el);
            return;
        }
        if (!$el->hasAttribute('alt')) {
            $el->setAttribute('alt', '');
        }
        // width/height reservam o espaço da imagem antes de carregar (evita CLS).
        if (!$el->hasAttribute('width') && ($size = local_image_size($el->getAttribute('src')))) {
            $el->setAttribute('width', (string) $size[0]);
            $el->setAttribute('height', (string) $size[1]);
        }
        $el->setAttribute('loading', 'lazy');
        $el->setAttribute('decoding', 'async');
    }
}

function rename_element(DOMElement $el, string $newTag, DOMDocument $doc): DOMElement
{
    $new = $doc->createElement($newTag);
    foreach (iterator_to_array($el->attributes) as $attr) {
        $new->setAttribute($attr->name, $attr->value);
    }
    while ($el->firstChild) {
        $new->appendChild($el->firstChild);
    }
    $el->parentNode->replaceChild($new, $el);
    return $new;
}

function safe_url(string $url, bool $isLink): ?string
{
    if ($url === '') {
        return null;
    }
    if (preg_match('#^(https?://|/(?!/)|\#)#i', $url)) {
        return $url;
    }
    if ($isLink && preg_match('#^(mailto:|tel:)#i', $url)) {
        return $url;
    }
    return null;
}

/** [largura, altura] de uma imagem alojada no site (/uploads/…, /assets/…). */
function local_image_size(string $src): ?array
{
    if (!preg_match('#^/(uploads|assets)/[^?\#]+$#', $src) || str_contains($src, '..')) {
        return null;
    }
    $info = @getimagesize(ROOT . rawurldecode($src));
    return $info ? [$info[0], $info[1]] : null;
}

function safe_style(string $style): ?string
{
    $keep = [];
    foreach (explode(';', $style) as $decl) {
        if (preg_match('/^\s*text-align\s*:\s*(left|right|center|justify)\s*$/i', $decl, $m)) {
            $keep[] = 'text-align: ' . strtolower($m[1]);
        }
    }
    return $keep ? implode('; ', $keep) : null;
}

function youtube_id(string $url): ?string
{
    $url = trim(html_entity_decode($url));
    if (preg_match('~^(?:https?:)?//(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
        return $m[1];
    }
    if (preg_match('~^(?:https?:)?//(?:www\.|m\.)?youtube\.com/watch\?~i', $url)) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if (isset($q['v']) && is_string($q['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $q['v'])) {
            return $q['v'];
        }
    }
    return null;
}

function youtube_embed_node(DOMDocument $doc, string $id): DOMElement
{
    $div = $doc->createElement('div');
    $div->setAttribute('class', 'video');
    $iframe = $doc->createElement('iframe');
    $iframe->setAttribute('src', 'https://www.youtube-nocookie.com/embed/' . $id);
    $iframe->setAttribute('title', 'YouTube');
    $iframe->setAttribute('loading', 'lazy');
    $iframe->setAttribute('allow', 'accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
    $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    $iframe->setAttribute('allowfullscreen', '');
    $div->appendChild($iframe);
    return $div;
}

/** Um parágrafo que só contém um link do YouTube passa a vídeo incorporado. */
function autoembed_youtube(DOMElement $root, DOMDocument $doc): void
{
    foreach (iterator_to_array($root->getElementsByTagName('p')) as $p) {
        $text = trim(str_replace("\u{00A0}", ' ', $p->textContent));
        if ($text === '' || preg_match('/\s/', $text) || $p->getElementsByTagName('img')->length) {
            continue;
        }
        $id = youtube_id($text);
        if ($id) {
            $p->parentNode->replaceChild(youtube_embed_node($doc, $id), $p);
        }
    }
}

/** O editor por vezes põe o vídeo dentro de <p>; um <div> lá dentro é HTML inválido. */
function lift_videos_out_of_paragraphs(DOMElement $root): void
{
    foreach (iterator_to_array($root->getElementsByTagName('div')) as $div) {
        $p = $div->parentNode;
        if ($p instanceof DOMElement && strtolower($p->tagName) === 'p') {
            $p->parentNode->insertBefore($div, $p);
            if (trim($p->textContent) === '' && !$p->getElementsByTagName('img')->length) {
                $p->parentNode->removeChild($p);
            }
        }
    }
}

/** Imagens e vídeos de um conteúdo, para sitemap e dados estruturados. */
function content_images(string $html): array
{
    preg_match_all('/<img[^>]+src="([^"]+)"/i', $html, $m);
    return array_values(array_unique(array_map(fn($u) => abs_url(html_entity_decode($u)), $m[1])));
}

function content_videos(string $html): array
{
    preg_match_all('#youtube-nocookie\.com/embed/([A-Za-z0-9_-]{11})#', $html, $m);
    return array_values(array_unique($m[1]));
}
