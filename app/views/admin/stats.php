<?php
/** @var array $r relatório de stats_report() */
$n = fn(int|float $v) => number_format((float) $v, 0, ',', ' ');
$periodLabel = $r['days'] === 365 ? '12 meses' : $r['days'] . ' dias';
$change = function (int $now, int $prev) use ($periodLabel): string {
    if ($prev === 0) {
        return $now > 0 ? '<span class="muted">sem dados no período anterior</span>' : '';
    }
    $pct = (int) round(($now - $prev) / $prev * 100);
    $sign = $pct > 0 ? '+' : ($pct < 0 ? '−' : '');
    return '<span class="muted">' . $sign . abs($pct) . '% vs. ' . $periodLabel . ' anteriores</span>';
};
$hasIntl = class_exists('Locale');
$country = fn(string $c) => $c === '?' ? 'Desconhecido' : ($hasIntl ? (Locale::getDisplayRegion('und_' . $c, 'pt_PT') ?: $c) : $c);
$language = fn(string $l) => $l === '?' ? 'Desconhecido' : ($hasIntl ? (Locale::getDisplayLanguage($l, 'pt_PT') ?: $l) : $l);
$shortDate = fn(string $d) => date('j/n', strtotime($d));

// Mais de 90 dias: o gráfico mostra semanas para as barras não ficarem finas demais.
$bars = [];
if ($r['days'] > 90) {
    foreach (array_chunk($r['series'], 7) as $week) {
        $bars[] = [
            'label' => $shortDate($week[0]['day']) . '–' . $shortDate(end($week)['day']),
            'views' => array_sum(array_column($week, 'views')),
            'visitors' => array_sum(array_column($week, 'visitors')),
        ];
    }
    $unit = 'semana';
} else {
    foreach ($r['series'] as $d) {
        $bars[] = ['label' => format_date($d['day']), 'views' => $d['views'], 'visitors' => $d['visitors']];
    }
    $unit = 'dia';
}
$max = max(1, ...array_column($bars, 'views'));

/** Tabela com barra de proporção (uma só cor: é magnitude, não identidade). */
$list = function (string $title, array $rows, ?callable $label = null, string $empty = 'Sem dados neste período.', bool $visitors = true) use ($n): void {
    $top = max(1, ...array_map(fn($x) => (int) $x['views'], $rows ?: [['views' => 1]]));
    ?>
    <section class="card stats-list">
        <h2><?= e($title) ?></h2>
        <?php if (!$rows): ?>
            <p class="muted"><?= e($empty) ?></p>
        <?php else: ?>
            <table>
                <thead><tr><th></th><th class="num">Vis.</th><?php if ($visitors): ?><th class="num">Visitantes</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <div class="stats-label"><?= $label ? $label($row) : e((string) $row['label']) ?></div>
                            <div class="stats-meter" aria-hidden="true"><span style="width: <?= round((int) $row['views'] / $top * 100, 1) ?>%"></span></div>
                        </td>
                        <td class="num"><?= $n((int) $row['views']) ?></td>
                        <?php if ($visitors): ?><td class="num muted"><?= $n((int) ($row['visitors'] ?? 0)) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
};
?>
<div class="head">
    <h1>Estatísticas</h1>
    <nav class="stats-range" aria-label="Período">
        <?php foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '12 meses'] as $d => $lbl): ?>
            <a class="btn small<?= $r['days'] === $d ? ' primary' : '' ?>" href="/admin/stats?dias=<?= $d ?>"<?= $r['days'] === $d ? ' aria-current="page"' : '' ?>><?= $lbl ?></a>
        <?php endforeach; ?>
    </nav>
</div>
<p class="muted">Contagem feita pelo próprio site: sem cookies, sem serviços externos e sem guardar IPs. Robôs e as suas próprias
    visitas (com sessão no backoffice) não contam. Os dados com mais de 13 meses são apagados.</p>

<div class="stats">
    <div class="stat stat-tile">
        <b><?= $n($r['views']) ?></b><span>visualizações</span>
        <?= $change($r['views'], $r['prev_views']) ?>
    </div>
    <div class="stat stat-tile">
        <b><?= $n($r['visitors']) ?></b><span>visitantes únicos <small class="muted">(soma diária)</small></span>
        <?= $change($r['visitors'], $r['prev_visitors']) ?>
    </div>
    <div class="stat stat-tile">
        <b><?= $n($r['entries']) ?></b><span>entradas no site</span>
        <span class="muted">vindas de fora ou diretas</span>
    </div>
    <div class="stat stat-tile">
        <b><?= $r['visitors'] ? number_format($r['views'] / $r['visitors'], 1, ',', '') : '0' ?></b><span>páginas por visitante</span>
    </div>
</div>

<section class="card">
    <h2>Visualizações por <?= $unit ?></h2>
    <?php if (!$r['views']): ?>
        <p class="muted">Ainda não há visitas registadas neste período. As visitas começam a contar a partir de agora.</p>
    <?php endif; ?>
    <div class="stats-chart" role="img" aria-label="Gráfico de barras: visualizações por <?= $unit ?>, máximo <?= $n($max) ?>">
        <div class="stats-yaxis" aria-hidden="true"><span><?= $n($max) ?></span><span>0</span></div>
        <div class="stats-bars">
            <?php foreach ($bars as $b): ?>
                <div class="stats-bar" tabindex="0">
                    <span class="stats-fill" style="height: <?= $b['views'] ? max(1.5, round($b['views'] / $max * 100, 1)) : 0 ?>%"></span>
                    <span class="stats-tip" role="tooltip"><strong><?= e($b['label']) ?></strong><br><?= $n($b['views']) ?> visualizações<br><?= $n($b['visitors']) ?> visitantes</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="stats-xaxis muted" aria-hidden="true">
        <span><?= e($bars[0]['label']) ?></span><span><?= e(end($bars)['label']) ?></span>
    </div>
    <details class="stats-table">
        <summary>Ver dados em tabela</summary>
        <table>
            <thead><tr><th><?= ucfirst($unit) ?></th><th class="num">Visualizações</th><th class="num">Visitantes</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($bars) as $b): ?>
                <tr><td><?= e($b['label']) ?></td><td class="num"><?= $n($b['views']) ?></td><td class="num"><?= $n($b['visitors']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</section>

<div class="stats-grid">
    <?php
    $list('Páginas mais vistas', $r['pages'], fn($row) => ($row['title'] ? e($row['title']) . ' ' : '')
        . '<a class="muted small" href="' . e($row['label']) . '" target="_blank">' . e($row['label']) . '</a>');
    $list('Como chegam ao site', $r['channels']);
    $list('Sites de origem', $r['sources'], null, 'Ainda sem visitas vindas de outros sites.');
    $list('Países', $r['countries'], fn($row) => e($country((string) $row['label'])));
    $list('Idioma do navegador', $r['languages'], fn($row) => e($language((string) $row['label'])));
    $list('Dispositivos', $r['devices']);
    $list('Sistemas operativos', $r['os']);
    $list('Navegadores', $r['browsers']);
    $list('Robôs e assistentes de IA (pedidos)', $r['bots'], null, 'Nenhum robô registado neste período.', false);
    $list('Páginas não encontradas (404)', $r['not_found'], fn($row) => '<code>' . e((string) $row['label']) . '</code>',
        'Nenhum erro 404 neste período.');
    ?>
</div>
<?php if ($r['countries'] && count($r['countries']) === 1 && $r['countries'][0]['label'] === '?'): ?>
    <p class="muted small">O país só é conhecido se o site estiver atrás da Cloudflare (ou se o alojamento tiver GeoIP).
        Até lá, o idioma do navegador é uma boa aproximação da origem geográfica.</p>
<?php endif; ?>
