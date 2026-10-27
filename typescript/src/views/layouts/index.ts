/**
 * Layouts — app/Views/layouts/{hud,auth,partial,landing}.php.
 */
import { APP_NAME, BASE_URL } from '../../core/config';
import { asset, assetExists, img } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import { nonce } from '../../core/security';
import { importMap, pageAssets, type Scope } from '../../core/view';
import { background, flashStack, fontPreloads, footer, topbar } from '../partials/shell';

function titulo(s: Scope): string {
  const t = String(s.page.title ?? '').trim();
  return t !== '' ? t + ' · ' + APP_NAME : APP_NAME;
}

function descricao(s: Scope): string {
  return String(s.page.description || 'Extract Hunters - sobreviva, extraia, evolua.');
}

function bgPreload(s: Scope): SafeHtml {
  const bg = String(s.page.bg ?? '').replace(/^\/+/, '');
  return bg !== '' && assetExists('img/' + bg) ? html`<link rel="preload" as="image" href="${img(bg)}">
` : raw('');
}

function folhas(lista: string[], marcarSpa = false): SafeHtml {
  return join(
    lista.map((css) =>
      assetExists(css)
        ? marcarSpa
          ? html`<link rel="stylesheet" href="${asset(css)}" data-spa-css="${asset(css)}">
`
          : html`<link rel="stylesheet" href="${asset(css)}">
`
        : marcarSpa
          ? ''
          : html`<!-- assets/${css} ainda não existe -->
`,
    ),
  );
}

function scripts(lista: string[], n: string): SafeHtml {
  return join(
    lista.map((js) =>
      assetExists(js)
        ? html`<script type="module" nonce="${n}" src="${asset(js)}"></script>
`
        : html`<!-- assets/${js} ainda não existe -->
`,
    ),
  );
}

function hudJs(n: string): SafeHtml {
  return assetExists('js/hud.js')
    ? html`<script type="module" nonce="${n}" src="${asset('js/hud.js')}"></script>
`
    : raw('<!-- assets/js/hud.js ainda não existe -->\n');
}

/** Layout HUD (casco do jogo). A classe da página vai também no <html> (CEF 90 não tem :has()). */
export function hud(s: Scope, content: SafeHtml): string {
  const n = nonce();
  const pa = pageAssets(s.page);
  const js = [...pa.shellJs, ...pa.onceJs, ...pa.pageJs];
  return html`<!DOCTYPE html>
<html lang="pt-BR" class="page-${pa.slug}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${titulo(s)}</title>
<meta name="description" content="${descricao(s)}">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="${s.csrf ?? ''}">
<link rel="icon" type="image/png" href="${img('logo/emblem.png')}">
${fontPreloads()}${bgPreload(s)}${folhas(pa.shellCss)}${folhas(pa.pageCss, true)}</head>
<body class="page-${pa.slug}" data-page="${pa.slug}" data-base="${BASE_URL}" data-nonce="${n}">
${background(s)}<div class="hud">
${topbar(s)}<main class="hud-main" id="main">
${flashStack(s.flash)}${content}
</main>
${footer(s)}</div>
<div class="page-transition" aria-hidden="true"></div>
<script type="importmap" nonce="${n}">${raw(importMap())}</script>
${hudJs(n)}${scripts(js, n)}</body>
</html>
`.value;
}

/** Layout AUTH (login, criar-conta, recuperar-senha, erros): sem topbar, rodapé mínimo. */
export function auth(s: Scope, content: SafeHtml): string {
  const n = nonce();
  const slug = String(s.page.slug ?? 'auth').replace(/[^a-z0-9_-]/g, '') || 'auth';
  const three = s.page.three ?? {};
  const styles = [
    'fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/hud.css', 'css/components.css',
    'css/animations.css', 'css/topbar.css', 'css/footer.css', 'css/logo.css', 'css/spa.css',
    'css/pages/' + slug + '.css',
  ];
  for (const x of s.page.css ?? []) {
    let extra = String(x).replace(/^\/+/, '');
    extra = extra.startsWith('css/') ? extra : 'css/' + extra;
    if (!styles.includes(extra)) styles.push(extra);
  }
  const js = ['js/carousel.js'];
  if (three.atmosphere) js.push('js/three/atmosphere.js');
  if (three.hero) js.push('js/three/hero3d.js');
  if (three.map) js.push('js/three/map3d.js');
  js.push('js/pages/' + slug + '.js');
  for (const x of s.page.js ?? []) {
    let extra = String(x).replace(/^\/+/, '');
    extra = extra.startsWith('js/') ? extra : 'js/' + extra;
    if (!js.includes(extra)) js.push(extra);
  }
  return html`<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${titulo(s)}</title>
<meta name="description" content="${descricao(s)}">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="${s.csrf ?? ''}">
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" href="${img('logo/emblem.png')}">
${fontPreloads()}${bgPreload(s)}${folhas(styles)}</head>
<body class="page-${slug} is-auth" data-page="${slug}" data-base="${BASE_URL}" data-nonce="${n}">
${background(s)}<div class="hud hud--auth">
<main class="auth-main" id="main">
${flashStack(s.flash)}${content}
</main>
${footer(s, { minimal: true })}</div>
<div class="page-transition" aria-hidden="true"></div>
<script type="importmap" nonce="${n}">${raw(importMap())}</script>
${hudJs(n)}${scripts(js, n)}</body>
</html>
`.value;
}

/** Resposta da navegação sem recarga (docs/NAVEGACAO-SPA.md): JSON com os fragmentos. */
export function partial(s: Scope, content: SafeHtml): string {
  const pa = pageAssets(s.page);
  const urls = (lista: string[]) => lista.filter(assetExists).map((rel) => asset(rel));
  return JSON.stringify({
    ok: true,
    layout: 'hud',
    slug: pa.slug,
    title: titulo(s),
    bodyClass: 'page-' + pa.slug,
    csrf: String(s.csrf ?? ''),
    background: background(s).value,
    topbar: topbar(s).value,
    main: flashStack(s.flash, true).value + content.value,
    footer: footer(s).value,
    css: urls(pa.pageCss),
    js: urls(pa.pageJs),
    jsOnce: urls(pa.onceJs),
  });
}

/** Layout LANDING (/): o único casco que rola e é público; robots INDEX. */
export function landing(s: Scope, content: SafeHtml): string {
  const n = nonce();
  const slug = 'landing';
  const title = String(s.page.title ?? '').trim() || APP_NAME;
  const desc = descricao(s);
  const styles = ['fonts/fonts.css', 'css/tokens.css', 'css/pages/mapa.css', 'css/pages/' + slug + '.css'];
  const js = ['js/three/map3d.js', 'js/pages/' + slug + '.js'];
  return html`<!DOCTYPE html>
<html lang="pt-BR" class="page-${slug}" data-estado="hunter">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${title}</title>
<meta name="description" content="${desc}">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#070907">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website">
<meta property="og:title" content="EXTRACT HUNTERS">
<meta property="og:description" content="${desc}">
<meta property="og:image" content="${img('bg/home-hero-wide.jpg')}">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/png" href="${img('logo/emblem.png')}">
${fontPreloads()}${bgPreload(s)}${folhas(styles)}</head>
<body class="page-${slug}" data-page="${slug}" data-base="${BASE_URL}" data-nonce="${n}">
${content}
<script type="importmap" nonce="${n}">${raw(importMap())}</script>
${scripts(js, n)}</body>
</html>
`.value;
}
