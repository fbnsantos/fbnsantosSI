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

- Chaves criadas e revogadas no backoffice em **API / IA**. A autenticação usa só o hash; a chave fica também cifrada (com uma chave derivada do `setup_key`) para o botão *Mostrar instruções* a poder voltar a mostrar.
- Autenticação: `Authorization: Bearer <chave>` (ou `X-API-Key: <chave>`). Falhas repetidas bloqueiam o IP durante 15 minutos.
- O conteúdo passa pelas mesmas validações e pelo mesmo filtro de HTML do editor.
- **Aprovação obrigatória:** a API não consegue publicar. Tudo o que cria ou altera fica *pendente* e invisível no site
  até ser aprovado no backoffice em **Para aprovar** (ou no aviso no topo do editor). Rejeitar deixa-o como rascunho.
  Conteúdo já público não pode ser alterado nem apagado pela API (erro 409).
- A página **API / IA** tem instruções prontas a colar no ChatGPT (GPT com ações) e no Claude.

```bash
curl -H "Authorization: Bearer <chave>" https://fbnsantos.com/api/posts
```

## Redes sociais e Destaques

**Do site para o LinkedIn** (`app/linkedin.php`): no fundo do editor de cada artigo ou página publicada há o cartão
*Partilhar no LinkedIn*, com o texto sugerido (título, resumo, hashtags das etiquetas).
- *Publicar no LinkedIn*: publica no perfil pessoal pela API oficial, com cartão de link e a imagem de capa.
  Configuração única em **Redes sociais**: criar uma app em linkedin.com/developers com os produtos
  *Share on LinkedIn* e *Sign In with LinkedIn using OpenID Connect*, juntar o URL de redirecionamento
  `https://fbnsantos.com/admin/linkedin/callback` e colar o Client ID e o Client Secret (fica cifrado).
  O LinkedIn dá autorizações de 60 dias; depois clica-se outra vez em *Ligar ao LinkedIn*.
- *Abrir no LinkedIn*: sem configurar nada, abre o LinkedIn com o texto e o link já escritos.
- Os artigos têm também links de partilha (LinkedIn, X, Facebook, email) para os visitantes, sem scripts externos.

**Das redes para o site** (`app/social.php`): página `/highlights` (e os 3 mais recentes na página inicial) com
vídeos do YouTube, publicações do LinkedIn e do Instagram e outros links. Geridos em **Destaques**.
- Cola-se o link; o título, texto e imagem são lidos da página (YouTube: oEmbed oficial). Se a rede não deixar,
  escrevem-se à mão e envia-se uma imagem. As imagens são copiadas para `uploads/`: o site não carrega nada das redes
  (exceto os vídeos ★ destacados, em modo `youtube-nocookie`).
- YouTube: indicando o canal em **Redes sociais**, os vídeos novos entram sozinhos (feed RSS público, de 6 em 6 horas).
- O LinkedIn e o Instagram não deixam ler as publicações de um perfil pessoal pela API, por isso entram colando o link.

## Investigação (publicações e projetos)

- Página pública `/research` (publicações por ano, filtro por tipo e pesquisa) e `/research/projects` (projetos em curso e concluídos).
  Aparece no menu quando há itens visíveis; entra no sitemap, no `llms.txt` e tem dados estruturados (ScholarlyArticle, ResearchProject).
- Backoffice → **Investigação**: indicar o ORCID iD e o Authenticus (URL da lista de publicações, número ou `R-XXX-XXX`).
- **ORCID**: API pública (sem chave) → publicações com autores e projetos (secção *Funding*). Só o que é público no ORCID.
- **Authenticus**: lista pública de publicações do investigador (a API do Authenticus só é dada a instituições) → publicações validadas, com indexação Scopus/WoS.
- As fontes são juntadas pelo DOI ou pelo título. A sincronização corre sozinha uma vez por dia (em segundo plano, depois de uma visita)
  ou no botão **Sincronizar agora**; só acrescenta e preenche campos vazios — o que for editado, escondido ou ★ destacado mantém-se.
- Itens sincronizados não se apagam (voltariam na sincronização seguinte): escondem-se. Os adicionados à mão podem ser apagados.
- O nome do autor fica a negrito nas listas (variantes preenchidas a partir do ORCID e do Authenticus; editáveis).

## Palestras, prémios e imprensa

- Página pública `/activities`: cronologia por ano (palestras, prémios, imprensa, entrevistas), com filtros por tipo.
  Os ★ destacados aparecem também na página inicial (se não houver, os 3 mais recentes; desligável nas opções).
- Backoffice → **Palestras e prémios**: colar o link (notícia, página do evento, vídeo) e o site preenche título, meio/organizador,
  data e imagem; depois revê-se e guarda-se. Também se pode criar à mão.
- Cada item pode ter um vídeo (escolhido dos Destaques ou link do YouTube), que só carrega do YouTube (`youtube-nocookie`) quando se clica em ▶.
- Os prémios entram no `Person.award` dos dados estruturados; palestras como `Event` e notícias como `NewsArticle`. Entra no sitemap e no `llms.txt`.
- O ORCID tem uma secção *Distinctions*, mas o perfil não tem dados lá, por isso não há importação automática.

### Impacto (citações, quartil, fator de impacto)

- **Citações**, FWCI e "Top 10% / 1%" de cada publicação: OpenAlex, pelo DOI, na sincronização diária. Com isto o site calcula
  o total de citações, o índice h e o i10. Chave gratuita do OpenAlex opcional (Investigação → Revistas e impacto).
- **Quartil**: SJR da Scimago. A Scimago não deixa descarregar automaticamente, por isso uma vez por ano: scimagojr.com/journalrank.php →
  *Download data* → enviar o CSV em **Revistas e impacto** (se for grande demais para o upload: pôr em `data/scimagojr.csv` pelo cPanel e
  clicar em *Importar de data/*).
- **Fator de impacto**: o JIF/quartil JCR (Clarivate) não tem fonte aberta; pode ser escrito à mão por revista e passa a ter prioridade.
  Sem ele, o site mostra o "impacto a 2 anos" do OpenAlex (citações médias por artigo nos 2 anos anteriores — a fórmula do JIF com dados abertos).
- Em `/research`, cada publicação mostra quartil, impacto da revista, citações e Top 10%; há ainda a ordenação "Mais citadas".

## Estatísticas

O backoffice tem **Estatísticas** (`/admin/stats`): visualizações e visitantes por dia, páginas mais vistas,
origem das visitas (pesquisa, assistentes de IA, redes sociais, outros sites, direto), sites de origem, país, idioma,
dispositivo, sistema, navegador, pedidos de robôs (Googlebot, GPTBot, ClaudeBot…) e erros 404.
As listas de páginas e artigos mostram o total de visualizações de cada um.

- Feito pelo próprio site (`app/stats.php`): sem cookies, sem JavaScript, sem serviços externos, sem banner de consentimento.
- Não se guarda o IP. O visitante é um hash diário (IP + navegador + `setup_key` + dia): conta visitantes únicos num dia,
  mas não permite seguir ninguém entre dias.
- Da origem guarda-se só o domínio. Links com `?utm_source=` (o ChatGPT acrescenta `utm_source=chatgpt.com`) também contam.
- Robôs e as visitas com sessão no backoffice não contam como visitas. Os dados com mais de 13 meses são apagados.
- País: obtido do IP no momento do pedido, numa base local (`app/geoip.php`, base gratuita
  [DB-IP Lite](https://db-ip.com), CC BY 4.0). Descarrega-se e atualiza-se (uma vez por mês) com o botão
  *Descarregar base de países* em **Estatísticas**; fica em `data/geoip4.bin` e `data/geoip6.bin` (~9 MB).
  O IP não é enviado a nenhum serviço nem guardado. Atrás da Cloudflare usa-se `CF-IPCountry`.
- Não conta ficheiros estáticos servidos diretamente pelo Apache (ex.: a app em `/treino/`).

## Cópias de segurança

Basta copiar `data/site.sqlite` e `uploads/`.
