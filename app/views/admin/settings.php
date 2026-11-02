<?php
$field = function (string $name, string $label, string $placeholder = '', string $type = 'text', string $hint = '') {
    echo '<label>' . e($label) . ' <input type="' . $type . '" name="' . $name . '" value="' . e(setting($name)) . '" placeholder="' . e($placeholder) . '"></label>';
    if ($hint) {
        echo '<p class="hint">' . $hint . '</p>';
    }
};
$area = function (string $name, string $label, string $placeholder = '', string $hint = '') {
    echo '<label>' . e($label) . ' <textarea name="' . $name . '" rows="3" placeholder="' . e($placeholder) . '">' . e(setting($name)) . '</textarea></label>';
    if ($hint) {
        echo '<p class="hint">' . $hint . '</p>';
    }
};
?>
<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="head">
        <h1>Definições</h1>
        <button class="btn primary">Guardar</button>
    </div>

    <div class="settings-grid">
        <section class="card">
            <h2>Site</h2>
            <?php $field('site_name', 'Nome do site', 'Filipe Santos'); ?>
            <?php $field('site_tagline', 'Subtítulo (aparece no título da página inicial)', 'Investigador em …'); ?>
            <label>Descrição do site (meta description por omissão)
                <textarea name="site_description" rows="3" data-count="160"><?= e(setting('site_description')) ?></textarea>
            </label>
            <?php $field('blog_title', 'Nome da secção de artigos', 'Blog'); ?>
            <?php $field('site_lang', 'Idioma do site (pt-PT ou en)', 'en', 'text', 'Muda o idioma dos textos fixos do site (menu, datas, botões).'); ?>
            <?php $field('footer_text', 'Texto do rodapé', '© 2026 Filipe Santos'); ?>
            <?php $area('footer_links', 'Links no rodapé (um por linha: Nome | URL)', "QiVolt | https://qivolt.com/"); ?>
        </section>

        <section class="card">
            <h2>Sobre si</h2>
            <p class="hint">Usado nos dados estruturados <code>Person</code> que o Google lê para o painel de conhecimento.</p>
            <?php $field('author_name', 'Nome', 'Filipe Santos'); ?>
            <?php $field('author_job', 'Cargo (resumo para o Google)', 'ex.: Assistant Professor at FEUP'); ?>
            <?php $area('author_headline', 'Cargos no topo da página inicial (um por linha)'); ?>
            <?php $field('author_tagline', 'Frase de destaque', 'Field Robotics for Agriculture and Forestry — …'); ?>
            <?php $area('author_works_for', 'Onde trabalha (uma instituição por linha)', '', 'Vai para os dados estruturados <code>worksFor</code>.'); ?>
            <?php $area('author_alumni_of', 'Onde estudou (uma instituição por linha)', '', 'Vai para <code>alumniOf</code>.'); ?>
            <?php $field('author_knows_about', 'Áreas de especialidade (separadas por vírgulas)', 'Field robotics, Precision agriculture'); ?>
            <?php $field('contact_email', 'Email público', '', 'email'); ?>
            <label>Fotografia</label>
            <?php if (setting('author_image')): ?><img class="thumb round" src="<?= e(setting('author_image')) ?>" alt=""><?php endif; ?>
            <?php $field('author_image', 'URL', '/uploads/…'); ?>
            <label>…ou enviar nova <input type="file" name="author_image_file" accept="image/*"></label>
        </section>

        <section class="card">
            <h2>Perfis (sameAs)</h2>
            <?php $field('social_linkedin', 'LinkedIn', 'https://www.linkedin.com/in/…', 'url'); ?>
            <?php $field('social_github', 'GitHub', 'https://github.com/…', 'url'); ?>
            <?php $field('social_x', 'X / Twitter', 'https://x.com/…', 'url'); ?>
            <?php $field('social_youtube', 'YouTube', 'https://www.youtube.com/@…', 'url'); ?>
            <?php $field('social_instagram', 'Instagram', 'https://www.instagram.com/…', 'url'); ?>
            <?php $field('social_scholar', 'Google Scholar', 'https://scholar.google.com/citations?user=…', 'url'); ?>
            <?php $field('social_orcid', 'ORCID', 'https://orcid.org/0000-…', 'url'); ?>
            <?php $area('profile_links', 'Outros perfis (um por linha: Nome | URL)', 'Scopus | https://www.scopus.com/…', 'Aparecem no topo da página inicial e ligam o site à sua identidade no Google.'); ?>
        </section>

        <section class="card">
            <h2>Google e motores de pesquisa</h2>
            <label class="check big">
                <input type="checkbox" name="allow_indexing" <?= indexing_allowed() ? 'checked' : '' ?>>
                Permitir indexação (robots.txt e meta robots)
            </label>
            <p class="hint">Desligue enquanto o site está em construção.</p>
            <label class="check">
                <input type="checkbox" name="indexnow_enabled" <?= setting('indexnow_enabled', '1') === '1' ? 'checked' : '' ?>>
                Avisar o Bing/Yandex (IndexNow) sempre que publico
            </label>
            <p class="hint">O Google não usa IndexNow: descobre as alterações pelo sitemap.</p>
            <?php $field('google_site_verification', 'Código de verificação Google Search Console', 'cole a meta tag ou só o código', 'text',
                'Search Console → Adicionar propriedade → Prefixo do URL → Etiqueta HTML.'); ?>
            <?php $field('bing_site_verification', 'Código de verificação Bing Webmaster', 'msvalidate.01'); ?>
            <?php $field('google_analytics_id', 'Google Analytics (opcional)', 'G-XXXXXXX'); ?>
            <label>Imagem de partilha por omissão (Open Graph, 1200×630)</label>
            <?php if (setting('default_image')): ?><img class="thumb" src="<?= e(setting('default_image')) ?>" alt=""><?php endif; ?>
            <?php $field('default_image', 'URL', '/uploads/…'); ?>
            <label>…ou enviar nova <input type="file" name="default_image_file" accept="image/*"></label>
            <p class="hint">Gerados automaticamente: <a href="/robots.txt" target="_blank">robots.txt</a> ·
                <a href="/sitemap.xml" target="_blank">sitemap.xml</a> · <a href="/feed.xml" target="_blank">feed.xml</a> ·
                <a href="/llms.txt" target="_blank">llms.txt</a></p>
        </section>
    </div>
</form>
