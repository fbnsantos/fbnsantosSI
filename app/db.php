<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $path = (string) config('db_path', ROOT . '/data/site.sqlite');
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $fresh = !is_file($path);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    if ($fresh) {
        seed($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS settings (
        name TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        meta_description TEXT NOT NULL DEFAULT '',
        content TEXT NOT NULL DEFAULT '',
        cover_image TEXT NOT NULL DEFAULT '',
        published INTEGER NOT NULL DEFAULT 1,
        noindex INTEGER NOT NULL DEFAULT 0,
        in_menu INTEGER NOT NULL DEFAULT 1,
        menu_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        meta_description TEXT NOT NULL DEFAULT '',
        excerpt TEXT NOT NULL DEFAULT '',
        content TEXT NOT NULL DEFAULT '',
        cover_image TEXT NOT NULL DEFAULT '',
        tags TEXT NOT NULL DEFAULT '',
        published INTEGER NOT NULL DEFAULT 1,
        noindex INTEGER NOT NULL DEFAULT 0,
        published_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS posts_published ON posts (published, published_at);
    CREATE TABLE IF NOT EXISTS media (
        id INTEGER PRIMARY KEY,
        path TEXT NOT NULL UNIQUE,
        original_name TEXT NOT NULL,
        mime TEXT NOT NULL,
        size INTEGER NOT NULL,
        width INTEGER,
        height INTEGER,
        alt TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS login_attempts (
        ip TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS api_tokens (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        prefix TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_used_at TEXT
    );
    SQL);

    // Aprovação do que chega pela API: review_status = 'pending' até ser aprovado no backoffice;
    // source = quem criou/alterou (ex.: "API: ChatGPT").
    // Chave da API cifrada (para a voltar a mostrar no backoffice); o hash continua a ser o que autentica.
    $cols = array_column($pdo->query('PRAGMA table_info(api_tokens)')->fetchAll(), 'name');
    if (!in_array('token_enc', $cols, true)) {
        $pdo->exec("ALTER TABLE api_tokens ADD COLUMN token_enc TEXT NOT NULL DEFAULT ''");
    }

    foreach (['pages', 'posts'] as $table) {
        $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!in_array('review_status', $cols, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN review_status TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('source', $cols, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN source TEXT NOT NULL DEFAULT ''");
        }
    }
}

function pending_review_count(): int
{
    return (int) db()->query("SELECT (SELECT COUNT(*) FROM posts WHERE review_status = 'pending')
        + (SELECT COUNT(*) FROM pages WHERE review_status = 'pending')")->fetchColumn();
}

function seed(PDO $pdo): void
{
    // Conteúdo inicial (o mesmo que estava em fbnsantos.com); depois edita-se tudo no backoffice.
    $data = require __DIR__ . '/seed.php';
    $now = now();

    $st = $pdo->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
    foreach ($data['settings'] as $k => $v) {
        $st->execute([$k, $v]);
    }
    $st = $pdo->prepare('INSERT INTO pages (slug, title, meta_description, content, in_menu, menu_order, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($data['pages'] as [$slug, $title, $desc, $html, $inMenu, $order]) {
        $st->execute([$slug, $title, $desc, sanitize_html($html), $inMenu, $order, $now, $now]);
    }
}

function user_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function menu_pages(): array
{
    return db()->query("SELECT slug, title FROM pages
        WHERE published = 1 AND in_menu = 1 AND slug <> 'home'
        ORDER BY menu_order, title")->fetchAll();
}

function has_public_posts(): bool
{
    return (bool) db()->query('SELECT 1 FROM posts WHERE ' . public_posts_where() . ' LIMIT 1')->fetchColumn();
}

function page_exists(string $slug): bool
{
    $st = db()->prepare('SELECT 1 FROM pages WHERE slug = ? AND published = 1');
    $st->execute([$slug]);
    return (bool) $st->fetchColumn();
}

/** SQL para artigos visíveis publicamente (publicados e com data já atingida). */
function public_posts_where(): string
{
    return 'published = 1 AND published_at <= ' . db()->quote(now());
}
