<?php
/**
 * Palestras, prémios e imprensa: cronologia em /activities (e na página inicial, os ★ destacados).
 *
 * - Adiciona-se no backoffice, colando o link (notícia, página do evento, vídeo): o título, o meio/organizador,
 *   a data e a imagem são lidos da página; o resto escreve-se à mão.
 * - Cada item pode ter um vídeo (dos Destaques ou um link do YouTube), que toca na própria página (youtube-nocookie).
 * - Os prémios entram também nos dados estruturados da pessoa (Person.award) para o Google.
 */
declare(strict_types=1);

/** Tipo => [plural (filtros), singular (selo)]. */
const ACTIVITY_KINDS = [
    'talk' => ['Palestras', 'Palestra'],
    'award' => ['Prémios', 'Prémio'],
    'press' => ['Imprensa', 'Imprensa'],
    'interview' => ['Entrevistas', 'Entrevista'],
    'other' => ['Outros', 'Outro'],
];

function activities_title(): string
{
    return setting('activities_title') ?: t('Palestras, prémios e imprensa');
}

function has_activities(): bool
{
    return (bool) db()->query('SELECT 1 FROM activities WHERE visible = 1 LIMIT 1')->fetchColumn();
}

function activity_kind_label(string $kind, bool $plural = false): string
{
    return t(ACTIVITY_KINDS[$kind][$plural ? 0 : 1] ?? 'Outro');
}

/** Visíveis, mais recentes primeiro (sem data no fim). */
function activities_list(string $kind = '', int $limit = 500): array
{
    $st = db()->prepare('SELECT * FROM activities WHERE visible = 1' . ($kind !== '' ? ' AND kind = ?' : '')
        . " ORDER BY item_date = '', item_date DESC, id DESC LIMIT " . $limit);
    $st->execute($kind !== '' ? [$kind] : []);
    return $st->fetchAll();
}

/** Para a página inicial: os ★ destacados; se não houver, os mais recentes. */
function activities_home(int $limit = 4): array
{
    $st = db()->prepare("SELECT * FROM activities WHERE visible = 1 AND featured = 1 ORDER BY item_date DESC, id DESC LIMIT $limit");
    $st->execute();
    return $st->fetchAll() ?: activities_list('', 3);
}

/** Títulos dos prémios, para Person.award. */
function activity_awards(): array
{
    try {
        $rows = db()->query("SELECT title, event, item_date FROM activities WHERE visible = 1 AND kind = 'award' ORDER BY item_date DESC LIMIT 30")->fetchAll();
    } catch (Throwable) {
        return [];
    }
    return array_map(fn($a) => $a['title'] . ($a['event'] !== '' ? ' — ' . $a['event'] : '') . ($a['item_date'] !== '' ? ' (' . substr($a['item_date'], 0, 4) . ')' : ''), $rows);
}

/** "2024-05-12" → "12 de maio de 2024"; "2024-05" → "maio de 2024"; "2024" → "2024". */
function activity_date(string $d): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return format_date($d);
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $d, $m)) {
        $t = strtotime("$d-01");
        if (!site_is_pt()) {
            return date('F Y', $t);
        }
        $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $months[(int) $m[2] - 1] . ' de ' . $m[1];
    }
    return $d;
}

/** Normaliza datas escritas à mão: AAAA, AAAA-MM ou AAAA-MM-DD (aceita também DD/MM/AAAA). */
function activity_clean_date(string $d): string
{
    $d = trim($d);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $d, $m)) {
        $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $d) ? $d : '';
}

/** Vídeos dos Destaques, para escolher no formulário. */
function activity_video_options(): array
{
    return db()->query("SELECT url, title FROM highlights WHERE kind = 'youtube' ORDER BY item_date DESC LIMIT 200")->fetchAll(PDO::FETCH_KEY_PAIR);
}
