<?php
declare(strict_types=1);

const POSTS_PER_PAGE = 10;

function route_site(string $path): void
{
    switch ($path) {
        case '/robots.txt':
            header('Content-Type: text/plain; charset=utf-8');
            echo robots_txt();
            return;
        case '/sitemap.xml':
            header('Content-Type: application/xml; charset=utf-8');
            echo sitemap_xml();
            return;
        case '/feed.xml':
            header('Content-Type: application/rss+xml; charset=utf-8');
            echo feed_xml();
            return;
        case '/favicon.svg':
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=86400');
            echo favicon_svg();
            return;
        case '/llms.txt':
            header('Content-Type: text/plain; charset=utf-8');
            echo llms_txt();
            return;
        case '/og.png':
            serve_og_image();
            return;
        case '/favicon.ico':
            redirect('/favicon.svg', 301);
        case '/':
            show_home();
            return;
        case '/home':
        case '/index.php':
            // URLs do site antigo (index.php?page=contacts) → novos URLs, com 301 para manter o SEO.
            $old = slugify((string) ($_GET['page'] ?? ''));
            redirect($old !== '' && $old !== 'home' ? "/$old" : '/', 301);
        case '/blog':
            show_blog();
            return;
        case '/highlights':
            show_highlights();
            return;
        case '/destaques':
            redirect('/highlights', 301);
        case '/research':
            show_research('pubs');
            return;
        case '/research/projects':
            show_research('projects');
            return;
        case '/investigacao':
        case '/publications':
        case '/publicacoes':
            redirect('/research', 301);
        case '/projects':
        case '/projetos':
            redirect('/research/projects', 301);
        case '/activities':
            show_activities();
            return;
        case '/palestras':
        case '/premios':
        case '/imprensa':
            redirect('/activities', 301);
    }
    if (preg_match('#^/([a-f0-9]{32})\.txt$#', $path, $m) && hash_equals(indexnow_key(), $m[1])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $m[1];
        return;
    }
    if (preg_match('#^/blog/([a-z0-9-]+)$#', $path, $m)) {
        show_post($m[1]);
        return;
    }
    if (preg_match('#^/([a-z0-9-]+)$#', $path, $m)) {
        show_page($m[1]);
        return;
    }
    not_found();
}

function show_home(): void
{
    $page = db()->query("SELECT * FROM pages WHERE slug = 'home'")->fetch() ?: ['title' => '', 'content' => '', 'meta_description' => '', 'updated_at' => now()];
    $posts = db()->query('SELECT * FROM posts WHERE ' . public_posts_where() . ' ORDER BY published_at DESC LIMIT 6')->fetchAll();
    $meta = [
        'title' => '',
        'description' => $page['meta_description'] ?: setting('site_description'),
        'path' => '/',
        'type' => 'profile',
        'schema' => array_merge([[
            '@type' => 'ProfilePage',
            '@id' => abs_url('/') . '#webpage',
            'url' => abs_url('/'),
            'name' => setting('site_name'),
            'isPartOf' => ['@id' => abs_url('/') . '#website'],
            'mainEntity' => ['@id' => abs_url('/') . '#person'],
            'dateModified' => iso_date($page['updated_at']),
        ]], video_schemas($page['content'], setting('site_name'), setting('site_description'), iso_date($page['updated_at']))),
    ];
    $highlights = setting('highlights_home', '1') === '1' ? highlights_list(3) : [];
    $activities = setting('activities_home', '1') === '1' ? activities_home() : [];
    track_view('home', isset($page['id']) ? (int) $page['id'] : null);
    youtube_sync_in_background();
    render('home', compact('page', 'posts', 'meta', 'highlights', 'activities'));
}

function show_page(string $slug): void
{
    if ($slug === 'home') {
        redirect('/', 301);
    }
    $st = db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $st->execute([$slug]);
    $page = $st->fetch();
    $preview = $page && !$page['published'];
    if (!$page || ($preview && !current_user_id())) {
        not_found();
        return;
    }
    $description = $page['meta_description'] ?: text_excerpt($page['content']);
    $meta = [
        'title' => $page['title'],
        'description' => $description,
        'path' => '/' . $page['slug'],
        'image' => $page['cover_image'] ?: (content_images($page['content'])[0] ?? ''),
        'noindex' => $page['noindex'] || $preview,
        'schema' => array_merge([
            [
                '@type' => 'WebPage',
                '@id' => abs_url('/' . $page['slug']) . '#webpage',
                'url' => abs_url('/' . $page['slug']),
                'name' => $page['title'],
                'description' => $description,
                'isPartOf' => ['@id' => abs_url('/') . '#website'],
                'dateModified' => iso_date($page['updated_at']),
            ],
            breadcrumb_schema([[t('Início'), '/'], [$page['title'], '/' . $page['slug']]]),
        ], video_schemas($page['content'], $page['title'], $description, iso_date($page['created_at']))),
    ];
    if (!$preview) {
        track_view('page', (int) $page['id']);
    }
    render('page', compact('page', 'meta', 'preview'));
}

function show_blog(): void
{
    $total = (int) db()->query('SELECT COUNT(*) FROM posts WHERE ' . public_posts_where())->fetchColumn();
    $pages = max(1, (int) ceil($total / POSTS_PER_PAGE));
    $current = max(1, (int) ($_GET['pagina'] ?? 1));
    if ($current > $pages) {
        not_found();
        return;
    }
    $st = db()->prepare('SELECT * FROM posts WHERE ' . public_posts_where() . ' ORDER BY published_at DESC LIMIT ? OFFSET ?');
    $st->execute([POSTS_PER_PAGE, ($current - 1) * POSTS_PER_PAGE]);
    $posts = $st->fetchAll();

    $title = setting('blog_title', 'Blog');
    $meta = [
        'title' => $title . ($current > 1 ? ' ' . t('— página %d', $current) : ''),
        'description' => t('Artigos de %s.', setting('author_name', setting('site_name'))),
        'path' => '/blog' . ($current > 1 ? "?pagina=$current" : ''),
        'noindex' => $total === 0,
        'schema' => [
            [
                '@type' => 'Blog',
                '@id' => abs_url('/blog') . '#blog',
                'url' => abs_url('/blog'),
                'name' => $title,
                'author' => ['@id' => abs_url('/') . '#person'],
                'blogPost' => array_map(fn($p) => [
                    '@type' => 'BlogPosting',
                    'headline' => $p['title'],
                    'url' => abs_url('/blog/' . $p['slug']),
                    'datePublished' => iso_date($p['published_at']),
                ], $posts),
            ],
            breadcrumb_schema([[t('Início'), '/'], [$title, '/blog']]),
        ],
    ];
    track_view('blog');
    render('blog', compact('posts', 'meta', 'title', 'current', 'pages'));
}

function show_post(string $slug): void
{
    $st = db()->prepare('SELECT * FROM posts WHERE slug = ?');
    $st->execute([$slug]);
    $post = $st->fetch();
    $preview = $post && (!$post['published'] || $post['published_at'] > now());
    if (!$post || ($preview && !current_user_id())) {
        not_found();
        return;
    }
    $url = '/blog/' . $post['slug'];
    $description = $post['meta_description'] ?: ($post['excerpt'] ?: text_excerpt($post['content']));
    $images = array_values(array_filter(array_unique(array_merge(
        $post['cover_image'] ? [abs_url($post['cover_image'])] : [],
        content_images($post['content'])
    ))));
    $tags = array_values(array_filter(array_map('trim', explode(',', $post['tags']))));

    $article = [
        '@type' => 'BlogPosting',
        '@id' => abs_url($url) . '#article',
        'headline' => mb_substr($post['title'], 0, 110),
        'description' => $description,
        'url' => abs_url($url),
        'mainEntityOfPage' => abs_url($url),
        'datePublished' => iso_date($post['published_at']),
        'dateModified' => iso_date($post['updated_at']),
        'author' => ['@id' => abs_url('/') . '#person'],
        'publisher' => ['@id' => abs_url('/') . '#person'],
        'isPartOf' => ['@id' => abs_url('/') . '#website'],
        'inLanguage' => setting('site_lang', 'pt-PT'),
        'wordCount' => str_word_count(strip_tags($post['content'])),
    ];
    if ($images) {
        $article['image'] = $images;
    }
    if ($tags) {
        $article['keywords'] = implode(', ', $tags);
    }

    $meta = [
        'title' => $post['title'],
        'description' => $description,
        'path' => $url,
        'image' => $images[0] ?? '',
        'type' => 'article',
        'published' => $post['published_at'],
        'modified' => $post['updated_at'],
        'noindex' => $post['noindex'] || $preview,
        'schema' => array_merge([
            $article,
            breadcrumb_schema([[t('Início'), '/'], [setting('blog_title', 'Blog'), '/blog'], [$post['title'], $url]]),
        ], video_schemas($post['content'], $post['title'], $description, iso_date($post['published_at']))),
    ];
    if (!$preview) {
        track_view('post', (int) $post['id']);
    }
    render('post', compact('post', 'meta', 'preview', 'tags'));
}

function highlights_title(): string
{
    return setting('highlights_title', t('Destaques'));
}

function show_highlights(): void
{
    $kind = (string) ($_GET['tipo'] ?? '');
    if ($kind !== '' && !isset(HIGHLIGHT_KINDS[$kind])) {
        redirect('/highlights', 301);
    }
    $items = highlights_list(90, $kind);
    $kinds = db()->query('SELECT kind, COUNT(*) FROM highlights WHERE visible = 1 GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);
    $featured = $kind === '' ? array_values(array_filter($items, fn($h) => $h['featured'])) : [];
    $rest = $kind === '' ? array_values(array_filter($items, fn($h) => !$h['featured'])) : $items;
    $title = highlights_title();
    $meta = [
        'title' => $title . ($kind !== '' ? ' — ' . HIGHLIGHT_KINDS[$kind] : ''),
        'description' => t('Vídeos, publicações e projetos de %s.', setting('author_name', setting('site_name'))),
        'path' => '/highlights' . ($kind !== '' ? '?tipo=' . $kind : ''),
        'noindex' => !$items || $kind !== '',
        'schema' => [
            [
                '@type' => 'CollectionPage',
                '@id' => abs_url('/highlights') . '#webpage',
                'url' => abs_url('/highlights'),
                'name' => $title,
                'isPartOf' => ['@id' => abs_url('/') . '#website'],
                'about' => ['@id' => abs_url('/') . '#person'],
                'hasPart' => array_map(fn($h) => [
                    '@type' => $h['kind'] === 'youtube' ? 'VideoObject' : 'CreativeWork',
                    'name' => $h['title'],
                    'url' => $h['url'],
                ] + ($h['kind'] === 'youtube' ? [
                    'thumbnailUrl' => $h['image'] ? abs_url($h['image']) : 'https://i.ytimg.com/vi/' . youtube_id($h['url']) . '/hqdefault.jpg',
                    'uploadDate' => iso_date($h['item_date']),
                    'description' => $h['description'] ?: $h['title'],
                    'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . youtube_id($h['url']),
                ] : []), array_slice($items, 0, 30)),
            ],
            breadcrumb_schema([[t('Início'), '/'], [$title, '/highlights']]),
        ],
    ];
    track_view('highlights');
    youtube_sync_in_background();
    render('highlights', compact('items', 'featured', 'rest', 'kinds', 'kind', 'title', 'meta'));
}

function show_research(string $tab): void
{
    research_sync_in_background();
    $type = (string) ($_GET['tipo'] ?? '');
    $byCites = ($_GET['ordem'] ?? '') === 'citacoes';
    if (($type !== '' && ($tab !== 'pubs' || !isset(PUB_TYPES[$type]))) || (isset($_GET['ordem']) && !$byCites)) {
        redirect($tab === 'pubs' ? '/research' : '/research/projects', 301);
    }
    $pubCount = (int) db()->query('SELECT COUNT(*) FROM publications WHERE visible = 1')->fetchColumn();
    $projCount = (int) db()->query('SELECT COUNT(*) FROM projects WHERE visible = 1')->fetchColumn();
    if ($pubCount + $projCount === 0) {
        not_found();
        return;
    }
    if ($tab === 'pubs' && $pubCount === 0) {
        redirect('/research/projects');
    }
    $title = research_title();
    $author = setting('author_name', setting('site_name'));
    $path = $tab === 'pubs' ? '/research' : '/research/projects';
    $types = db()->query('SELECT type, COUNT(*) FROM publications WHERE visible = 1 GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);
    $summary = [
        'pubs' => $pubCount,
        'articles' => (int) ($types['article'] ?? 0),
        'indexed' => (int) db()->query("SELECT COUNT(*) FROM publications WHERE visible = 1 AND indexed <> ''")->fetchColumn(),
        'projects' => $projCount,
        'since' => (int) db()->query('SELECT MIN(year) FROM publications WHERE visible = 1 AND year > 0')->fetchColumn(),
    ];
    $featured = [];
    $items = [];
    if ($tab === 'pubs') {
        $st = db()->prepare('SELECT * FROM publications WHERE visible = 1' . ($type ? ' AND type = ?' : '')
            . ($byCites ? ' ORDER BY COALESCE(citations, 0) DESC, year DESC' : ' ORDER BY year DESC, pub_date DESC, id DESC'));
        $st->execute($type ? [$type] : []);
        $items = $st->fetchAll();
        $featured = $type === '' && !$byCites ? array_values(array_filter($items, fn($p) => $p['featured'])) : [];
        $parts = array_map(fn($p) => array_filter([
            '@type' => 'ScholarlyArticle',
            'name' => $p['title'],
            'author' => array_map(fn($a) => ['@type' => 'Person', 'name' => trim($a)], array_slice(array_filter(explode(';', $p['authors'])), 0, 12)),
            'datePublished' => $p['pub_date'] ?: ($p['year'] ? (string) $p['year'] : ''),
            'isPartOf' => $p['venue'] ? ['@type' => 'CreativeWork', 'name' => $p['venue']] : null,
            'url' => pub_link($p),
            'sameAs' => $p['doi'] ? 'https://doi.org/' . $p['doi'] : null,
        ]), array_slice($items, 0, 100));
        $description = t('Publicações científicas de %s: artigos em revista, conferências e capítulos de livro.', $author);
    } else {
        $items = db()->query("SELECT * FROM projects WHERE visible = 1
            ORDER BY featured DESC, (end_date = '' OR end_date >= date('now')) DESC, COALESCE(NULLIF(end_date, ''), '9999') DESC, start_date DESC")->fetchAll();
        $parts = array_map(fn($p) => array_filter([
            '@type' => 'ResearchProject',
            'name' => $p['title'],
            'alternateName' => $p['acronym'] ?: null,
            'description' => $p['description'] ?: null,
            'funder' => $p['funder'] ? ['@type' => 'Organization', 'name' => $p['funder']] : null,
            'url' => $p['url'] ?: null,
            'foundingDate' => $p['start_date'] ?: null,
            'dissolutionDate' => $p['end_date'] ?: null,
        ]), $items);
        $description = t('Projetos de investigação e desenvolvimento de %s.', $author);
    }
    $tabTitle = $tab === 'pubs' ? t('Publicações') : t('Projetos');
    $meta = [
        'title' => $tabTitle . ' — ' . $title . ($type !== '' ? ' — ' . pub_type_label($type) : ''),
        'description' => $description,
        'path' => $path . ($type !== '' ? '?tipo=' . $type : ''),
        'noindex' => $type !== '' || $byCites,
        'schema' => [
            [
                '@type' => 'CollectionPage',
                '@id' => abs_url($path) . '#webpage',
                'url' => abs_url($path),
                'name' => $tabTitle,
                'isPartOf' => ['@id' => abs_url('/') . '#website'],
                'about' => ['@id' => abs_url('/') . '#person'],
                'hasPart' => array_values($parts),
            ],
            breadcrumb_schema([[t('Início'), '/'], [$title, '/research']] + ($tab === 'projects' ? [2 => [$tabTitle, $path]] : [])),
        ],
    ];
    track_view('research');
    $impact = research_impact_summary();
    render('research', compact('tab', 'type', 'byCites', 'types', 'items', 'featured', 'summary', 'impact', 'title', 'meta'));
}

function show_activities(): void
{
    $kind = (string) ($_GET['tipo'] ?? '');
    if ($kind !== '' && !isset(ACTIVITY_KINDS[$kind])) {
        redirect('/activities', 301);
    }
    $items = activities_list($kind);
    if (!$items && $kind === '') {
        not_found();
        return;
    }
    $kinds = db()->query('SELECT kind, COUNT(*) FROM activities WHERE visible = 1 GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);
    $title = activities_title();
    $author = setting('author_name', setting('site_name'));
    $parts = [];
    foreach (array_slice($items, 0, 60) as $a) {
        $date = strlen($a['item_date']) >= 4 ? $a['item_date'] : null;
        $parts[] = array_filter(match ($a['kind']) {
            'talk' => [
                '@type' => 'Event',
                'name' => $a['title'],
                'startDate' => $date,
                'location' => $a['location'] || $a['event'] ? ['@type' => 'Place', 'name' => $a['location'] ?: $a['event']] : null,
                'organizer' => $a['event'] ? ['@type' => 'Organization', 'name' => $a['event']] : null,
                'performer' => ['@id' => abs_url('/') . '#person'],
                'url' => $a['url'] ?: null,
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            ],
            'press', 'interview' => [
                '@type' => 'NewsArticle',
                'headline' => $a['title'],
                'datePublished' => $date,
                'publisher' => $a['event'] ? ['@type' => 'Organization', 'name' => $a['event']] : null,
                'about' => ['@id' => abs_url('/') . '#person'],
                'url' => $a['url'] ?: null,
            ],
            default => ['@type' => 'CreativeWork', 'name' => $a['title'], 'dateCreated' => $date, 'url' => $a['url'] ?: null],
        });
    }
    $meta = [
        'title' => $title . ($kind !== '' ? ' — ' . activity_kind_label($kind, true) : ''),
        'description' => t('Palestras, prémios, notícias e entrevistas de %s.', $author),
        'path' => '/activities' . ($kind !== '' ? '?tipo=' . $kind : ''),
        'noindex' => $kind !== '',
        'schema' => [
            [
                '@type' => 'CollectionPage',
                '@id' => abs_url('/activities') . '#webpage',
                'url' => abs_url('/activities'),
                'name' => $title,
                'isPartOf' => ['@id' => abs_url('/') . '#website'],
                'about' => ['@id' => abs_url('/') . '#person'],
                'hasPart' => $parts,
            ],
            breadcrumb_schema([[t('Início'), '/'], [$title, '/activities']]),
        ],
    ];
    track_view('activities');
    render('activities', compact('items', 'kinds', 'kind', 'title', 'meta'));
}

function not_found(): void
{
    http_response_code(404);
    track_view('404');
    render('404', ['meta' => ['title' => t('Página não encontrada'), 'path' => $_SERVER['REQUEST_URI'] ?? '/', 'noindex' => true]]);
}
