<?php
/**
 * Investigação: publicações e projetos, sincronizados do ORCID e do Authenticus e mostrados em /research.
 *
 * - ORCID: API pública (pub.orcid.org, sem chave) → publicações (com autores) e projetos (financiamentos).
 * - Authenticus: lista pública de publicações do investigador (a mesma que o Authenticus oferece para
 *   incorporar noutros sites). A API do Authenticus só está disponível para instituições.
 * - As duas fontes são juntadas pelo DOI ou pelo título; a sincronização só acrescenta ou preenche
 *   campos vazios: o que for editado, escondido ou destacado no backoffice nunca é desfeito.
 * - Corre sozinha uma vez por dia (em segundo plano, depois de responder a uma visita) ou no backoffice.
 */
declare(strict_types=1);

const RESEARCH_SYNC_EVERY = 24 * 3600;
const PUB_TYPES = [
    'article' => 'Artigos em revista',
    'conference' => 'Artigos em conferência',
    'chapter' => 'Capítulos de livro',
    'book' => 'Livros',
    'thesis' => 'Teses',
    'patent' => 'Patentes',
    'other' => 'Outros',
];
const AUTHENTICUS = 'https://www.authenticus.pt';

/* ---------- Identificadores ---------- */

function research_orcid_id(): string
{
    $raw = setting('research_orcid') ?: setting('social_orcid');
    return preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i', $raw, $m) ? strtoupper($m[1]) : '';
}

function research_authenticus_id(): string
{
    return preg_match('/^\d+$/', setting('research_authenticus')) ? setting('research_authenticus') : '';
}

/** Aceita o número, o URL da lista de publicações ou o identificador curto (R-002-RGT). */
function authenticus_resolve(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^\d+$/', $input)) {
        return $input;
    }
    if (preg_match('#(?:publicationsList|embeddedPublicationsList|profileOfResearchers/view)/(\d+)#', $input, $m)) {
        return $m[1];
    }
    if (preg_match('/\b(R-[A-Z0-9]{3}-[A-Z0-9]{3})\b/i', $input, $m)) {
        $r = http_request('GET', AUTHENTICUS . '/' . strtoupper($m[1]), [], null, 15);
        if (preg_match('#publicationsList/(\d+)#', $r['body'], $id)) {
            return $id[1];
        }
    }
    return null;
}

/* ---------- Normalização ---------- */

/** Chave para reconhecer o mesmo título vindo de fontes diferentes. */
function research_norm(string $title): string
{
    $s = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D) ?: $s) ?? $s; // tira acentos
    } else {
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    }
    return mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '', 0, 250);
}

function research_clean(string $s): string
{
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '', " \t\n,;");
}

/** "SCIENTIFIC DATA" → "Scientific Data" (siglas curtas como IEEE ficam como estão). */
function research_title_case(string $s): string
{
    if ($s === '' || mb_strtoupper($s, 'UTF-8') !== $s) {
        return $s;
    }
    $small = ['of', 'and', 'in', 'on', 'for', 'the', 'a', 'an', 'to', 'de', 'da', 'do', 'e', 'em'];
    $words = explode(' ', mb_strtolower($s, 'UTF-8'));
    foreach ($words as $i => $w) {
        if (preg_match('/^\W*(ieee|acm|ifac|ieice|spie|ifip|mdpi|aaai|ijcai|iros|icra|ras|ceur|lncs|ccis|ai|iot|ict|gps|uav|ros)\W*$/', $w)) {
            $words[$i] = mb_strtoupper($w, 'UTF-8');
        } elseif ($i > 0 && in_array($w, $small, true)) {
            $words[$i] = $w;
        } else {
            $words[$i] = mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return implode(' ', $words);
}

function research_doi(string $s): string
{
    return preg_match('#(10\.\d{4,9}/\S+)#', $s, $m) ? strtolower(rtrim($m[1], '.,;')) : '';
}

function pub_type_from_orcid(string $t): string
{
    return match (true) {
        $t === 'journal-article', $t === 'review', $t === 'magazine-article', $t === 'newspaper-article' => 'article',
        str_starts_with($t, 'conference') => 'conference',
        $t === 'book-chapter' => 'chapter',
        $t === 'book', $t === 'edited-book' => 'book',
        $t === 'dissertation-thesis', $t === 'supervised-student-publication' => 'thesis',
        $t === 'patent' => 'patent',
        default => 'other',
    };
}

function pub_type_from_authenticus(string $t): string
{
    $t = strtolower($t);
    return match (true) {
        in_array($t, ['article', 'review', 'letter', 'editorial material', 'note', 'short survey', 'data paper'], true) => 'article',
        str_contains($t, 'proceedings'), str_contains($t, 'conference'), str_contains($t, 'meeting') => 'conference',
        str_contains($t, 'chapter') => 'chapter',
        $t === 'book' => 'book',
        str_contains($t, 'thesis') => 'thesis',
        $t === 'patent' => 'patent',
        default => 'other',
    };
}

/* ---------- Gravação (juntar fontes sem desfazer o que foi editado) ---------- */

/** Junta uma publicação vinda de uma fonte. Devolve true se for nova. */
function pub_upsert(array $p, string $source): bool
{
    $p['title'] = research_clean($p['title'] ?? '');
    $norm = research_norm($p['title']);
    if (mb_strlen($norm) < 8) {
        return false;
    }
    $p += ['authors' => '', 'venue' => '', 'year' => 0, 'pub_date' => '', 'type' => 'other', 'doi' => '', 'url' => '',
        'indexed' => '', 'orcid_code' => '', 'authenticus_id' => ''];
    $row = null;
    if ($p['doi'] !== '') {
        $st = db()->prepare('SELECT * FROM publications WHERE doi = ?');
        $st->execute([$p['doi']]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        $st = db()->prepare('SELECT * FROM publications WHERE norm = ?');
        $st->execute([$norm]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        db()->prepare('INSERT INTO publications (title, norm, authors, venue, year, pub_date, type, doi, url, indexed, sources,
            orcid_code, authenticus_id, visible, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($p['title'], 0, 500), $norm, mb_substr($p['authors'], 0, 3000), mb_substr($p['venue'], 0, 300),
                (int) $p['year'], $p['pub_date'], $p['type'], $p['doi'], $p['url'], $p['indexed'], $source,
                (string) $p['orcid_code'], (string) $p['authenticus_id'], setting('research_auto_visible', '1') === '1' ? 1 : 0, now(), now()]);
        return true;
    }
    $set = [];
    foreach (['authors', 'venue', 'pub_date', 'doi', 'url', 'orcid_code', 'authenticus_id'] as $f) {
        if ((string) $row[$f] === '' && (string) $p[$f] !== '') {
            $set[$f] = (string) $p[$f];
        }
    }
    if ((int) $row['year'] === 0 && (int) $p['year'] > 0) {
        $set['year'] = (int) $p['year'];
    }
    if ($row['type'] === 'other' && $p['type'] !== 'other') {
        $set['type'] = $p['type'];
    }
    foreach (['indexed' => $p['indexed'], 'sources' => $source] as $f => $add) {
        $merged = implode(',', array_unique(array_filter(array_merge(explode(',', (string) $row[$f]), explode(',', (string) $add)))));
        if ($merged !== (string) $row[$f]) {
            $set[$f] = $merged;
        }
    }
    if ($set) {
        $set['updated_at'] = now();
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        db()->prepare("UPDATE publications SET $cols WHERE id = ?")->execute([...array_values($set), $row['id']]);
    }
    return false;
}

/** "ROMOVI - Robot Modular…" → ['ROMOVI', 'Robot Modular…']. */
function project_split_title(string $title): array
{
    if (preg_match('/^\s*([\p{L}0-9&+.][\p{L}0-9&+.\-]{1,23}?)(?:\s+[-–—]|\s*:)\s+(.{8,})$/u', $title, $m)) {
        return [$m[1], trim($m[2])];
    }
    return ['', $title];
}

function project_upsert(array $p, string $source): bool
{
    $p['title'] = research_clean($p['title'] ?? '');
    $norm = research_norm($p['title']);
    if ($norm === '') {
        return false;
    }
    $p += ['acronym' => '', 'funder' => '', 'type' => '', 'start_date' => '', 'end_date' => '', 'grant_number' => '', 'url' => '', 'orcid_code' => ''];
    if ($p['acronym'] === '') {
        [$p['acronym']] = project_split_title($p['title']);
    }
    $st = db()->prepare('SELECT * FROM projects WHERE norm = ?');
    $st->execute([$norm]);
    $row = $st->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO projects (title, norm, acronym, funder, type, start_date, end_date, grant_number, url, sources,
            orcid_code, visible, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($p['title'], 0, 500), $norm, mb_substr($p['acronym'], 0, 40), mb_substr($p['funder'], 0, 200),
                $p['type'], $p['start_date'], $p['end_date'], $p['grant_number'], $p['url'], $source, (string) $p['orcid_code'],
                setting('research_auto_visible', '1') === '1' ? 1 : 0, now(), now()]);
        return true;
    }
    $set = [];
    foreach (['funder', 'type', 'start_date', 'end_date', 'grant_number', 'url', 'orcid_code'] as $f) {
        if ((string) $row[$f] === '' && (string) $p[$f] !== '') {
            $set[$f] = (string) $p[$f];
        }
    }
    if ($set) {
        $set['updated_at'] = now();
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        db()->prepare("UPDATE projects SET $cols WHERE id = ?")->execute([...array_values($set), $row['id']]);
    }
    return false;
}

/* ---------- ORCID ---------- */

function orcid_get(string $path): ?array
{
    $r = http_request('GET', 'https://pub.orcid.org/v3.0/' . $path, ['Accept: application/json'], null, 30, 20_000_000);
    if ($r['status'] !== 200) {
        return null;
    }
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : null;
}

function orcid_date(?array $d): string
{
    $y = $d['year']['value'] ?? '';
    if (!preg_match('/^\d{4}$/', (string) $y)) {
        return '';
    }
    $out = $y;
    foreach (['month', 'day'] as $k) {
        $v = $d[$k]['value'] ?? '';
        if (!preg_match('/^\d{1,2}$/', (string) $v)) {
            break;
        }
        $out .= '-' . str_pad((string) $v, 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

function orcid_external(array $item, string $type): array
{
    foreach ($item['external-ids']['external-id'] ?? [] as $x) {
        if (($x['external-id-type'] ?? '') === $type && ($x['external-id-relationship'] ?? 'self') === 'self') {
            return [(string) ($x['external-id-value'] ?? ''), (string) ($x['external-id-url']['value'] ?? '')];
        }
    }
    return ['', ''];
}

/** Autores de uma obra completa do ORCID (lista de contribuidores ou, em falta, o BibTeX). */
function orcid_work_authors(array $work): string
{
    $names = [];
    foreach ($work['contributors']['contributor'] ?? [] as $c) {
        if ($n = research_clean((string) ($c['credit-name']['value'] ?? ''))) {
            $names[] = $n;
        }
    }
    if (!$names && ($work['citation']['citation-type'] ?? '') === 'bibtex'
        && preg_match('/author\s*=\s*[{"](.+?)[}"]\s*,\s*\n/si', (string) ($work['citation']['citation-value'] ?? ''), $m)) {
        foreach (preg_split('/\s+and\s+/i', $m[1]) as $n) {
            $n = research_clean(str_replace(['{', '}'], '', $n));
            if ($n !== '' && !str_contains(strtolower($n), 'corresponding author')) {
                $names[] = $n;
            }
        }
    }
    return implode('; ', array_unique($names));
}

/** Devolve [novas publicações, novos projetos] ou uma mensagem de erro. */
function orcid_sync(string $orcid): array|string
{
    $works = orcid_get("$orcid/works");
    if ($works === null) {
        return 'O ORCID não respondeu (verifique o ORCID iD e se o perfil é público).';
    }
    $new = 0;
    $byCode = [];
    foreach ($works['group'] ?? [] as $g) {
        $s = $g['work-summary'][0] ?? null;
        if (!$s || ($s['visibility'] ?? 'public') !== 'public') {
            continue;
        }
        [$doiRaw, $doiUrl] = orcid_external($s, 'doi');
        $doi = research_doi($doiRaw ?: $doiUrl);
        $date = orcid_date($s['publication-date'] ?? null);
        $byCode[(string) $s['put-code']] = [
            'title' => (string) ($s['title']['title']['value'] ?? ''),
            'venue' => research_title_case(research_clean((string) ($s['journal-title']['value'] ?? ''))),
            'year' => (int) substr($date, 0, 4),
            'pub_date' => $date,
            'type' => pub_type_from_orcid((string) ($s['type'] ?? '')),
            'doi' => $doi,
            'url' => $doi !== '' ? "https://doi.org/$doi" : (string) ($s['url']['value'] ?? ''),
            'orcid_code' => (string) $s['put-code'],
        ];
    }
    // Autores: só vêm na obra completa; pede-os em blocos de 100, apenas para as que ainda não os têm.
    $have = db()->query("SELECT orcid_code FROM publications WHERE orcid_code <> '' AND authors <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff(array_keys($byCode), array_map('strval', $have)));
    foreach (array_chunk($missing, 100) as $chunk) {
        $bulk = orcid_get("$orcid/works/" . implode(',', $chunk));
        foreach ($bulk['bulk'] ?? [] as $b) {
            $w = $b['work'] ?? null;
            if ($w && isset($byCode[(string) $w['put-code']])) {
                $byCode[(string) $w['put-code']]['authors'] = orcid_work_authors($w);
            }
        }
    }
    foreach ($byCode as $p) {
        $new += pub_upsert($p, 'orcid') ? 1 : 0;
    }

    $newProjects = 0;
    $fundings = orcid_get("$orcid/fundings");
    foreach ($fundings['group'] ?? [] as $g) {
        $s = $g['funding-summary'][0] ?? null;
        if (!$s || ($s['visibility'] ?? 'public') !== 'public') {
            continue;
        }
        [$grant, $grantUrl] = orcid_external($s, 'grant_number');
        $newProjects += project_upsert([
            'title' => (string) ($s['title']['title']['value'] ?? ''),
            'funder' => research_clean((string) ($s['organization']['name'] ?? '')),
            'type' => (string) ($s['type'] ?? ''),
            'start_date' => orcid_date($s['start-date'] ?? null),
            'end_date' => orcid_date($s['end-date'] ?? null),
            'grant_number' => $grant,
            'url' => (string) ($s['url']['value'] ?? '') ?: $grantUrl,
            'orcid_code' => (string) $s['put-code'],
        ], 'orcid') ? 1 : 0;
    }

    // Variantes do nome (para destacar o autor nas listas), só enquanto não forem editadas no backoffice.
    if (setting('research_names_auto', '1') === '1' && ($person = orcid_get("$orcid/personal-details"))) {
        $names = research_name_variants(array_filter([
            $person['name']['credit-name']['value'] ?? '',
            trim(($person['name']['given-names']['value'] ?? '') . ' ' . ($person['name']['family-name']['value'] ?? '')),
        ]));
        foreach ($person['other-names']['other-name'] ?? [] as $o) {
            if (preg_match('/^\p{Lu}\S*\s+\S/u', trim($o['content'] ?? ''))) {
                $names[] = research_clean($o['content']);
            }
        }
        research_add_names($names);
    }
    return [$new, $newProjects];
}

/** "Filipe Neves dos Santos" → também "Santos, Filipe", "Santos, F.", "F. N. Santos"… */
function research_name_variants(array $names): array
{
    $out = [];
    foreach ($names as $n) {
        $n = research_clean($n);
        $parts = preg_split('/\s+/u', $n);
        if (count($parts) < 2) {
            continue;
        }
        $first = $parts[0];
        $last = end($parts);
        $out[] = $n;
        $out[] = "$last, $first";
        $out[] = mb_substr($first, 0, 1) . ". $last";
        $middle = array_slice($parts, 1, -1);
        $middle = array_filter($middle, fn($w) => mb_strtolower($w) !== $w);
        if ($middle) {
            $out[] = implode(' ', array_slice($parts, 1)) . ", $first";
            $initials = implode(' ', array_map(fn($w) => mb_substr($w, 0, 1) . '.', $middle));
            $out[] = mb_substr($first, 0, 1) . ". $initials $last";
            $out[] = "$first $initials $last";
        }
    }
    return array_values(array_unique($out));
}

/** Acrescenta variantes do nome (sem repetir), enquanto a lista não tiver sido editada à mão. */
function research_add_names(array $names): void
{
    if (setting('research_names_auto', '1') !== '1') {
        return;
    }
    $list = setting_lines('research_names');
    $lower = array_map(fn($n) => mb_strtolower($n), $list);
    foreach ($names as $n) {
        if ($n !== '' && !in_array(mb_strtolower($n), $lower, true)) {
            $list[] = $n;
            $lower[] = mb_strtolower($n);
        }
    }
    setting_set('research_names', implode("\n", $list));
}

/* ---------- Authenticus ---------- */

/** Lê a lista pública de publicações (paginada, 20 por página), um tipo de documento de cada vez. */
function authenticus_sync(string $id): int|string
{
    $base = AUTHENTICUS . "/en/profileOfResearchers/embeddedPublicationsList/$id";
    $r = http_request('GET', $base, [], null, 30);
    if ($r['status'] !== 200 || !str_contains($r['body'], 'TITLE:')) {
        return 'O Authenticus não devolveu a lista de publicações (' . $r['status'] . ').';
    }
    preg_match_all('#<option value="([^"]+)">[^<(]*\((\d+)\)</option>#', $r['body'], $types, PREG_SET_ORDER);
    $new = 0;
    $seen = 0;
    $myNames = [];
    foreach ($types ?: [['', '', '0']] as $t) {
        $docType = html_entity_decode($t[1]);
        $pages = max(1, (int) ceil((int) $t[2] / 20));
        for ($page = 1; $page <= min($pages, 30); $page++) {
            usleep(250_000);
            $url = $base . '?' . http_build_query(['pubsFilter' => 'all', 'docType' => $docType, 'page' => $page]);
            $html = http_request('GET', $url, [], null, 30)['body'];
            $items = authenticus_parse($html, $id);
            if (!$items) {
                break;
            }
            foreach ($items as $p) {
                $p['type'] = pub_type_from_authenticus($docType);
                $myNames += array_flip($p['my_names']);
                unset($p['my_names']);
                $new += pub_upsert($p, 'authenticus') ? 1 : 0;
                $seen++;
            }
        }
    }
    // Só nomes com a mesma inicial do nome próprio (o Authenticus por vezes associa publicações de homónimos).
    // Nome próprio completo → tem de ser igual; só iniciais → a primeira tem de coincidir.
    $first = mb_strtolower(explode(' ', setting_lines('research_names')[0] ?? '')[0]);
    research_add_names(array_filter(array_keys($myNames), function ($n) use ($first) {
        $given = trim(str_contains($n, ',') ? explode(',', $n, 2)[1] : explode(' ', $n)[0]);
        $word = preg_split('/[\s.]+/u', $given)[0] ?? '';
        if ($first === '' || $word === '') {
            return $first === '';
        }
        $isInitials = mb_strlen($word) <= 3 && preg_match('/^\p{Lu}+$/u', $word);
        return $isInitials ? mb_strtolower(mb_substr($word, 0, 1)) === mb_substr($first, 0, 1) : mb_strtolower($word) === $first;
    }));
    return $seen ? $new : 'Não foi possível ler as publicações do Authenticus (o formato da página pode ter mudado).';
}

function authenticus_parse(string $html, string $id = ''): array
{
    $out = [];
    foreach (array_slice(explode('<b>TITLE: </b>', $html), 1) as $chunk) {
        if (!preg_match('#<a href="/en/publications/view/(\d+)"[^>]*>(.*?)</a>#s', $chunk, $m)) {
            continue;
        }
        $p = ['authenticus_id' => $m[1], 'title' => research_clean($m[2]), 'url' => AUTHENTICUS . '/en/publications/view/' . $m[1]];
        // O próprio investigador aparece ligado à sua lista: é assim que o Authenticus escreve o nome dele.
        preg_match_all('#publicationsList/' . preg_quote($id, '#') . '"[^>]*>([^<]+)</a>#', $chunk, $mine);
        $p['my_names'] = array_map('research_clean', $mine[1]);
        if (preg_match('#AUTHORS: </b>(.*?)</small>#s', $chunk, $a)) {
            $names = array_map('research_clean', explode(';', preg_replace('#<i [^>]*></i>#', '', $a[1])));
            $p['authors'] = implode('; ', array_filter($names));
        }
        if (preg_match('#PUBLISHED: </b>\s*(\d{4})#', $chunk, $y)) {
            $p['year'] = (int) $y[1];
            $p['pub_date'] = $y[1];
        }
        if (preg_match('#SOURCE: </b>(.*?)(?:<b>VOLUME|</small>)#s', $chunk, $s)) {
            $venue = preg_split('#<b>in</b>#', $s[1])[0];
            $p['venue'] = research_title_case(research_clean($venue));
        }
        $idx = [];
        foreach (['Scopus' => 'Scopus', 'WOS' => 'WoS'] as $k => $v) {
            if (preg_match('#<span class="citText">' . $k . '</span>#i', explode('IN MY:', explode('INDEXED IN:', $chunk)[1] ?? '')[0])) {
                $idx[] = $v;
            }
        }
        $p['indexed'] = implode(',', $idx);
        $out[] = $p;
    }
    return $out;
}

/* ---------- Sincronização ---------- */

function research_configured(): bool
{
    return research_orcid_id() !== '' || research_authenticus_id() !== '';
}

/** Sincroniza as duas fontes. Devolve o resumo (também guardado em research_sync_log). */
function research_sync(bool $force = false): string
{
    if (!research_configured()) {
        return 'Indique o ORCID iD ou o Authenticus em Investigação.';
    }
    if (!$force && (int) setting('research_sync_at') > time() - RESEARCH_SYNC_EVERY) {
        return '';
    }
    setting_set('research_sync_at', (string) time()); // antes dos pedidos: evita sincronizações em paralelo
    @set_time_limit(300);
    $log = [];
    $ok = true;
    if ($orcid = research_orcid_id()) {
        $r = orcid_sync($orcid);
        $ok = $ok && is_array($r);
        $log[] = is_array($r) ? "ORCID: {$r[0]} publicação(ões) e {$r[1]} projeto(s) novos" : "ORCID: $r";
    }
    if ($auth = research_authenticus_id()) {
        $r = authenticus_sync($auth);
        $ok = $ok && is_int($r);
        $log[] = is_int($r) ? "Authenticus: $r publicação(ões) nova(s)" : "Authenticus: $r";
    }
    $msg = implode(' · ', $log) . '.';
    setting_set('research_sync_log', date('d/m/Y H:i') . ' — ' . $msg);
    setting_set('research_sync_ok', $ok ? '1' : '0');
    return $msg;
}

function research_sync_in_background(): void
{
    if (!research_configured() || (int) setting('research_sync_at') > time() - RESEARCH_SYNC_EVERY) {
        return;
    }
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        try {
            research_sync();
        } catch (Throwable $e) {
            error_log('Investigação: ' . $e->getMessage());
        }
    });
}

/* ---------- Leitura para o site ---------- */

function research_title(): string
{
    return setting('research_title') ?: t('Investigação');
}

function has_research(): bool
{
    return (bool) db()->query('SELECT (SELECT 1 FROM publications WHERE visible = 1 LIMIT 1) OR (SELECT 1 FROM projects WHERE visible = 1 LIMIT 1)')->fetchColumn();
}

function pub_type_label(string $type): string
{
    return t(PUB_TYPES[$type] ?? 'Outros');
}

/** Autores com o nome do próprio a negrito (variantes em research_names). Devolve HTML seguro. */
function research_authors_html(string $authors): string
{
    $html = e($authors);
    $names = setting_lines('research_names');
    usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($names as $n) {
        $q = preg_quote(e($n), '/');
        $new = preg_replace('/(?<![\p{L}>])(' . $q . ')(?![\p{L}<])/iu', '<b>$1</b>', $html, 1, $count);
        if ($count && $new !== null) {
            return $new;
        }
    }
    return $html;
}

/** Projeto em curso (sem data de fim, ou fim no futuro). */
function project_ongoing(array $p): bool
{
    $end = $p['end_date'];
    $end .= match (strlen($end)) { 4 => '-12-31', 7 => '-31', default => '' };
    return $end === '' || $end >= date('Y-m-d');
}

function project_years(array $p): string
{
    $a = substr($p['start_date'], 0, 4);
    $b = substr($p['end_date'], 0, 4);
    if ($a !== '' && $b !== '') {
        return $a === $b ? $a : "{$a}–{$b}";
    }
    return $a !== '' ? "{$a}–" : $b;
}

function pub_link(array $p): string
{
    return $p['doi'] !== '' ? 'https://doi.org/' . $p['doi'] : (string) $p['url'];
}
