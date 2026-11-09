<?php
$byYear = [];
foreach ($tab === 'pubs' ? $items : [] as $p) {
    $byYear[$p['year'] ?: t('Sem data')][] = $p;
}
?>
<header class="page-head">
    <div class="wrap-wide">
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/"><?= e(t('Início')) ?></a></nav>
        <h1><?= e($title) ?></h1>
        <dl class="rs-stats">
            <?php if ($summary['pubs']): ?><div><dt><?= e(t('Publicações')) ?></dt><dd><?= $summary['pubs'] ?></dd></div><?php endif; ?>
            <?php if ($summary['articles']): ?><div><dt><?= e(t('Artigos em revista')) ?></dt><dd><?= $summary['articles'] ?></dd></div><?php endif; ?>
            <?php if ($summary['indexed']): ?><div><dt><?= e(t('Indexadas Scopus / WoS')) ?></dt><dd><?= $summary['indexed'] ?></dd></div><?php endif; ?>
            <?php if ($summary['projects']): ?><div><dt><?= e(t('Projetos')) ?></dt><dd><?= $summary['projects'] ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($summary['pubs'] && $summary['projects']): ?>
            <nav class="hl-filter rs-tabs" aria-label="<?= e($title) ?>">
                <a href="/research"<?= $tab === 'pubs' ? ' aria-current="page"' : '' ?>><?= e(t('Publicações')) ?></a>
                <a href="/research/projects"<?= $tab === 'projects' ? ' aria-current="page"' : '' ?>><?= e(t('Projetos')) ?></a>
            </nav>
        <?php endif; ?>
    </div>
</header>

<div class="wrap-wide rs">
<?php if ($tab === 'pubs'): ?>
    <?php if ($featured): ?>
        <h2 class="rs-h"><?= e(t('Publicações em destaque')) ?></h2>
        <ul class="rs-featured">
            <?php foreach ($featured as $p): $href = pub_link($p); ?>
                <li>
                    <p class="rs-kicker"><?= e(pub_type_label($p['type'])) ?><?= $p['year'] ? ' · ' . (int) $p['year'] : '' ?></p>
                    <h3><?php if ($href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= e($p['title']) ?></a><?php else: ?><?= e($p['title']) ?><?php endif; ?></h3>
                    <?php if ($p['authors']): ?><p class="rs-authors"><?= research_authors_html($p['authors']) ?></p><?php endif; ?>
                    <?php if ($p['venue']): ?><p class="rs-venue"><?= e($p['venue']) ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="rs-tools">
        <?php if (count($types) > 1): ?>
            <nav class="hl-filter" aria-label="<?= e(t('Filtrar')) ?>">
                <a href="/research"<?= $type === '' ? ' aria-current="page"' : '' ?>><?= e(t('Tudo')) ?></a>
                <?php foreach (PUB_TYPES as $k => $lbl): if (empty($types[$k])) continue; ?>
                    <a href="/research?tipo=<?= $k ?>"<?= $type === $k ? ' aria-current="page"' : '' ?>><?= e(t($lbl)) ?> <span class="rs-n"><?= (int) $types[$k] ?></span></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <label class="rs-search">
            <span class="sr-only"><?= e(t('Pesquisar')) ?></span>
            <input type="search" placeholder="<?= e(t('Pesquisar título, autor, revista…')) ?>" data-rs-search hidden>
        </label>
    </div>
    <p class="muted rs-empty" hidden><?= e(t('Nenhum resultado.')) ?></p>

    <?php foreach ($byYear as $year => $pubs): ?>
        <section class="rs-year" data-rs-group>
            <h2><?= e((string) $year) ?> <span class="rs-n"><?= count($pubs) ?></span></h2>
            <ol class="rs-list">
                <?php foreach ($pubs as $p): $href = pub_link($p); ?>
                    <li data-rs-item>
                        <p class="rs-title">
                            <?php if ($href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= e($p['title']) ?></a><?php else: ?><?= e($p['title']) ?><?php endif; ?>
                        </p>
                        <?php if ($p['authors']): ?><p class="rs-authors"><?= research_authors_html($p['authors']) ?></p><?php endif; ?>
                        <p class="rs-meta">
                            <span class="rs-type"><?= e(pub_type_label($p['type'])) ?></span>
                            <?php if ($p['venue']): ?><span class="rs-venue"><?= e($p['venue']) ?></span><?php endif; ?>
                            <?php foreach (array_filter(explode(',', $p['indexed'])) as $ix): ?><span class="rs-badge"><?= e($ix) ?></span><?php endforeach; ?>
                            <?php if ($p['doi']): ?><a class="rs-doi" href="https://doi.org/<?= e($p['doi']) ?>" target="_blank" rel="noopener">DOI</a><?php endif; ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endforeach; ?>
<?php else: ?>
    <ul class="rs-projects">
        <?php foreach ($items as $p):
            $split = project_split_title($p['title']);
            $acr = $p['acronym'] ?: $split[0];
            $name = $split[0] !== '' && $split[0] === $acr ? $split[1] : $p['title'];
            $ongoing = project_ongoing($p);
            ?>
            <li class="rs-project<?= $p['featured'] ? ' is-featured' : '' ?>">
                <p class="rs-kicker">
                    <span class="rs-status<?= $ongoing ? ' is-on' : '' ?>"><?= e($ongoing ? t('Em curso') : t('Concluído')) ?></span>
                    <?php if ($years = project_years($p)): ?><span><?= e($years) ?></span><?php endif; ?>
                </p>
                <h3>
                    <?php if ($acr): ?><span class="rs-acr"><?= e($acr) ?></span><?php endif; ?>
                    <?php if ($p['url']): ?><a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><?= e($name) ?></a><?php else: ?><?= e($name) ?><?php endif; ?>
                </h3>
                <?php if ($p['description']): ?><p><?= e($p['description']) ?></p><?php endif; ?>
                <p class="rs-meta">
                    <?php if ($p['funder']): ?><span><?= e(t('Financiamento:')) ?> <?= e($p['funder']) ?></span><?php endif; ?>
                    <?php if ($p['role']): ?><span><?= e($p['role']) ?></span><?php endif; ?>
                    <?php if ($p['grant_number']): ?><span class="muted"><?= e($p['grant_number']) ?></span><?php endif; ?>
                </p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

    <p class="rs-sources muted small">
        <?= e(t('Fontes:')) ?>
        <?php if ($o = research_orcid_id()): ?><a href="https://orcid.org/<?= e($o) ?>" target="_blank" rel="me noopener">ORCID</a><?php endif; ?>
        <?php if ($a = research_authenticus_id()): ?> · <a href="<?= AUTHENTICUS ?>/en/profileOfResearchers/publicationsList/<?= e($a) ?>" target="_blank" rel="noopener">Authenticus</a><?php endif; ?>
    </p>
</div>
<?php if ($tab === 'pubs'): ?>
<script>
(() => {
    const input = document.querySelector('[data-rs-search]');
    if (!input) return;
    input.hidden = false;
    const items = [...document.querySelectorAll('[data-rs-item]')].map(li => [li, li.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')]);
    const empty = document.querySelector('.rs-empty');
    input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        let shown = 0;
        items.forEach(([li, text]) => { const ok = !q || q.split(/\s+/).every(w => text.includes(w)); li.hidden = !ok; shown += ok; });
        document.querySelectorAll('[data-rs-group]').forEach(g => { g.hidden = !g.querySelector('[data-rs-item]:not([hidden])'); });
        empty.hidden = shown > 0;
    });
})();
</script>
<?php endif; ?>
