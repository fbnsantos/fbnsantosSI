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
    render('home', compact('page', 'posts', 'meta'));
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
    render('post', compact('post', 'meta', 'preview', 'tags'));
}

function not_found(): void
{
    http_response_code(404);
    render('404', ['meta' => ['title' => t('Página não encontrada'), 'path' => $_SERVER['REQUEST_URI'] ?? '/', 'noindex' => true]]);
}
