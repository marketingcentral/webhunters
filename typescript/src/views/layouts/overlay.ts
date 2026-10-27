/**
 * Layout OVERLAY — HUD in-game (app/Views/layouts/overlay.php).
 *
 * Difere do layout `hud` em três coisas que importam quando a página vira overlay do jogo:
 *   1. fundo TRANSPARENTE (nada de background, vinheta ou canvas do three.js);
 *   2. sem topbar/rodapé/flash — só o conteúdo da página;
 *   3. `pointer-events: none` no corpo (overlay.css), para o clique atravessar para o jogo.
 *
 * Roda dentro do CEF (Chromium 90) da Unreal: o HTML sai idêntico ao do PHP.
 */
import { APP_NAME, BASE_URL } from '../../core/config';
import { asset, assetExists } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import { nonce } from '../../core/security';
import type { Scope } from '../../core/view';

/** Fontes pré-carregadas (só as duas que o overlay usa). */
const PRELOAD_FONTS = ['barlow-condensed-5.woff2', 'share-tech-mono-0.woff2'];

export function overlay(s: Scope, content: SafeHtml): string {
  const slug = String(s.page.slug ?? 'hud').replace(/[^a-z0-9_-]/g, '') || 'hud';
  const t = String(s.page.title ?? '').trim();
  const title = t !== '' ? t + ' · ' + APP_NAME : APP_NAME;
  const n = nonce();

  // Só o essencial: tokens (cores/tipografia) e o css da própria página. Sem hud.css,
  // topbar.css nem components.css — eles pintam fundos que quebrariam a transparência.
  const styles = ['fonts/fonts.css', 'css/tokens.css', 'css/overlay.css', 'css/pages/' + slug + '.css'];
  const scripts = ['js/pages/' + slug + '.js'];

  const fontes = join(
    PRELOAD_FONTS.filter((f) => assetExists('fonts/' + f)).map(
      (f) => html`<link rel="preload" as="font" type="font/woff2" crossorigin href="${asset('fonts/' + f, false)}">
`,
    ),
  );
  const folhas = join(
    styles.filter(assetExists).map(
      (css) => html`<link rel="stylesheet" href="${asset(css)}">
`,
    ),
  );
  const js = join(
    scripts.filter(assetExists).map(
      (src) => html`<script type="module" nonce="${n}" src="${asset(src)}"></script>
`,
    ),
  );

  return html`<!DOCTYPE html>
<html lang="pt-BR" class="overlay">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<meta name="color-scheme" content="dark">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="${s.csrf ?? ''}">
${fontes}${folhas}</head>
<body class="overlay-body page-${slug}" data-page="${slug}" data-base="${BASE_URL}">
${content}
${js}</body>
</html>
`.value;
}
