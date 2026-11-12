(function () {
    'use strict';
    const csrf = document.querySelector('meta[name="csrf"]')?.content || '';

    // Confirmação antes de ações destrutivas.
    document.addEventListener('click', (ev) => {
        const el = ev.target.closest('[data-confirm]');
        if (el && !confirm(el.dataset.confirm)) ev.preventDefault();
    });

    // Copiar URL na biblioteca de média.
    document.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-copy]');
        if (!btn) return;
        try {
            await navigator.clipboard.writeText(btn.dataset.copy);
            const old = btn.textContent;
            btn.textContent = 'Copiado!';
            setTimeout(() => (btn.textContent = old), 1200);
        } catch (_) {
            prompt('Copie o URL:', btn.dataset.copy);
        }
    });

    // Contadores de caracteres (meta descriptions).
    document.querySelectorAll('[data-count]').forEach((el) => {
        const ideal = +el.dataset.count;
        const out = document.createElement('span');
        out.className = 'counter';
        el.insertAdjacentElement('afterend', out);
        const upd = () => {
            const n = el.value.length;
            out.textContent = `${n} / ${ideal} caracteres`;
            out.classList.toggle('over', n > ideal);
        };
        el.addEventListener('input', upd);
        upd();
    });

    // Slug automático a partir do título + pré-visualização do resultado no Google.
    const title = document.querySelector('[data-slug-source]');
    const slug = document.querySelector('[data-slug]');
    const slugify = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
    if (title) {
        let manual = slug && slug.value !== '';
        slug?.addEventListener('input', () => (manual = slug.value !== ''));
        const serpTitle = document.querySelector('[data-serp-title]');
        const serpPath = document.querySelector('[data-serp-path]');
        const serpDesc = document.querySelector('[data-serp-desc]');
        const desc = document.querySelector('[name="meta_description"]');
        const siteName = serpTitle ? serpTitle.textContent.split(' — ').pop() : '';
        const sync = () => {
            if (slug && !manual) slug.value = slugify(title.value);
            if (serpTitle) serpTitle.textContent = (title.value || 'Título') + ' — ' + siteName;
            if (serpPath && slug) serpPath.textContent = (location.pathname.includes('/posts') ? '/blog/' : '/') + slug.value;
            if (serpDesc && desc) serpDesc.textContent = desc.value || 'A descrição aparece aqui (se vazia, o Google usa um excerto do texto)…';
        };
        title.addEventListener('input', sync);
        slug?.addEventListener('input', sync);
        desc?.addEventListener('input', sync);
    }

    // Editor visual (TinyMCE).
    if (window.tinymce && document.getElementById('content')) {
        const dark = matchMedia('(prefers-color-scheme: dark)').matches;
        tinymce.init({
            selector: '#content',
            license_key: 'gpl',
            language: 'pt_PT',
            language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@26.10.26/langs7/pt_PT.js',
            height: 640,
            menubar: false,
            branding: false,
            promotion: false,
            skin: dark ? 'oxide-dark' : 'oxide',
            content_css: window.EDITOR_CSS,
            body_class: 'prose',
            content_style: 'body{max-width:760px;margin:24px auto;padding:0 20px}',
            plugins: 'autolink link image media lists table code fullscreen wordcount autoresize searchreplace',
            toolbar: 'blocks styles | bold italic underline strikethrough | bullist numlist blockquote | link image media table | alignleft aligncenter alignright | removeformat code fullscreen',
            style_formats: [
                { title: 'Lista em cartões (destaques)', selector: 'ul', classes: 'highlights' },
                { title: 'Lista normal', selector: 'ul', classes: '' },
            ],
            block_formats: 'Parágrafo=p; Título 2=h2; Título 3=h3; Título 4=h4; Código=pre',
            autoresize_bottom_margin: 40,
            min_height: 500,
            relative_urls: false,
            remove_script_host: true,
            convert_urls: false,
            image_caption: true,
            image_advtab: false,
            image_dimensions: false,
            image_title: false,
            image_list: '/admin/media.json',
            automatic_uploads: true,
            images_reuse_filename: false,
            file_picker_types: 'image',
            paste_data_images: true,
            media_alt_source: false,
            media_poster: false,
            media_dimensions: false,
            link_default_target: '',
            link_assume_external_targets: 'https',
            images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
                const fd = new FormData();
                fd.append('file', blobInfo.blob(), blobInfo.filename());
                fd.append('csrf', csrf);
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '/admin/media/upload');
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.upload.onprogress = (e) => progress(e.loaded / e.total * 100);
                xhr.onload = () => {
                    let json = {};
                    try { json = JSON.parse(xhr.responseText); } catch (_) {}
                    if (xhr.status === 200 && json.location) resolve(json.location);
                    else reject({ message: json.error?.message || 'Erro ao enviar a imagem.', remove: true });
                };
                xhr.onerror = () => reject({ message: 'Erro de rede ao enviar a imagem.', remove: true });
                xhr.send(fd);
            }),
            // Botão "carregar" no diálogo de imagem.
            file_picker_callback: (cb) => {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.onchange = () => {
                    const file = input.files[0];
                    const reader = new FileReader();
                    reader.onload = () => {
                        const id = 'blobid' + Date.now();
                        const cache = tinymce.activeEditor.editorUpload.blobCache;
                        const blobInfo = cache.create(id, file, reader.result.split(',')[1]);
                        cache.add(blobInfo);
                        cb(blobInfo.blobUri(), { title: file.name.replace(/\.[^.]+$/, '') });
                    };
                    reader.readAsDataURL(file);
                };
                input.click();
            },
            setup: (ed) => {
                // Se ainda houver imagens coladas por enviar, envia-as antes de submeter.
                const form = document.getElementById('editor-form');
                form.addEventListener('submit', (ev) => {
                    ed.save();
                    if (!ed.getBody().querySelector('img[src^="blob:"], img[src^="data:"]')) return;
                    ev.preventDefault();
                    ed.uploadImages().then(() => {
                        ed.save();
                        HTMLFormElement.prototype.submit.call(form);
                    });
                });
                // Ctrl/Cmd+S guarda.
                ed.addShortcut('meta+s', 'Guardar', () => form.requestSubmit());
            },
        });
    }

    // Editor de Markdown (EasyMDE) dos documentos MD.
    const mdArea = document.getElementById('md-content');
    if (window.EasyMDE && mdArea) {
        const mde = new EasyMDE({
            element: mdArea,
            forceSync: true,
            spellChecker: false,
            autoDownloadFontAwesome: false, // carregado na vista, com SRI
            minHeight: '480px',
            status: ['lines', 'words', 'cursor'],
            placeholder: 'Escreva em Markdown…',
            toolbar: ['bold', 'italic', 'strikethrough', 'heading-2', 'heading-3', '|', 'quote', 'unordered-list', 'ordered-list',
                'table', 'code', 'link', 'horizontal-rule', '|', 'preview', 'side-by-side', 'fullscreen', '|', 'undo', 'redo', 'guide'],
            // O texto pode vir de assistentes de IA: a pré-visualização nunca executa HTML ativo.
            renderingConfig: { sanitizerFunction: (html) => window.DOMPurify ? DOMPurify.sanitize(html) : html.replace(/</g, '&lt;') },
            shortcuts: { togglePreview: 'Cmd-Alt-P' },
        });
        const mdForm = mdArea.form;
        mde.codemirror.on('change', () => mdArea.dispatchEvent(new Event('input', { bubbles: true })));
        mde.codemirror.setOption('extraKeys', {
            ...mde.codemirror.getOption('extraKeys'),
            'Cmd-S': () => mdForm.requestSubmit(),
            'Ctrl-S': () => mdForm.requestSubmit(),
        });

        // Avisa quando outra pessoa ou um assistente grava uma versão nova.
        const versionUrl = mdForm.dataset.docVersionUrl;
        const banner = document.querySelector('[data-doc-changed]');
        let known = +mdForm.dataset.docVersion;
        const check = async () => {
            if (document.hidden) return;
            try {
                const r = await fetch(versionUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const j = await r.json();
                if (j.version > known) {
                    known = j.version;
                    banner.querySelector('[data-doc-changed-by]').textContent = `versão ${j.version}, ${j.updated_by}`;
                    banner.hidden = false;
                }
            } catch (_) {}
        };
        if (versionUrl && banner) setInterval(check, 20000);
    }

    // Aviso ao sair com alterações por guardar.
    const form = document.getElementById('editor-form');
    if (form) {
        let dirty = false;
        form.addEventListener('input', () => (dirty = true));
        window.tinymce?.on?.('AddEditor', ({ editor }) => editor.on('change input', () => (dirty = true)));
        form.addEventListener('submit', () => (dirty = false));
        addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });
    }
})();
