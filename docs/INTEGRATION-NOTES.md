# EXTRACT HUNTERS — Notas de integração (complemento ao SPEC.md)

Este documento explica **como as peças já construídas se encaixam** e o que um agente de tela precisa saber
que o `SPEC.md` não diz. O SPEC continua sendo o contrato; em caso de conflito, o SPEC vence.

Estado integrado (verificado): todas as rotas do SPEC §2 respondem 200 (404 para rota inexistente, 405 para
`GET /sair`, 403 para `app/`, `tools/`, `captures/`, `*.md`, `*.json` fora de `assets/`, `.env`); a CSP com nonce
por requisição está presente; `node tools/capture.mjs` captura as 23 telas com **zero erros de console,
zero 404 e zero violações de CSP**.

---

## 1. Fluxo de uma requisição

```
index.php -> app/bootstrap.php (autoload App\ => app/, Config::boot, helpers, sessão)
          -> Router::load(app/routes.php) -> Controller@action(Request $req, array $params): Response
          -> Response::send()
```

* `Controller::page($slug, $title, $over)` monta o `$page` do SPEC §4 com defaults (bg por slug, nav, back,
  three). `Controller::render($view, $page, $data, $layout = 'hud', $status = 200)` injeta `player`, `nav`,
  `server`, `flash` e `csrf` e devolve um `Response`.
* `View::render` só expõe às views as chaves `page, player, nav, server, data, flash, csrf` (+ `$content` no
  layout). `partial()`/`component()` recebem o mesmo escopo mais as próprias variáveis.
* Arquivo ausente (partial, component, css/pages/x.css, js/pages/x.js) vira um comentário HTML, nunca fatal.
* Erros HTTP (404/405/419/500) renderizam `pages/_404.php` dentro de `layouts/auth.php` com o código real.
  Em `localhost` (`Config::debug()`) a página 500 mostra a exceção.

---

## 2. Como adicionar / preencher uma tela (agente de tela)

Uma tela = 4 arquivos, todos seus: `app/Views/pages/<slug>.php`, `assets/css/pages/<slug>.css`,
`assets/js/pages/<slug>.js`, `app/Data/<slug>.php`. Controller e rota **já existem** para todas as telas do
SPEC §2 — não é preciso tocar em `app/Controllers` nem em `app/routes.php`.

1. **Dados**: `app/Data/<slug>.php` devolve um array. O controller faz `data_mock('<slug>')` e passa em
   `$data` (alguns controllers acrescentam chaves calculadas — veja a lista de `$data` por tela abaixo).
   Pode estender à vontade, mas **mantenha as chaves existentes**: os controllers e o placeholder dependem delas.
2. **View**: substitua o placeholder `app/Views/pages/<slug>.php` 1:1. Comece com
   `<?php declare(strict_types=1); ?>`, use sintaxe alternativa (`<?php if (...): ?>`), **todo dado dinâmico
   passa por `e()`**, URLs só via `url()`/`asset()`/`img()`. Nada de `session_start`, `$_GET`, `$_POST`.
3. **CSS**: `assets/css/pages/<slug>.css` é carregado automaticamente **depois** de
   `tokens > base > hud > components > animations > topbar > footer > logo`. Escreva regras sob
   `.page-<slug>` para não vazar para outras telas. Use apenas tokens de `tokens.css`.
4. **JS**: `assets/js/pages/<slug>.js` é carregado automaticamente como `type="module"` **depois** de
   `hud.js`, `carousel.js` e dos módulos three (`atmosphere.js`, `hero3d.js`, `map3d.js` conforme
   `$page['three']`). `import * as THREE from 'three'` e `from 'three/addons/...'` funcionam via importmap.
5. **Precisa de outro css/js?** Passe em `$page['css']` / `$page['js']` a partir do controller (`$over`), ou peça
   ao orquestrador. Não crie `<style>`/`<script>` inline sem `nonce="<?= nonce() ?>"` — a CSP bloqueia.
6. **Fundo**: o controller já define `$page['bg']` (SPEC §9). Para mudar o recorte, `bg_pos` (ex.: `'center top'`).
7. **Topbar/footer**: nunca redesenhe; só `$page['nav']` (item ativo) e `$page['back']` variam.

### Criar uma tela nova (fora do SPEC)

1. `app/Controllers/XController.php` estendendo `Controller`; método `index(Request $req, array $params): Response`
   que faz `return $this->render('slug', $this->page('slug', 'Título'), data_mock('slug'));`
2. Linha em `app/routes.php`: `['GET', '/slug[/{param}]', XController::class, 'index']`. Parâmetros opcionais
   validam-se com `$this->pick($params['param'] ?? null, $whitelist, $default)` (desconhecido => 404).
3. Se a tela aparece no topbar, adicione em `app/Data/nav.php`; se não, mapeie o item ativo em
   `Controller::NAV_OF` e o fundo em `Controller::BG`.
4. Crie os 4 arquivos da tela. POST: `$this->csrf($req)` antes de agir; `flash('ok', 'msg')` + `$this->redirect('slug')`.

### `$data` que cada controller entrega hoje

| Tela | `$data` |
|---|---|
| `inicio` | `inicio.php` + `news` (3 primeiras de `noticias.php`) |
| `jogar` | `jogar.php` + `maps` (itens de `mapas.php`), `challenges` (4 diárias), `reset_in` |
| `lobby` | `lobby.php` + `chat` (mock + `$_SESSION['lobby_chat']`), `news`, `news_slides` |
| `configuracoes` | `tabs`, `active_tab`, `tab`, `groups` (da aba ativa: `[{title, items[{key,label,type: toggle/stepper/range/select/key/text, value, options?, unit?}]}]`), `difficulty` |
| `inventario`, `saude` (`?aba=tratamento|status`), `missoes`, `mapas`, `desafios`, `amigos`, `mensagens`, `perfil`, `suporte`, `relatorio-de-bug` | array do `app/Data/<slug>.php` |
| `habilidades` | `categories`, `active_category`, `category`, `tiers`, `tree` (4 tiers x 5 nós: `slug,name,icon,state unlocked/progress/locked,cur,max,cost,selected?`), `selected`, `points` |
| `mapa` | `regions` (5), `active_region`, `region`, `markers` (x,y em %), `legend`, `controls`, `map_image` |
| `conquistas` | `categories`, `active_category`, `items`, `selected`, `done`, `total`, `pagination`, textos de detalhe (`?ordem=recentes|nome|progresso`) |
| `noticias` | lista; `/noticias/{slug}` acrescenta `article` |
| `loja` | `loja.php` (`?categoria=`) |
| `login`, `criar-conta`, `recuperar-senha` | `auth` (textos), `error`/`errors`, `values`/`user`, `sent` |
| `_404` | `code`, `title`, `message`, `detail`, `path`, `links[]` |

Fluxos POST já funcionais: `/login`, `/criar-conta`, `/recuperar-senha`, `/sair`, `/lobby/chat` (campo `message`),
`/configuracoes/{aba}` (`action=apply|reset`), `/suporte`, `/relatorio-de-bug`. Todos exigem `csrf_field()`.

---

## 3. Layouts: o que cada um garante

**`layouts/hud.php`** (todas as telas do jogo): `<head>` com preloads de fonte, folhas na ordem do §2 acima,
`css/pages/{slug}.css`; `<body class="page-{slug}" data-page data-base data-nonce>`; `partial('background')`,
`.hud > topbar + main.hud-main#main + footer`, `.page-transition`, importmap (`three`, `three/addons/`),
`hud.js`, `carousel.js`, módulos three, `js/pages/{slug}.js`, extras. Flashes pendentes são renderizados no topo
de `<main>` em `.flash-stack` (toasts fixos abaixo do topbar; `data-autohide` 5 s / erro 9 s).

**`layouts/auth.php`** (login, criar-conta, recuperar-senha, páginas de erro): mesmo head; `<body class="page-{slug} is-auth">`;
`.hud.hud--auth > main.auth-main#main + footer`. `.auth-main` centraliza um filho `.panel` (largura máx. 58rem).
Footer em modo **minimal** (`partial('footer', ['minimal' => true])`): sem barra de vidro — só "Suporte" à
esquerda e o status do servidor à direita, discretos, porque a referência 00 não tem footer.
Se a tela precisar de um painel mais largo, sobrescreva `.page-login .auth-main > .panel{max-width:...}`.

**`partial('background')`**: lê `$page['bg']`, `$page['bg_pos']`, `$page['three']['atmosphere']`; emite `<picture>`
com webp quando existe; aceita `partial('background', ['bg' => 'bg/x.jpg', 'bg_pos' => 'center'])`.

**Segurança já aplicada (não desfazer)**: CSP `default-src 'self'; script-src 'self' 'nonce-X'; style-src 'self'
'nonce-X'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; worker-src 'self' blob:;
frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'`. Ou seja: atributos `style="--v:62%"`
são permitidos (o SPEC exige), mas `<style>` e `<script>` inline **precisam de `nonce="<?= nonce() ?>"`** e nenhum
recurso externo (CDN, Google Fonts) carrega. Sessão `EHSESSID` HttpOnly/SameSite=Lax; CSRF via `_csrf`
(ou header `X-CSRF-Token`) checado por `Security::checkCsrf()` -> 419.

---

## 4. Cheat-sheet de componentes e helpers

Todos os componentes ficam em `app/Views/components/<nome>.php`; o cabeçalho de cada arquivo lista as props.
Chame com `component('nome', [...])` (echo direto). Props escalares são escapadas; props `body`, `slides`, `ctrl`,
`foot` são **HTML já renderizado** (monte com `ob_start()`/`ob_get_clean()`).

```php
component('panel',    ['title' => 'ARMAS', 'meta' => '1 / 4', 'hot' => true, 'grow' => true,
                       'action' => ['label' => 'VER TODOS', 'href' => url('inventario'), 'icon' => 'add'], 'body' => $html]);
component('btn',      ['label' => 'JOGAR SOLO', 'href' => url('jogar'), 'variant' => 'primary', 'size' => 'lg',
                       'icon' => 'sports_esports', 'chev' => true]);          // variant: primary|gold|ghost|outline|lime|danger
component('hex',      ['size' => 'md', 'text' => 23, 'hot' => true]);          // ou 'icon' => 'skull' / 'svg' => 'emblem-hex'
component('progress', ['value' => 62, 'max' => 100, 'color' => 'cyan', 'anim' => true, 'label' => 'SAÚDE']);
component('toggle',   ['name' => 'aim', 'checked' => true, 'on' => 'ATIVADO', 'off' => 'DESATIVADO']);
component('stepper',  ['options' => ['FÁCIL', 'MÉDIA', 'DIFÍCIL'], 'value' => 'MÉDIA', 'name' => 'dif']);
component('range',    ['name' => 'vol', 'value' => 60, 'suffix' => '%']);
component('tabs',     ['items' => [['label' => 'TRATAMENTO', 'panel' => 'trat', 'active' => true],
                                   ['label' => 'STATUS', 'panel' => 'status']]]);   // 'href' => navegação server-side
component('row',      ['title' => 'DISPARO ESTÁVEL', 'sub' => 'Recuo -10%', 'icon' => 'my_location',
                       'meta' => '2/3', 'active' => true, 'href' => url('habilidades/combate')]);
component('card',     ['title' => 'VALE DO ECO', 'sub' => 'EXTRAÇÃO', 'meta' => '6v6', 'img' => 'maps/vale-do-eco.jpg',
                       'selected' => true, 'value' => 'vale-do-eco']);
component('chip',     ['label' => 'LÍDER', 'color' => 'gold']);
component('stat',     ['label' => 'SAÚDE', 'value' => 100, 'max' => 100, 'icon' => 'favorite', 'color' => 'lime']);
component('slot',     ['name' => 'AK-103', 'img' => 'items/ak103.png', 'count' => '30/30', 'key' => '1', 'wide' => true]);
component('carousel', ['slides' => [$html1, $html2], 'interval' => 5000, 'dots' => true]);
component('logo',     ['variant' => 'hero']);   // hero (Home, ~310x150px), auth (coluna esquerda do login), mark
```

Layouts de página (hud.css): `.layout-3` (`--col-l` | 1fr | `--col-r`; ajuste com `style="--col-l:20%;--col-r:20.5%"`
ou `.layout-3--wide-l/--wide-r/--narrow`), `.layout-2`, `.layout-2--r`, `.layout-fill`, `.col` (pilha vertical;
`.panel--grow` preenche), `.page-head`, `.page-title` (com `<small>` lime), `.section-title`, `.scroll-y` (só dentro
de corpos de painel — `.hud-main` **não rola**). Utilitários: `.num .lime .cyan .amber .gold .red .purple .muted .uc
.flex .flex-col .grid .gap-1..6 .mt-* .mb-* .ml-auto .between .center .cols-2/3/4 .w-full .grow .hidden .sr-only .ellipsis`.

Formulários: `.form` (grid, gap 1rem), `.field > .field__label + .input`, `.field__error`, `.field__hint`,
`.field--check`, `.field--icon` (ícone à direita do input), `.form__actions`, `.link`. Inputs de texto já são
estilizados mesmo sem classe.

Helpers: `e()`, `url('mapa/floresta-verde', ['x' => 1])`, `asset('css/x.css')` (adiciona `?v=mtime`;
`asset($p, false)` sem versão), `img('items/ak103.png')`, `icon('skull', 'fill')` (`.ms`, ligadura; classe `fill`
= variante preenchida), `svg('coin-credit', 'lg')` (`<svg class="ic"><use>`), `component()`, `partial()`,
`csrf_field()`, `nonce()`, `flash('ok', 'msg')` / `flash('ok')`, `active('inicio')`, `num(125500)` = `125.500`,
`num(2.5, 1)` = `2,5`, `data_mock('slug')`, `attr_class(...)`.

Comportamentos do `hud.js` (nenhum JS necessário na tela): boot com stagger (`.panel` e `[data-boot]`;
`[data-stagger]` para filhos), `.tab[data-tab]` + `[data-tab-panel]` dentro de `[data-tabs-scope]`, steppers
(`data-options="A|B|C" data-value`), toggles (texto via `data-on/data-off`), ranges (`--v`), `[data-select-group]`
(seleção única; valor `multi` para múltipla; sincroniza input via `data-select-input`), `[data-count]` (contador
animado pt-BR, `data-dec/prefix/suffix/dur`), `[data-tip]` tooltips, `.flash[data-autohide]`, transição de página
(pule com `data-no-transition`). Eventos: `hud:booted, tab:change, stepper:change, toggle:change, select:change,
carousel:change`.

Animações (animations.css): `.progress--anim`, `.scanline`, `hud-in`, `fade-up`, `pulse-lime`, `shimmer`, `sweep`,
`blink`, `pop`; tudo desativado em `prefers-reduced-motion`.

---

## 5. Ícones e imagens

* **Material Symbols**: `assets/fonts/material-symbols-0.woff2` é a fonte variável **completa** (2,3 MB), então
  qualquer ligadura oficial funciona (`park`, `landscape`, `factory`, `pets`, `send`, `undo`, `home`...). Prefira os nomes
  do SPEC §8; use outros só quando a referência mostrar claramente o glifo. `icon('x', 'fill')` = preenchido.
* **Sprite** `assets/icons/sprite.svg`: os 23 ids do SPEC §8 + `check-badge`, `hex-frame`, `signal`. `<use href>`
  same-origin é permitido pela CSP. Tamanho via `font-size` (ou `.ic.lg/.ic.xl`), cor via `currentColor`.
* **Imagens**: sempre `img('...')` (SPEC §9). Fundos têm `.webp` ao lado — `partial('background')` já usa.
* **Three.js r180** vendorizado em `assets/vendor/three/` (`build/three.module.js` + `build/three.core.js`,
  `examples/jsm/*`). `tools/fetch_vendor.mjs` busca ambos os arquivos do build.

---

## 6. Ferramentas de verificação

```
"C:/wamp64/bin/php/php8.3.28/php.exe" -l app/Views/pages/<slug>.php     # lint
node tools/capture.mjs <slug> [--hover sel] [--click sel] [--suffix x]    # 1672x941 -> captures/<slug>.png + <slug>.log.json
node tools/shot.mjs http://localhost/extracthunters/<slug> captures/x.png --clip x,y,w,h
node tools/capture.mjs --list                                             # slug -> referência
```

Critério de pronto de uma tela: `captures/<slug>.log.json` com `"errors": []`, todos os links 200, e a captura
comparada lado a lado com `O-QUE-VAMOS-CRIAR/Telas do jogador/<ref>.png` (geometria ±1%, tipografia, cor/glow).

Pegadinhas conhecidas:
* Formulários renderizados **antes** do login ficam com token CSRF inválido depois dele (o login rotaciona sessão e
  token) — recarregue a página antes de postar.
* `Response::send()` emite a status line explicitamente (Apache mod_php transformaria 419 em 500).
* `mod_expires` não está carregado no WAMP: cache só via `mod_headers` (assets com `?v=` são imutáveis por 1 ano).
* A captura da tela `404` espera HTTP 404 do próprio documento (`SCREENS['404'].expect`); qualquer outro 4xx é erro.
* `assets/_dev/*.html` são pré-visualizações estáticas dos donos F2/F3 (links relativos), não fazem parte do app.
