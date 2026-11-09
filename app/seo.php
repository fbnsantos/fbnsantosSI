<?php
declare(strict_types=1);

/*
 * Tudo o que os motores de pesquisa leem é gerado aqui a partir da base de dados:
 * <head> (title, description, canonical, robots, Open Graph, Twitter, verificação),
 * dados estruturados JSON-LD, robots.txt, sitemap.xml (com imagens e vídeos) e RSS.
 */

function indexing_allowed(): bool
{
    return setting('allow_indexing', '1') === '1';
}

function social_links(): array
{
    $links = [];
    $map = [
        'social_linkedin' => 'LinkedIn', 'social_github' => 'GitHub', 'social_x' => 'X',
        'social_youtube' => 'YouTube', 'social_instagram' => 'Instagram',
        'social_scholar' => 'Google Scholar', 'social_orcid' => 'ORCID',
    ];
    foreach ($map as $key => $label) {
        $url = setting($key);
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $links[$label] = $url;
        }
    }
    return $links + link_lines(setting('profile_links'));
}

/** Linhas "Nome | https://…" → ['Nome' => url]. */
function link_lines(string $text): array
{
    $links = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        [$label, $url] = array_map('trim', explode('|', $line, 2)) + [1 => ''];
        if ($label !== '' && preg_match('#^https?://#i', $url)) {
            $links[$label] = $url;
        }
    }
    return $links;
}

/** Linhas de texto não vazias de uma definição. */
function setting_lines(string $name): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', setting($name)) ?: [])));
}

function person_schema(): array
{
    $p = [
        '@type' => 'Person',
        '@id' => abs_url('/') . '#person',
        'name' => setting('author_name', setting('site_name')),
        'url' => abs_url('/'),
    ];
    if (setting('author_image')) {
        $p['image'] = abs_url(setting('author_image'));
    }
    if (setting('author_job')) {
        $p['jobTitle'] = setting('author_job');
    }
    if (setting('site_description')) {
        $p['description'] = setting('site_description');
    }
    if (setting('contact_email')) {
        $p['email'] = 'mailto:' . setting('contact_email');
    }
    $orgs = fn(string $name) => array_map(fn($o) => ['@type' => 'Organization', 'name' => $o], setting_lines($name));
    if ($works = $orgs('author_works_for')) {
        $p['worksFor'] = $works;
    }
    if ($alumni = $orgs('author_alumni_of')) {
        $p['alumniOf'] = $alumni;
    }
    if ($topics = array_values(array_filter(array_map('trim', explode(',', setting('author_knows_about')))))) {
        $p['knowsAbout'] = $topics;
    }
    if ($same = array_values(social_links())) {
        $p['sameAs'] = $same;
    }
    if ($awards = activity_awards()) {
        $p['award'] = $awards;
    }
    return $p;
}

function website_schema(): array
{
    return [
        '@type' => 'WebSite',
        '@id' => abs_url('/') . '#website',
        'url' => abs_url('/'),
        'name' => setting('site_name'),
        'description' => setting('site_description'),
        'inLanguage' => setting('site_lang', 'pt-PT'),
        'publisher' => ['@id' => abs_url('/') . '#person'],
    ];
}

function breadcrumb_schema(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $path]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => abs_url($path)];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function video_schemas(string $html, string $name, string $description, string $uploadDate): array
{
    $out = [];
    foreach (content_videos($html) as $id) {
        $out[] = [
            '@type' => 'VideoObject',
            'name' => $name,
            'description' => $description ?: $name,
            'thumbnailUrl' => "https://i.ytimg.com/vi/$id/hqdefault.jpg",
            'embedUrl' => "https://www.youtube-nocookie.com/embed/$id",
            'contentUrl' => "https://www.youtube.com/watch?v=$id",
            'uploadDate' => $uploadDate,
        ];
    }
    return $out;
}

/**
 * $m: title, description, path, image, type (website|article|profile),
 *     published, modified, noindex, schema (lista de nós JSON-LD extra)
 */
function seo_head(array $m): string
{
    $site = setting('site_name', 'Filipe Santos');
    $title = trim($m['title'] ?? '');
    $fullTitle = $title !== '' && $title !== $site
        ? "$title — $site"
        : $site . (setting('site_tagline') ? ' — ' . setting('site_tagline') : '');
    $desc = trim($m['description'] ?? '') ?: setting('site_description');
    $url = abs_url($m['path'] ?? '/');
    // Imagem de partilha: a da página → a definida nas Definições → uma gerada com o título.
    $imagePath = ($m['image'] ?? '') ?: setting('default_image');
    if ($imagePath === '' && og_available()) {
        $imagePath = og_image_url($title !== '' ? $title : setting('author_name', $site));
        $imageSize = [OG_W, OG_H];
    }
    $imagePath = $imagePath ?: setting('author_image');
    $imageSize ??= local_image_size($imagePath);
    $image = abs_url($imagePath);
    $type = $m['type'] ?? 'website';
    $noindex = !empty($m['noindex']) || !indexing_allowed();
    $locale = str_replace('-', '_', setting('site_lang', 'pt-PT'));

    $h = [];
    $h[] = '<title>' . e($fullTitle) . '</title>';
    $h[] = '<meta name="description" content="' . e($desc) . '">';
    $h[] = '<link rel="canonical" href="' . e($url) . '">';
    $h[] = '<meta name="robots" content="' . ($noindex
        ? 'noindex, nofollow'
        : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1') . '">';
    $h[] = '<meta name="author" content="' . e(setting('author_name', $site)) . '">';

    $og = [
        'og:type' => $type === 'article' ? 'article' : ($type === 'profile' ? 'profile' : 'website'),
        'og:site_name' => $site,
        'og:locale' => $locale,
        'og:title' => $title ?: $fullTitle,
        'og:description' => $desc,
        'og:url' => $url,
    ];
    if ($image) {
        $og['og:image'] = $image;
        $og['og:image:alt'] = $title ?: $site;
        if ($imageSize) {
            $og['og:image:width'] = (string) $imageSize[0];
            $og['og:image:height'] = (string) $imageSize[1];
        }
    }
    if ($type === 'article') {
        $og['article:published_time'] = iso_date($m['published'] ?? null);
        $og['article:modified_time'] = iso_date($m['modified'] ?? null);
        $og['article:author'] = setting('author_name', $site);
    }
    foreach ($og as $k => $v) {
        if ($v !== '') {
            $h[] = '<meta property="' . $k . '" content="' . e($v) . '">';
        }
    }
    $h[] = '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '">';
    if (preg_match('#(?:x|twitter)\.com/([A-Za-z0-9_]+)#', setting('social_x'), $tw)) {
        $h[] = '<meta name="twitter:site" content="@' . e($tw[1]) . '">';
        $h[] = '<meta name="twitter:creator" content="@' . e($tw[1]) . '">';
    }

    if (setting('google_site_verification')) {
        $h[] = '<meta name="google-site-verification" content="' . e(setting('google_site_verification')) . '">';
    }
    if (setting('bing_site_verification')) {
        $h[] = '<meta name="msvalidate.01" content="' . e(setting('bing_site_verification')) . '">';
    }

    $h[] = '<link rel="alternate" type="application/rss+xml" title="' . e($site) . '" href="' . e(abs_url('/feed.xml')) . '">';
    $h[] = '<link rel="icon" href="/favicon.svg" type="image/svg+xml">';
    $h[] = '<meta name="theme-color" content="#b5532f">';

    $graph = array_merge([website_schema(), person_schema()], $m['schema'] ?? []);
    $h[] = '<script type="application/ld+json">' . json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) . '</script>';

    if (($ga = setting('google_analytics_id')) && preg_match('/^G-[A-Z0-9]+$/', $ga) && !$noindex) {
        $h[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . '"></script>';
        $h[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','" . e($ga) . "',{anonymize_ip:true});</script>";
    }

    return implode("\n    ", $h) . "\n";
}

function robots_txt(): string
{
    if (!indexing_allowed()) {
        return "# Indexação desativada no backoffice (Definições → Permitir indexação)\nUser-agent: *\nDisallow: /\n";
    }
    return "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api\n\nSitemap: " . abs_url('/sitemap.xml') . "\n";
}

function xml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sitemap_xml(): string
{
    $urls = [];
    $add = function (string $path, ?string $lastmod, string $html = '', string $cover = '', string $title = '', string $desc = '') use (&$urls) {
        $urls[] = compact('path', 'lastmod', 'html', 'cover', 'title', 'desc');
    };

    $home = db()->query("SELECT * FROM pages WHERE slug = 'home'")->fetch() ?: [];
    $latest = db()->query('SELECT MAX(updated_at) FROM posts WHERE ' . public_posts_where())->fetchColumn();
    $homeMod = max((string) ($home['updated_at'] ?? ''), (string) $latest) ?: null;
    $add('/', $homeMod, $home['content'] ?? '', setting('author_image'), setting('site_name'), setting('site_description'));

    $pages = db()->query("SELECT * FROM pages WHERE published = 1 AND noindex = 0 AND slug <> 'home' ORDER BY menu_order");
    foreach ($pages as $p) {
        $add('/' . $p['slug'], $p['updated_at'], $p['content'], $p['cover_image'], $p['title'], $p['meta_description']);
    }
    if ($latest) {
        $add('/blog', $latest);
    }
    if ($hl = db()->query('SELECT MAX(created_at) FROM highlights WHERE visible = 1')->fetchColumn()) {
        $add('/highlights', $hl);
    }
    if ($rs = db()->query('SELECT MAX(updated_at) FROM publications WHERE visible = 1')->fetchColumn()) {
        $add('/research', $rs);
    }
    if ($rs = db()->query('SELECT MAX(updated_at) FROM projects WHERE visible = 1')->fetchColumn()) {
        $add('/research/projects', $rs);
    }
    if ($ac = db()->query('SELECT MAX(updated_at) FROM activities WHERE visible = 1')->fetchColumn()) {
        $add('/activities', $ac);
    }
    $posts = db()->query('SELECT * FROM posts WHERE ' . public_posts_where() . ' AND noindex = 0 ORDER BY published_at DESC');
    foreach ($posts as $p) {
        $add('/blog/' . $p['slug'], $p['updated_at'], $p['content'], $p['cover_image'], $p['title'],
            $p['meta_description'] ?: $p['excerpt']);
    }

    $x = ['<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
        . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'
        . ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">'];
    foreach ($urls as $u) {
        $x[] = '  <url>';
        $x[] = '    <loc>' . xml(abs_url($u['path'])) . '</loc>';
        if ($u['lastmod']) {
            $x[] = '    <lastmod>' . xml(iso_date($u['lastmod'])) . '</lastmod>';
        }
        $images = content_images($u['html']);
        if ($u['cover']) {
            array_unshift($images, abs_url($u['cover']));
        }
        foreach (array_slice(array_unique($images), 0, 1000) as $img) {
            $x[] = '    <image:image><image:loc>' . xml($img) . '</image:loc></image:image>';
        }
        foreach (content_videos($u['html']) as $id) {
            $title = $u['title'] ?: setting('site_name');
            $x[] = '    <video:video>';
            $x[] = '      <video:thumbnail_loc>https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg</video:thumbnail_loc>';
            $x[] = '      <video:title>' . xml($title) . '</video:title>';
            $x[] = '      <video:description>' . xml(mb_substr($u['desc'] ?: text_excerpt($u['html'], 300) ?: $title, 0, 2048)) . '</video:description>';
            $x[] = '      <video:player_loc>https://www.youtube-nocookie.com/embed/' . $id . '</video:player_loc>';
            $x[] = '    </video:video>';
        }
        $x[] = '  </url>';
    }
    $x[] = '</urlset>';
    return implode("\n", $x) . "\n";
}

function feed_xml(): string
{
    $posts = db()->query('SELECT * FROM posts WHERE ' . public_posts_where() . ' ORDER BY published_at DESC LIMIT 20')->fetchAll();
    $x = ['<?xml version="1.0" encoding="UTF-8"?>',
        '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">',
        '<channel>',
        '  <title>' . xml(setting('site_name')) . '</title>',
        '  <link>' . xml(abs_url('/')) . '</link>',
        '  <description>' . xml(setting('site_description')) . '</description>',
        '  <language>' . xml(strtolower(setting('site_lang', 'pt-PT'))) . '</language>',
        '  <atom:link href="' . xml(abs_url('/feed.xml')) . '" rel="self" type="application/rss+xml"/>'];
    if ($posts) {
        $x[] = '  <lastBuildDate>' . date(DATE_RSS, strtotime($posts[0]['updated_at'])) . '</lastBuildDate>';
    }
    foreach ($posts as $p) {
        $url = abs_url('/blog/' . $p['slug']);
        // URLs relativos (/uploads/...) passam a absolutos para os leitores de RSS.
        $content = preg_replace('#(src|href)="/(?!/)#', '$1="' . base_url() . '/', $p['content']);
        $x[] = '  <item>';
        $x[] = '    <title>' . xml($p['title']) . '</title>';
        $x[] = '    <link>' . xml($url) . '</link>';
        $x[] = '    <guid isPermaLink="true">' . xml($url) . '</guid>';
        $x[] = '    <pubDate>' . date(DATE_RSS, strtotime($p['published_at'])) . '</pubDate>';
        $x[] = '    <description>' . xml($p['excerpt'] ?: text_excerpt($p['content'], 300)) . '</description>';
        $x[] = '    <content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $content) . ']]></content:encoded>';
        $x[] = '  </item>';
    }
    $x[] = '</channel>';
    $x[] = '</rss>';
    return implode("\n", $x) . "\n";
}

/** Favicon SVG gerado com as iniciais do nome do site. */
function favicon_svg(): string
{
    $words = preg_split('/\s+/', trim(setting('site_name', 'FS'))) ?: ['F'];
    $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
        . '<rect width="64" height="64" rx="14" fill="#b5532f"/>'
        . '<text x="32" y="42" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="28" font-weight="700" fill="#fff" text-anchor="middle">'
        . xml($initials) . '</text></svg>';
}

/* ---------- IndexNow: avisa Bing, Yandex, Seznam… quando um URL muda ---------- */

function indexnow_key(): string
{
    $key = setting('indexnow_key');
    if ($key === '') {
        $key = bin2hex(random_bytes(16));
        db()->prepare('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)')->execute(['indexnow_key', $key]);
    }
    return $key;
}

/** Envia os URLs alterados ao IndexNow. Só em produção; falhas são ignoradas. */
function indexnow_ping(array $paths): void
{
    $host = (string) parse_url(base_url(), PHP_URL_HOST);
    if (!indexing_allowed() || setting('indexnow_enabled', '1') !== '1'
        || $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return;
    }
    $key = indexnow_key();
    $body = json_encode([
        'host' => $host,
        'key' => $key,
        'keyLocation' => abs_url("/$key.txt"),
        'urlList' => array_values(array_unique(array_map('abs_url', $paths))),
    ]);
    @file_get_contents('https://api.indexnow.org/indexnow', false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json; charset=utf-8\r\n",
        'content' => $body,
        'timeout' => 4,
        'ignore_errors' => true,
    ]]));
}

/* ---------- llms.txt: resumo do site em Markdown para assistentes e motores de IA ---------- */

function llms_txt(): string
{
    $md = ['# ' . setting('site_name'), '', '> ' . setting('site_description'), ''];
    if ($roles = setting_lines('author_headline')) {
        foreach ($roles as $r) {
            $md[] = "- $r";
        }
        $md[] = '';
    }
    $md[] = '## Pages';
    $md[] = '';
    $md[] = '- [' . t('Início') . '](' . abs_url('/') . '): ' . setting('author_tagline', setting('site_description'));
    foreach (db()->query("SELECT * FROM pages WHERE published = 1 AND noindex = 0 AND slug <> 'home' ORDER BY menu_order") as $p) {
        $md[] = '- [' . $p['title'] . '](' . abs_url('/' . $p['slug']) . '): ' . ($p['meta_description'] ?: text_excerpt($p['content'], 140));
    }
    $posts = db()->query('SELECT * FROM posts WHERE ' . public_posts_where() . ' AND noindex = 0 ORDER BY published_at DESC LIMIT 50')->fetchAll();
    if ($posts) {
        $md[] = '';
        $md[] = '## ' . setting('blog_title', 'Blog');
        $md[] = '';
        foreach ($posts as $p) {
            $md[] = '- [' . $p['title'] . '](' . abs_url('/blog/' . $p['slug']) . '): ' . ($p['excerpt'] ?: text_excerpt($p['content'], 140));
        }
    }
    $pubs = db()->query('SELECT * FROM publications WHERE visible = 1 ORDER BY featured DESC, year DESC, id DESC LIMIT 40')->fetchAll();
    if ($pubs) {
        $md[] = '';
        $md[] = '## ' . t('Publicações') . ' ([' . t('todas') . '](' . abs_url('/research') . '))';
        $md[] = '';
        foreach ($pubs as $p) {
            $md[] = '- ' . ($p['year'] ? "{$p['year']}. " : '') . (pub_link($p) ? '[' . $p['title'] . '](' . pub_link($p) . ')' : $p['title'])
                . ($p['venue'] ? '. ' . $p['venue'] : '');
        }
    }
    $projects = db()->query("SELECT * FROM projects WHERE visible = 1 ORDER BY featured DESC, COALESCE(NULLIF(end_date, ''), '9999') DESC LIMIT 30")->fetchAll();
    if ($projects) {
        $md[] = '';
        $md[] = '## ' . t('Projetos') . ' ([' . t('todos') . '](' . abs_url('/research/projects') . '))';
        $md[] = '';
        foreach ($projects as $p) {
            $md[] = '- ' . $p['title'] . ($p['funder'] ? ' — ' . $p['funder'] : '') . (project_years($p) ? ' (' . project_years($p) . ')' : '');
        }
    }
    if ($acts = activities_list('', 40)) {
        $md[] = '';
        $md[] = '## ' . activities_title() . ' ([' . t('todos') . '](' . abs_url('/activities') . '))';
        $md[] = '';
        foreach ($acts as $a) {
            $line = '- ' . ($a['item_date'] !== '' ? substr($a['item_date'], 0, 4) . '. ' : '') . activity_kind_label($a['kind']) . ': '
                . ($a['url'] !== '' ? '[' . $a['title'] . '](' . $a['url'] . ')' : $a['title']);
            $md[] = $line . ($a['event'] !== '' ? ' — ' . $a['event'] : '');
        }
    }
    if ($links = social_links()) {
        $md[] = '';
        $md[] = '## Profiles';
        $md[] = '';
        foreach ($links as $label => $url) {
            $md[] = "- [$label]($url)";
        }
    }
    return implode("\n", $md) . "\n";
}
