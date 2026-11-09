<?php
/**
 * Impacto das publicações.
 *
 * - Citações, FWCI (impacto normalizado por área: 1 = média mundial) e percentil de citações: OpenAlex, pelo DOI.
 *   API aberta; uma chave gratuita (opcional) dá orçamento diário próprio.
 * - Revista/série de cada publicação (ISSN-L) e o seu "impacto a 2 anos" (2yr mean citedness do OpenAlex:
 *   citações médias dos artigos dos 2 anos anteriores — a mesma fórmula do Impact Factor, com dados abertos).
 * - Quartil: SJR (Scimago), importado do ficheiro que a Scimago disponibiliza para download (uma vez por ano).
 * - Impact Factor e quartil JCR (Clarivate) não têm fonte aberta: podem ser escritos à mão por revista,
 *   e quando existem têm prioridade sobre o SJR / OpenAlex.
 */
declare(strict_types=1);

const OPENALEX = 'https://api.openalex.org';

function openalex_get(string $path, array $query): ?array
{
    if ($key = setting('openalex_key')) {
        $query['api_key'] = $key;
    }
    $r = http_request('GET', OPENALEX . $path . '?' . http_build_query($query), ['Accept: application/json'], null, 30, 10_000_000);
    if ($r['status'] !== 200) {
        return null;
    }
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : null;
}

function issn_compact(string $issn): string
{
    return strtoupper(preg_replace('/[^0-9Xx]/', '', $issn) ?? '');
}

/** Atualiza citações e revistas a partir do OpenAlex. Devolve o n.º de publicações encontradas ou uma mensagem de erro. */
function impact_sync(): int|string
{
    $pubs = db()->query("SELECT id, doi FROM publications WHERE doi <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$pubs) {
        return 0;
    }
    $byDoi = array_flip(array_map('strtolower', $pubs));
    $update = db()->prepare('UPDATE publications SET citations = ?, fwci = ?, percentile = ?, issn = CASE WHEN ? <> \'\' THEN ? ELSE issn END,
        openalex_id = ?, metrics_at = ? WHERE id = ?');
    $sources = [];
    $found = 0;
    foreach (array_chunk(array_values($pubs), 50) as $chunk) {
        $d = openalex_get('/works', [
            'filter' => 'doi:' . implode('|', $chunk),
            'select' => 'id,doi,cited_by_count,fwci,citation_normalized_percentile,primary_location',
            'per_page' => 50,
        ]);
        if ($d === null) {
            return $found ? $found : 'O OpenAlex não respondeu (pode ter esgotado o limite diário sem chave).';
        }
        foreach ($d['results'] ?? [] as $w) {
            $doi = strtolower(preg_replace('#^https?://doi\.org/#i', '', (string) ($w['doi'] ?? '')));
            if (!isset($byDoi[$doi])) {
                continue;
            }
            $src = $w['primary_location']['source'] ?? null;
            $issn = $src && !empty($src['issn_l']) ? issn_compact($src['issn_l']) : '';
            if ($issn !== '') {
                $sources[$issn] = $src;
            }
            $update->execute([
                (int) ($w['cited_by_count'] ?? 0),
                isset($w['fwci']) ? (float) $w['fwci'] : null,
                isset($w['citation_normalized_percentile']['value']) ? (float) $w['citation_normalized_percentile']['value'] : null,
                $issn, $issn, basename((string) ($w['id'] ?? '')), now(), $byDoi[$doi],
            ]);
            $found++;
        }
        usleep(150_000);
    }
    journals_sync($sources);
    return $found;
}

/** Guarda as revistas e o seu impacto a 2 anos / índice h (OpenAlex). */
function journals_sync(array $sources): void
{
    $upsert = db()->prepare("INSERT INTO journals (issn, name, kind, issns, openalex_id, updated_at) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(issn) DO UPDATE SET name = excluded.name, kind = excluded.kind, issns = excluded.issns,
        openalex_id = excluded.openalex_id, updated_at = excluded.updated_at");
    foreach ($sources as $issn => $s) {
        $all = array_unique(array_filter(array_map('issn_compact', array_merge([$s['issn_l'] ?? ''], (array) ($s['issn'] ?? [])))));
        $upsert->execute([$issn, (string) ($s['display_name'] ?? ''), (string) ($s['type'] ?? ''), implode(',', $all), basename((string) ($s['id'] ?? '')), now()]);
    }
    $ids = db()->query("SELECT openalex_id FROM journals WHERE openalex_id <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $set = db()->prepare('UPDATE journals SET mean2y = ?, h_index = ? WHERE openalex_id = ?');
    foreach (array_chunk($ids, 50) as $chunk) {
        $d = openalex_get('/sources', ['filter' => 'openalex:' . implode('|', $chunk), 'select' => 'id,summary_stats', 'per_page' => 50]);
        foreach ($d['results'] ?? [] as $s) {
            $st = $s['summary_stats'] ?? [];
            $set->execute([
                isset($st['2yr_mean_citedness']) ? round((float) $st['2yr_mean_citedness'], 3) : null,
                isset($st['h_index']) ? (int) $st['h_index'] : null,
                basename((string) $s['id']),
            ]);
        }
    }
    sjr_apply();
}

/* ---------- SJR (Scimago) ---------- */

/**
 * Importa o ficheiro "Download data" de scimagojr.com/journalrank.php (CSV separado por ";").
 * Devolve [n.º de revistas lidas, ano] ou uma mensagem de erro.
 */
function sjr_import(string $file): array|string
{
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return 'Não foi possível abrir o ficheiro.';
    }
    $head = fgetcsv($fh, 0, ';');
    if (!$head) {
        return 'Ficheiro vazio.';
    }
    $head = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $head);
    $col = array_flip($head);
    foreach (['Title', 'Issn', 'SJR', 'SJR Best Quartile'] as $need) {
        if (!isset($col[$need])) {
            fclose($fh);
            return 'Não parece o ficheiro da Scimago (falta a coluna "' . $need . '"). Use o botão "Download data" em scimagojr.com.';
        }
    }
    $year = 0;
    foreach ($head as $h) {
        if (preg_match('/\((\d{4})\)/', $h, $m)) {
            $year = (int) $m[1];
            break;
        }
    }
    $hCol = $col['H index'] ?? null;
    db()->beginTransaction();
    db()->exec('DELETE FROM sjr');
    $ins = db()->prepare('INSERT OR REPLACE INTO sjr (issn, title, sjr, quartile, h_index, year) VALUES (?, ?, ?, ?, ?, ?)');
    $n = 0;
    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $issns = array_filter(array_map('issn_compact', explode(',', (string) ($row[$col['Issn']] ?? ''))));
        if (!$issns) {
            continue;
        }
        $q = strtoupper(trim((string) ($row[$col['SJR Best Quartile']] ?? '')));
        $sjr = (string) ($row[$col['SJR']] ?? '');
        foreach ($issns as $issn) {
            $ins->execute([
                $issn, mb_substr((string) $row[$col['Title']], 0, 300),
                $sjr !== '' ? (float) str_replace(',', '.', $sjr) : null,
                preg_match('/^Q[1-4]$/', $q) ? $q : '',
                $hCol !== null && is_numeric($row[$hCol] ?? '') ? (int) $row[$hCol] : null,
                $year ?: null,
            ]);
        }
        $n++;
    }
    fclose($fh);
    if ($n < 100) {
        db()->rollBack(); // mantém o SJR anterior
        return "Só foram lidas $n revistas: o ficheiro parece incompleto.";
    }
    db()->commit();
    setting_set('sjr_year', (string) $year);
    setting_set('sjr_imported_at', now());
    sjr_apply();
    return [$n, $year];
}

/** Liga cada revista ao SJR por qualquer um dos seus ISSN. */
function sjr_apply(): void
{
    $find = db()->prepare('SELECT * FROM sjr WHERE issn = ?');
    $set = db()->prepare('UPDATE journals SET sjr = ?, sjr_quartile = ?, sjr_year = ? WHERE issn = ?');
    foreach (db()->query('SELECT issn, issns FROM journals')->fetchAll() as $j) {
        $hit = null;
        foreach (array_filter(explode(',', $j['issns'] . ',' . $j['issn'])) as $issn) {
            $find->execute([$issn]);
            if ($hit = $find->fetch()) {
                break;
            }
        }
        $set->execute([$hit['sjr'] ?? null, $hit['quartile'] ?? '', $hit['year'] ?? null, $j['issn']]);
    }
}

/* ---------- Leitura ---------- */

/** Métricas da revista de uma publicação: quartil e fator de impacto preferidos (JCR manual > SJR / OpenAlex). */
function journal_metrics(string $issn): ?array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT * FROM journals') as $j) {
            $all[$j['issn']] = $j;
        }
    }
    $j = $all[$issn] ?? null;
    if (!$j) {
        return null;
    }
    $j['quartile'] = $j['jif_quartile'] ?: $j['sjr_quartile'];
    $j['quartile_src'] = $j['jif_quartile'] ? 'JCR' . ($j['jif_year'] ? ' ' . $j['jif_year'] : '') : ($j['sjr_quartile'] ? 'SJR' . ($j['sjr_year'] ? ' ' . $j['sjr_year'] : '') : '');
    return $j;
}

/** Totais do autor: citações, índice h, i10 e publicações em Q1. */
function research_impact_summary(): array
{
    $cites = db()->query('SELECT COALESCE(citations, 0) FROM publications WHERE visible = 1 ORDER BY citations DESC')->fetchAll(PDO::FETCH_COLUMN);
    $h = 0;
    foreach ($cites as $i => $c) {
        if ((int) $c >= $i + 1) {
            $h = $i + 1;
        }
    }
    $q1 = 0;
    foreach (db()->query("SELECT issn FROM publications WHERE visible = 1 AND issn <> ''")->fetchAll(PDO::FETCH_COLUMN) as $issn) {
        $q1 += (journal_metrics($issn)['quartile'] ?? '') === 'Q1' ? 1 : 0;
    }
    return [
        'citations' => array_sum(array_map('intval', $cites)),
        'h' => $h,
        'i10' => count(array_filter($cites, fn($c) => (int) $c >= 10)),
        'q1' => $q1,
        'has' => (bool) array_filter($cites),
    ];
}

function format_metric(?float $v, int $dec = 1): string
{
    if ($v === null) {
        return '';
    }
    return site_is_pt() ? number_format($v, $dec, ',', ' ') : number_format($v, $dec, '.', ',');
}

/** Selos de impacto de uma publicação (quartil, fator de impacto, citações, top %). HTML seguro. */
function pub_impact_html(array $p): string
{
    $out = [];
    $j = $p['issn'] ? journal_metrics($p['issn']) : null;
    if ($j && $j['quartile']) {
        $out[] = '<span class="q q' . substr($j['quartile'], 1) . '" title="' . e(t('Quartil da revista') . ' (' . $j['quartile_src'] . ')') . '">'
            . e($j['quartile']) . '</span>';
    }
    if ($j && $j['jif'] !== null) {
        $out[] = '<span class="rs-if" title="' . e('Journal Impact Factor (JCR' . ($j['jif_year'] ? ' ' . $j['jif_year'] : '') . ')') . '">IF '
            . e(format_metric((float) $j['jif'])) . '</span>';
    } elseif ($j && $j['mean2y'] !== null && ($j['kind'] === 'journal' || $j['kind'] === '')) {
        $out[] = '<span class="rs-if" title="' . e(t('Citações médias por artigo nos 2 anos anteriores (OpenAlex), equivalente aberto ao Impact Factor')) . '">'
            . e(t('Impacto')) . ' ' . e(format_metric((float) $j['mean2y'])) . '</span>';
    }
    if ($p['citations'] !== null && (int) $p['citations'] > 0) {
        $n = (int) $p['citations'];
        $out[] = '<span class="rs-cites" title="' . e(t('Citações (OpenAlex)')) . '">' . e($n === 1 ? t('1 citação') : t('%d citações', $n)) . '</span>';
    }
    if ($p['percentile'] !== null && (float) $p['percentile'] >= 0.9) {
        $top = (float) $p['percentile'] >= 0.99 ? '1%' : '10%';
        $out[] = '<span class="rs-top" title="' . e(t('Entre as %s publicações mais citadas da área e do ano (OpenAlex)', $top)) . '">Top ' . $top . '</span>';
    }
    return implode(' ', $out);
}
