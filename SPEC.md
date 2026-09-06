# EXTRACT HUNTERS — Contrato técnico e de design (fonte única da verdade)

Todo agente que tocar no projeto lê este arquivo antes de escrever qualquer código. Ele fixa nomes de
arquivos, funções, classes CSS, tokens e formas de dados para que vários agentes trabalhem em paralelo
sem conflito. Quem precisar de algo fora do contrato **não altera arquivos de outro dono**: devolve um
pedido estruturado ao orquestrador.

Base URL local: `http://localhost/extracthunters/` (WAMP, Apache 2.4 + PHP 8.3, `mod_rewrite` ativo).
Referências visuais: `O-QUE-VAMOS-CRIAR/Telas do jogador/*.png` (1672×941). Idioma da UI: **pt-BR**.

---

## 1. Estrutura de pastas e donos

| Caminho | Dono | Conteúdo |
|---|---|---|
| `index.php`, `.htaccess`, `app/bootstrap.php`, `app/Core/*`, `app/routes.php`, `app/Controllers/*`, `app/Data/*`, `app/Views/layouts/*`, `app/Views/pages/_404.php` | **F1 Core** | Front controller, rotas, segurança, mocks, layouts |
| `assets/css/tokens.css`, `base.css`, `hud.css`, `components.css`, `animations.css`, `assets/js/hud.js`, `carousel.js`, `assets/js/three/atmosphere.js`, `assets/icons/sprite.svg`, `app/Views/components/*.php`, `app/Views/partials/background.php`, `app/Views/partials/icons.php` | **F2 Design system** | Tokens, componentes, animações, atmosfera 3D, ícones |
| `app/Views/partials/topbar.php`, `app/Views/partials/footer.php`, `app/Views/components/logo.php`, `assets/css/topbar.css`, `assets/css/footer.css`, `assets/css/logo.css` | **F3 Topbar/Footer** | Header/Topbar, Footer, Logo |
| `app/Views/pages/<slug>.php`, `assets/css/pages/<slug>.css`, `assets/js/pages/<slug>.js`, `app/Data/<slug>.php` | **Agente da tela** | Uma tela = um agente. Só toca nesses 4 arquivos |
| `assets/js/three/map3d.js` | tela `mapa` | Mapa 3D |
| `assets/js/three/hero3d.js` | tela `inicio` (reusado por `lobby`) | Soldado 3D |
| `assets/img/**`, `assets/fonts/**`, `assets/vendor/**`, `assets/models/**`, `tools/**` | Orquestrador | Já prontos; não editar |

Todos os arquivos PHP começam com `<?php declare(strict_types=1);`. Views usam sintaxe alternativa
(`<?php if (...): ?>`), **nunca** `<?=` sem `e()` para dados dinâmicos.

---

## 2. Rotas (arquivo `app/routes.php`)

Formato: `['GET', '/inicio', HomeController::class, 'index']`. Parâmetros `{slug}` casam `[a-z0-9-]{1,40}`.

| Método | Rota | Controller@method | Slug da página (`$page['slug']`) |
|---|---|---|---|
| GET | `/` | Auth@root → redirect `/login` (ou `/inicio` se sessão) | — |
| GET/POST | `/login` | Auth@login | `login` |
| GET/POST | `/criar-conta` | Auth@register | `criar-conta` |
| GET/POST | `/recuperar-senha` | Auth@recover | `recuperar-senha` |
| POST | `/sair` | Auth@logout → `/login` | — |
| GET | `/inicio` | Home@index | `inicio` |
| GET | `/jogar` | Play@index | `jogar` |
| GET | `/lobby` | Lobby@index | `lobby` |
| POST | `/lobby/chat` | Lobby@chat (CSRF, flash) → `/lobby` | — |
| GET/POST | `/configuracoes[/{aba}]` | Settings@index | `configuracoes` (abas: jogabilidade, controles, video, audio, interface, comunicacao, conta) |
| GET | `/inventario` | Inventory@index | `inventario` |
| GET | `/saude` | Health@index | `saude` |
| GET | `/habilidades[/{categoria}]` | Skills@index | `habilidades` |
| GET | `/mapa[/{regiao}]` | Map@index | `mapa` |
| GET | `/conquistas[/{categoria}]` | Achievements@index | `conquistas` |
| GET | `/missoes` | Missions@index | `missoes` |
| GET | `/mapas` | Play@maps | `mapas` |
| GET | `/desafios` | Challenges@index | `desafios` |
| GET | `/noticias[/{slug}]` | News@index / News@show | `noticias` |
| GET | `/loja` | Shop@index | `loja` |
| GET | `/amigos[/{aba}]` | Friends@index | `amigos` |
| POST | `/amigos` | Friends@store (convite/aceitar/recusar/cancelar) | — |
| GET | `/mensagens[/{conversa}]` | Messages@index | `mensagens` |
| POST | `/mensagens/responder` | Messages@reply | — |
| POST | `/mensagens/lida` | Messages@read (JSON) | — |
| GET | `/perfil` | Profile@index | `perfil` |
| GET/POST | `/suporte` | Support@index | `suporte` |
| GET/POST | `/relatorio-de-bug` | Support@bug | `relatorio-de-bug` |
| GET | `/relatorio-de-bug/{code}` | Support@bugDetail | `relatorio-de-bug` |
| * | qualquer outra | Errors@notFound (HTTP 404) | `404` |

Sem banco: login aceita qualquer credencial não vazia, grava `$_SESSION['player']` com o mock e
redireciona para `/inicio`. Nenhuma rota exige sessão (navegação livre para QA), mas o topbar mostra o
mock do jogador sempre.

---

## 3. Helpers globais (definidos em `app/Core/helpers.php`, carregados pelo bootstrap)

```php
e(mixed $v): string                 // htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, 'UTF-8')
url(string $path = '', array $q = []): string   // BASE_URL . path amigável, query rawurlencoded
asset(string $path): string         // BASE_URL . 'assets/' . path . '?v=' . filemtime (cache-bust)
img(string $path): string           // asset('img/' . path)
icon(string $name, string $class = '', array $attrs = []): string
    // <span class="ms {class}" aria-hidden="true">{ligature}</span>   (Material Symbols Outlined)
svg(string $id, string $class = ''): string
    // <svg class="ic {class}"><use href="{asset('icons/sprite.svg')}#{id}"/></svg>
component(string $name, array $props = []): void   // inclui app/Views/components/{name}.php com $props
partial(string $name, array $vars = []): void      // inclui app/Views/partials/{name}.php
csrf_field(): string                // <input type="hidden" name="_csrf" value="...">
nonce(): string                     // nonce CSP da requisição (usar em <script nonce> / <style nonce>)
flash(string $key, ?string $msg = null): ?string   // set/get de mensagem de sessão
active(string $slug): string        // devolve ' is-active' se $page['slug'] === $slug
num(int|float $n, int $dec = 0): string  // formato pt-BR: 125.500 / 2,5
```

`Core/View::render(string $layout, string $pageView, array $data)` extrai apenas as chaves permitidas
(`page`, `player`, `nav`, `server`, `data`, `flash`, `csrf`) para o escopo da view.

---

## 4. Contrato do layout `app/Views/layouts/hud.php`

Toda página (exceto login/criar-conta/recuperar-senha/404 que usam `layouts/auth.php`) recebe:

```php
$page = [
  'slug'     => 'inicio',              // usado em <body class="page-inicio"> e para CSS/JS da página
  'title'    => 'Início',              // <title>Início · Extract Hunters</title>
  'bg'       => 'bg/home-hero.jpg',    // imagem de fundo (ver §7); null = só cor
  'bg_pos'   => 'center',              // object-position
  'nav'      => 'inicio',              // item ativo do topbar (slug)
  'back'     => ['label' => 'VOLTAR', 'href' => url('inicio')] | ['label' => 'SAIR', 'href' => null (POST /sair)],
  'css'      => ['pages/inicio.css'],  // extras (auto: pages/{slug}.css se existir)
  'js'       => ['pages/inicio.js'],   // extras (auto: pages/{slug}.js se existir), carregados como type="module"
  'three'    => ['atmosphere' => true, 'hero' => false, 'map' => false],
];
```

Ordem no `<body>`:
```html
<body class="page-{slug}" data-page="{slug}">
  <?php partial('background') ?>          <!-- .bg > img.bg__img + .bg__overlay + canvas#atmosphere + .bg__grain + .bg__vignette -->
  <div class="hud">
    <?php partial('topbar') ?>            <!-- <header class="topbar"> -->
    <main class="hud-main" id="main">…página…</main>
    <?php partial('footer') ?>            <!-- <footer class="footer"> -->
  </div>
  <div class="page-transition" aria-hidden="true"></div>
  <script type="importmap" nonce>… "three": asset('vendor/three/build/three.module.js'), "three/addons/": asset('vendor/three/examples/jsm/') …</script>
  <script type="module" nonce src="hud.js"></script> … extras
</body>
```

`.hud` é um grid de 3 linhas: `auto 1fr auto` ocupando `100dvh`; `.hud-main` tem `padding: 1.5rem 1.75rem`
e **não rola** em 1672×941 (as telas cabem inteiras, como na referência). Conteúdo que precise
rolar usa `.scroll-y` dentro do painel.

---

## 5. Dados mock (`app/Data/*.php` devolvem arrays; controllers passam para a view)

```php
// player.php
['name' => 'HUNTER_07', 'level' => 23, 'xp' => 12456, 'xp_next' => 18200,
 'avatar' => 'portraits/op-assault.jpg', 'clan' => 'Resistência',
 'currencies' => ['credits' => 125500, 'cash' => 2450, 'points' => 1250],
 'friends_online' => 8, 'unread_mail' => 3, 'notifications' => 0]
// nav.php  (ordem fixa do topbar)
[['slug'=>'inicio','label'=>'INÍCIO','route'=>'inicio'], ['slug'=>'inventario','label'=>'INVENTÁRIO','route'=>'inventario'],
 ['slug'=>'saude','label'=>'SAÚDE','route'=>'saude'], ['slug'=>'habilidades','label'=>'HABILIDADES','route'=>'habilidades'],
 ['slug'=>'mapa','label'=>'MAPA','route'=>'mapa'], ['slug'=>'missoes','label'=>'MISSÕES','route'=>'missoes'],
 ['slug'=>'conquistas','label'=>'CONQUISTAS','route'=>'conquistas']]
// server.php
['region' => 'AMÉRICA DO SUL', 'ping_ms' => 32, 'status' => 'online', 'bars' => 4]
```
Moedas: `credits` = ⊕ lime (ícone `svg('coin-credit')`), `cash` = $ cyan (`svg('coin-cash')`), `points` = ▲ laranja (`svg('coin-points')`).
Formato numérico pt-BR via `num()` (125.500).

---

## 6. Design tokens (`assets/css/tokens.css`) — nomes obrigatórios

```css
:root{
  /* escala: a referência é 1672×941; 1rem = 16px nesse tamanho, proporcional em qualquer viewport 16:9 */
  font-size: clamp(9px, min(0.95694vw, 1.70032vh), 24px);

  --c-bg:#070907; --c-bg-2:#0b0f0b; --c-panel:rgba(8,12,8,.78); --c-panel-2:rgba(14,19,14,.86); --c-panel-solid:#0c110c;
  --c-line:rgba(150,190,100,.22); --c-line-2:rgba(150,190,100,.12); --c-line-hot:#9be04a;
  --c-lime:#9be04a; --c-lime-2:#b6f25a; --c-lime-dim:#6f9f34; --c-green-btn:#3f6b1f; --c-green-btn-2:#2c4d15;
  --c-cyan:#3ec9f0; --c-amber:#f5a623; --c-gold:#f5c518; --c-red:#e5484d; --c-purple:#a855f7; --c-blue:#4c8dff;
  --c-text:#e8ede6; --c-text-2:#c2cabf; --c-muted:#8f998d; --c-dim:#5d675b;
  --glow-lime:0 0 0 1px rgba(155,224,74,.55), 0 0 18px rgba(155,224,74,.35), inset 0 0 22px rgba(155,224,74,.08);
  --glow-gold:0 0 0 1px rgba(245,197,24,.6), 0 0 16px rgba(245,197,24,.35);
  --glow-soft:0 0 12px rgba(155,224,74,.25);
  --shadow-panel:0 10px 30px rgba(0,0,0,.45);
  --font-cond:'Barlow Condensed',system-ui,sans-serif; --font-body:'Barlow',system-ui,sans-serif;
  --font-mono:'Share Tech Mono',ui-monospace,monospace; --font-display:'Anton','Barlow Condensed',sans-serif;
  --r-1:.1875rem; --r-2:.25rem; --r-3:.375rem;
  --sp-1:.25rem; --sp-2:.5rem; --sp-3:.75rem; --sp-4:1rem; --sp-5:1.5rem; --sp-6:2rem;
  --t-fast:120ms; --t-med:220ms; --t-slow:420ms; --ease:cubic-bezier(.2,.7,.2,1); --ease-out:cubic-bezier(.16,1,.3,1);
  --topbar-h:5rem; --footer-h:3.75rem;
}
```

Tipografia: labels/nav/títulos `font-family:var(--font-cond); text-transform:uppercase; letter-spacing:.04em`.
Corpo `var(--font-body)`. **Todo número** (XP, moedas, contadores, ping, datas) usa `var(--font-mono)`
com a classe utilitária `.num`. Títulos de página (`JOGAR SOLO`, `CONFIGURAÇÕES`, `LOBBY`): `.page-title`
2.1rem/500. Títulos de painel `.panel__title` 1.05rem/500 uppercase.

---

## 7. Componentes (`assets/css/components.css` + `app/Views/components/*.php`) — classes obrigatórias

| Componente | Markup mínimo / props | Estados |
|---|---|---|
| Painel | `<section class="panel [panel--hot] [panel--flush]"><header class="panel__head"><h2 class="panel__title">…</h2><a class="panel__action">VER TODOS <span class="ms">add</span></a></header><div class="panel__body">…</div></section>` | `.panel--hot` borda lime + `--glow-lime`; hover opcional `.panel--hover` |
| Botão | `<a|button class="btn btn--primary|btn--gold|btn--ghost|btn--outline|btn--danger btn--lg|btn--sm">[<span class="ms">icon</span>] LABEL [<span class="ms btn__chev">chevron_right</span>]</a>` | hover: borda lime + sweep de brilho (`::after`), translateY(-1px); active: scale(.985) inset |
| Botão de menu (Home) | `<a class="menu-btn [is-active]"><span class="menu-btn__icon ms">…</span><span class="menu-btn__text"><strong>JOGAR SOLO</strong><small>Entre sozinho…</small></span><span class="ms menu-btn__chev">chevron_right</span></a>` | ativo: fundo verde gradiente + borda lime + glow |
| Hex badge | `component('hex', ['size'=>'md|lg|xl','text'=>'23','icon'=>'skull','hot'=>true])` → `<span class="hex hex--md [hex--hot]"><span class="hex__in">…</span></span>` | clip-path hexágono, borda lime |
| Progresso | `<div class="progress [progress--cyan|--amber|--red] [progress--anim]" style="--v:62%"><div class="progress__bar"></div></div>` | `--anim` preenche no load (`animations.css`) |
| Toggle | `<label class="toggle"><input type="checkbox" checked><span class="toggle__track"></span><span class="toggle__label">ATIVADO</span></label>` | lime quando on |
| Stepper | `<div class="stepper"><button class="stepper__btn ms">chevron_left</button><span class="stepper__val">MÉDIA</span><button class="stepper__btn ms">chevron_right</button></div>` | |
| Range | `<input type="range" class="range" min max value>` + `<span class="range__val num">60%</span>` | trilho lime |
| Tabs | `<nav class="tabs"><a class="tab is-active">TRATAMENTO</a><a class="tab">STATUS</a></nav>` | ativo: linha superior lime + glow + texto lime |
| Lista/linha | `<a class="row [is-active] [is-locked]"><span class="row__icon ms">…</span><span class="row__text"><strong>…</strong><small>…</small></span><span class="row__meta num">8/15</span><span class="ms row__lock">lock</span></a>` | ativo: fundo verde escuro + borda lime esquerda |
| Card (mapa/notícia/item) | `<article class="card [is-selected]"><div class="card__media"><img></div><div class="card__body">…</div></article>` | selecionado: borda lime + check lime canto |
| Chip/Tag | `<span class="chip chip--lime|--gold|--cyan|--amber|--red|--purple">LÍDER</span>` | |
| Stat | `<div class="stat"><span class="stat__icon ms">…</span><span class="stat__label">SAÚDE</span><span class="stat__val num">100<small>/100</small></span></div>` | |
| Slot de item | `<div class="slot [slot--wide] [slot--empty]"><span class="slot__name">AK-103</span><img class="slot__img"><span class="slot__count num">30/30</span><span class="slot__key num">1</span></div>` | hover: borda lime, img `scale(1.04)` |
| Carrossel | `<div class="carousel" data-carousel data-interval="5000"><div class="carousel__track"><div class="carousel__slide">…</div></div><div class="carousel__dots"></div></div>` | `carousel.js` cria dots, autoplay, pausa no hover, swipe |
| Divider | `<hr class="divider">` | linha `--c-line-2` |
| Ícone | `icon('shopping_cart')`, `svg('coin-credit')` | tamanho via `font-size` |

Utilitários: `.num`, `.lime`, `.cyan`, `.amber`, `.gold`, `.red`, `.muted`, `.uc`, `.scroll-y`, `.grid`, `.flex`, `.gap-*`, `.mt-*`, `.hidden`.
Boot da HUD: elementos com `data-boot` (ou todos os `.panel`) recebem `.is-booted` em stagger de 60ms (`hud.js`).
Sequência visual em `animations.css`: `@keyframes hud-in` (opacity 0→1, translateY(8px)→0, borda lime "wipe").
`prefers-reduced-motion`: sem stagger, sem partículas.

---

## 8. Ícones

Material Symbols Outlined (ligadura pelo nome): `person`, `visibility_off`, `visibility`, `group`, `mail`,
`settings`, `shopping_cart`, `logout`, `login`, `headset_mic`, `bug_report`, `chevron_right`, `chevron_left`,
`add`, `check_circle`, `lock`, `star`, `military_tech`, `medical_services`, `bolt`, `water_drop`,
`restaurant`, `thermostat`, `favorite`, `shield`, `map`, `explore`, `flag`, `radar`, `my_location`,
`signal_cellular_alt`, `skull`, `swords`, `sports_esports`, `desktop_windows`, `volume_up`, `dashboard`,
`mic`, `account_circle`, `schedule`, `warning`, `handshake`, `inventory_2`, `backpack`, `local_fire_department`.
Glifos customizados no sprite (`svg('id')`): `emblem` (caveira+asas+estrela), `emblem-hex` (caveira em hexágono),
`coin-credit`, `coin-cash`, `coin-points`, `xp`, `class-assault`, `class-scout`, `class-support`, `class-sniper`,
`mk-extraction`, `mk-base`, `mk-camp`, `mk-enemy`, `mk-loot`, `mk-event`, `mk-risk`, `discord`, `twitter`, `youtube`, `instagram`, `steam`, `crown`.

---

## 9. Imagens disponíveis (`assets/img/`, use `img('…')`)

- Fundos `bg/`: `home-hero.jpg` (Home: soldado à direita, torre, heli), `login.jpg`, `menu.jpg` (alt), `jogar-banner.jpg`,
  `lobby.jpg`, `inventario.jpg`, `inventory.jpg`, `saude.jpg`, `conquistas.jpg`, `settings.jpg`, `map-island.jpg` (mapa top-down),
  `forest-generic.jpg`, `admin.jpg`. Todos também em `.webp`.
- `ui/`: `season-pass.jpg` (3:4), `skill-preview.jpg`, `difficulty-preview.jpg`, `mission-sinal-perdido.jpg`, `body-wireframe.png` (alfa).
- `maps/`: `vale-do-eco, base-artica, porto-industrial, cidade-em-ruinas, fabrica-abandonada, deserto-seco, favela, complexo-militar` (.jpg 4:3).
- `regions/`: `region-floresta-verde, region-pantano-cinzento, region-montanhas-gelidas, region-deserto-abandonado, region-zona-industrial` (.jpg).
- `missions/`: `mission-sinal-perdido, mission-suprimentos-medicos, mission-cacada-ao-cacador, mission-carga-valiosa, mission-tecnologia-recuperada` (.jpg).
- `news/`: `news-atualizacao, news-evento-caca, news-manutencao` (.jpg).
- `portraits/`: `op-assault, op-scout, op-sniper, op-support` (.jpg 3:4, corpo inteiro; usar `object-position: top` para busto).
- `items/` (PNG alfa, recortados): `ak103, m4a1, glock17, knife-bayonet, ak-mag, m4-mag, ammo-box, m67-grenade, flashbang,
  helmet-fast, gasmask-gp7, glasses-aviator, vest-83, tactical-rig, clothing-tactical, armband-usec, backpack-45l, medkit,
  bandage, analgesic, surgical-kit, water-bottle, canned-food, dried-meat, cereal-bar, coffee, rope, tools-wrench, tools-generic,
  jerrycan, radio, multitool, compass, sleeping-bag, xray, blood-test, clipboard, supply-crate, keycard, weapon-case, skin-camo, xp-token`.
- `logo/emblem.png` (alfa). Modelo 3D: `assets/models/soldier.glb`. Three.js: `assets/vendor/three/`.

---

## 10. Segurança (obrigatório)

- Nunca `echo` de dado sem `e()`. URLs só via `url()`/`asset()`/`img()`.
- Todo `<form method="post">` inclui `csrf_field()`; o controller chama `Security::checkCsrf()` antes de agir.
- Scripts e estilos inline só com `nonce="<?= nonce() ?>"` (CSP `script-src 'self' 'nonce-…'`).
- Sem `eval`, sem `include` com variável de request, sem `$_GET` cru (usar `Request`).
- `.htaccess` bloqueia `app/`, `tools/`, `captures/`, `.env`, `*.md`, `*.json` fora de `assets/`.

---

## 11. Critérios de aprovação por tela (crítico)

Nota ≥ 9,0 em cada eixo: layout/geometria (posições e proporções batem com a referência ±1%), tipografia
(família, peso, caixa, tamanho, espaçamento), cor/glow (paleta e intensidade), bordas/painéis, imagens/ícones
(nítidos, alinhados, sem fundo quadrado), animação (boot, hover, active, sliders), sensação AAA. Zero erros de
console, zero 404, todo link resolve 200, topbar/footer idênticos entre telas.
