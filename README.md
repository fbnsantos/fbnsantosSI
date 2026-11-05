# fbnsantos.com

Site pessoal em **PHP + HTML + SQLite**, sem frameworks nem Composer, com backoffice para editar tudo
(páginas, artigos, imagens, vídeos do YouTube) e SEO gerado automaticamente.

Requisitos: PHP 8.1+ com `pdo_sqlite`, `mbstring`, `fileinfo`, `dom` (e `gd`, opcional, para reduzir imagens grandes).

## Estrutura

```
index.php        ← router (a pasta do projeto é a raiz web)
git.php          ← deploy automático
assets/          ← CSS/JS
uploads/         ← imagens enviadas no backoffice
app/             ← lógica e vistas (não acessível pela web)
data/            ← base de dados SQLite (não acessível pela web)
config.php       ← configuração (copiar de config.sample.php)
```

## Arrancar localmente

```bash
cp config.sample.php config.php   # e mudar setup_key
```

```bash
SITE_URL=http://localhost:8080 php -S localhost:8080 index.php
```

Abrir http://localhost:8080/admin → primeira vez pede a `setup_key` do `config.php` para criar o administrador.

## Publicar no HostGator (cPanel) com deploy automático

O código vem do GitHub; o servidor só guarda o que não está no git: `config.php`, `data/` (base de dados) e `uploads/`.

### 1. Clonar o repositório

cPanel → **Git™ Version Control** → *Create* → *Clone a Repository*:
- Clone URL: `https://github.com/<utilizador>/<repositório>.git`
- Repository Path: `/home/<utilizador-cpanel>/fbnsantos` (fora do `public_html`)

### 2. Apontar o domínio para a pasta do projeto

- **Domínio adicional/subdomínio:** cPanel → *Domains* → editar a *Document Root* para `fbnsantos`.
- **Domínio principal** (a raiz é sempre `public_html`): no *Terminal* do cPanel,
  ```bash
  mv ~/public_html ~/public_html.old && ln -s ~/fbnsantos ~/public_html
  ```
- Alternativa: clonar diretamente para dentro da raiz do domínio.

O `.htaccess` da raiz bloqueia `.git`, `app/`, `data/`, `config.php` e `README.md`.

### 3. Configurar

```bash
cd ~/fbnsantos && cp config.sample.php config.php && chmod 600 config.php
```

Editar `config.php` (File Manager ou `nano`):
- `setup_key` e `deploy_secret`: valores longos e aleatórios (`php -r 'echo bin2hex(random_bytes(24));'`).
- `base_url`: `https://fbnsantos.com`.

Em *Select PHP Version* (ou *MultiPHP Manager*) escolher PHP 8.1 ou superior, com `pdo_sqlite`, `gd`, `mbstring`, `fileinfo` e `dom`.
Confirmar que o SSL (AutoSSL / Let's Encrypt) está ativo.

### 4. Criar o administrador

Abrir `https://fbnsantos.com/admin/setup` e usar a `setup_key`. Depois de criado, o setup deixa de existir.

### 5. Deploy automático (`git.php`)

No GitHub: repositório → *Settings* → *Webhooks* → *Add webhook*:
- Payload URL: `https://fbnsantos.com/git.php`
- Content type: `application/json`
- Secret: o mesmo `deploy_secret` do `config.php`
- Eventos: *Just the push event*

A partir daí, cada `git push` para `main` atualiza o site em segundos. Também se pode forçar no browser
com `https://fbnsantos.com/git.php?token=<deploy_secret>`. O histórico fica em `data/deploy.log`.

O `git.php` faz `git fetch` + `git reset --hard origin/main`: o servidor fica sempre igual ao GitHub
(não edite ficheiros do repositório diretamente no servidor). Sem `deploy_secret` no `config.php` devolve 404.

## Segurança

O repositório pode ser público: não contém segredos. Tudo o que é sensível está fora do git
(`config.php`, base de dados, uploads) e fora da pasta pública.

- Palavras-passe com `password_hash`; sessões `HttpOnly`, `Secure`, `SameSite`; token CSRF em todos os formulários.
- Limite de 5 tentativas / 15 min por IP no login e no setup; o tempo de resposta não revela se o utilizador existe.
- HTML do editor filtrado por lista branca (sem scripts, eventos `on*`, `javascript:`, iframes que não sejam YouTube).
- Uploads validados pelo conteúdo real (não pela extensão), nomes aleatórios, sem execução de PHP em `uploads/`.
- `git.php` exige assinatura HMAC do GitHub ou o token; comparação em tempo constante e atraso em falhas.
- Sem redirecionamentos abertos; erros nunca mostrados ao visitante; HSTS, `nosniff`, `X-Frame-Options`.
- Imagens de partilha com URL assinado; script do editor com SRI.

### Nginx

```nginx
root /var/www/fbnsantos;
index index.php;
location ~ ^/(\.(?!well-known/)|app/|data/|config|README) { deny all; }
location / { try_files $uri /index.php?$query_string; }
location ~ ^/uploads/.*\.php$ { deny all; }
location ~ \.php$ { try_files $uri /index.php; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location ~* \.(jpg|jpeg|png|webp|avif|gif|css|js|svg|woff2)$ { expires 1y; }
gzip on; gzip_types text/css application/javascript application/xml application/rss+xml text/plain image/svg+xml;
```

## SEO (automático)

| O quê | Onde |
|---|---|
| `robots.txt` | `/robots.txt` — bloqueia `/admin`, aponta para o sitemap. Desligar "Permitir indexação" nas Definições bloqueia tudo. |
| Sitemap | `/sitemap.xml` — páginas, artigos, imagens e vídeos do YouTube, com `lastmod` |
| RSS | `/feed.xml` |
| `<head>` | title, meta description, canonical, meta robots, Open Graph, Twitter Card, verificação Google/Bing |
| JSON-LD | `WebSite`, `Person` (com perfis `sameAs`), `ProfilePage`, `BlogPosting`, `BreadcrumbList`, `VideoObject` |
| Imagens de partilha | `/og.png` gera 1200×630 com o título de cada página sem capa (assinado, em cache em `data/og/`) |
| IndexNow | Ao publicar, avisa Bing/Yandex/Seznam (o Google usa o sitemap). Chave servida em `/<chave>.txt` |
| `llms.txt` | Resumo do site em Markdown para ChatGPT, Perplexity, etc. |
| URLs | Um só endereço por página: 301 para https + domínio do `base_url`, minúsculas, sem barra final; URLs antigos `index.php?page=x` → `/x` |
| Desempenho | Fontes alojadas localmente (sem Google Fonts → RGPD), `width/height` nas imagens (sem CLS), `lazy` loading, WebP |
| Outros | favicon com iniciais, `lang`, HSTS, 404 com `noindex`, `/blog` vazio com `noindex` |

### Ligar ao Google

1. https://search.google.com/search-console → *Adicionar propriedade* → *Prefixo do URL* → `https://fbnsantos.com`.
2. Método *Etiqueta HTML*: copiar a meta tag e colar em **Backoffice → Definições → Código de verificação Google**. Guardar e clicar *Verificar*.
3. Em *Sitemaps*, submeter `sitemap.xml`.
4. (Opcional) O mesmo no Bing Webmaster Tools (também pode importar do Search Console).

### O que fica do seu lado

- Ligar o Search Console e submeter o sitemap (acima).
- Enviar uma fotografia maior (≥ 600 px) em Definições: a atual tem 156 px.
- Ter HTTPS ativo no alojamento (Let's Encrypt). O site redireciona para https automaticamente.
- Se ativar o Google Analytics, a lei europeia exige um aviso de consentimento de cookies.
- Escrever conteúdo regularmente: é o que mais pesa no ranking.

## Editar conteúdo

O conteúdo inicial (textos, perfis e foto do fbnsantos.com) está em `app/seed.php` e só é usado quando a base de dados é criada. Depois disso edita-se tudo no backoffice. Os URLs antigos (`index.php?page=contacts`) redirecionam com 301 para os novos (`/contacts`).


- **Páginas** (`/sobre`, `/projetos`…): aparecem no menu pela ordem definida. A página *Início* é a página inicial.
- **Artigos** (`/blog/...`): com data (uma data futura agenda a publicação), resumo, etiquetas e capa.
- **Imagens**: arrastar/colar no editor, ou enviar em *Imagens*. Imagens com mais de 2000 px são reduzidas.
  Preencher o *texto alternativo* ajuda o Google Imagens.
- **YouTube**: colar o link sozinho numa linha (`https://youtu.be/...`, `watch?v=`, `shorts/`) ou usar o botão de vídeo.
  É incorporado em modo de privacidade (`youtube-nocookie.com`).
- O HTML é filtrado ao guardar (sem scripts nem iframes que não sejam do YouTube).

## API (ChatGPT, Claude, scripts)

API REST em `/api` para criar e editar artigos, páginas e imagens. Especificação OpenAPI 3.1 em `/api/openapi.json`.

- Chaves criadas e revogadas no backoffice em **API / IA**. A chave só é mostrada uma vez; guarda-se apenas o hash.
- Autenticação: `Authorization: Bearer <chave>` (ou `X-API-Key: <chave>`). Falhas repetidas bloqueiam o IP durante 15 minutos.
- O conteúdo passa pelas mesmas validações e pelo mesmo filtro de HTML do editor.
- **Aprovação obrigatória:** a API não consegue publicar. Tudo o que cria ou altera fica *pendente* e invisível no site
  até ser aprovado no backoffice em **Para aprovar** (ou no aviso no topo do editor). Rejeitar deixa-o como rascunho.
  Conteúdo já público não pode ser alterado nem apagado pela API (erro 409).
- A página **API / IA** tem instruções prontas a colar no ChatGPT (GPT com ações) e no Claude.

```bash
curl -H "Authorization: Bearer <chave>" https://fbnsantos.com/api/posts
```

## Cópias de segurança

Basta copiar `data/site.sqlite` e `uploads/`.
