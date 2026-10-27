import { ctx } from './context';
import { asset, assetExists } from './helpers';
import type { SafeHtml } from './html';

/**
 * Renderização de página dentro de layout — o App\Core\View do PHP.
 *
 * No PHP a view era um arquivo incluído com extract() das chaves permitidas. Aqui cada view
 * é uma função que recebe o mesmo escopo ($page, $player, $nav, $server, $data, $flash,
 * $csrf) e devolve SafeHtml. O registro de views fica em views/registry.ts.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type Dict = Record<string, any>;

export interface Page extends Dict {
  slug: string;
  title: string;
  bg: string;
  bg_pos: string;
  nav: string;
  back: { label: string; href: string | null } | null;
  css: string[];
  js: string[];
  three: { atmosphere?: boolean; hero?: boolean; map?: boolean };
  description: string;
}

export interface Scope {
  page: Page;
  player: Dict;
  nav: Dict[];
  server: Dict;
  data: Dict;
  flash: Record<string, string>;
  csrf: string;
}

export type PageView = (s: Scope) => SafeHtml | Promise<SafeHtml>;
export type LayoutView = (s: Scope, content: SafeHtml) => string | Promise<string>;

export interface PageAssets {
  slug: string;
  shellCss: string[];
  pageCss: string[];
  shellJs: string[];
  onceJs: string[];
  pageJs: string[];
}

/** O que é casco (fica de pé na navegação sem recarga) e o que é da tela. */
export function pageAssets(page: Partial<Page>): PageAssets {
  const slug = String(page.slug ?? 'pagina').replace(/[^a-z0-9_-]/g, '') || 'pagina';
  const three = page.three ?? {};
  const shellCss = [
    'fonts/fonts.css', 'css/tokens.css', 'css/base.css', 'css/hud.css', 'css/components.css',
    'css/animations.css', 'css/topbar.css', 'css/footer.css', 'css/logo.css', 'css/spa.css',
  ];
  const pageCss = ['css/pages/' + slug + '.css'];
  for (const x of page.css ?? []) {
    let extra = String(x).replace(/^\/+/, '');
    extra = extra.startsWith('css/') ? extra : 'css/' + extra;
    if (!shellCss.includes(extra) && !pageCss.includes(extra)) pageCss.push(extra);
  }
  const shellJs = ['js/carousel.js'];
  const onceJs = three.atmosphere ? ['js/three/atmosphere.js'] : [];
  const pageJs: string[] = [];
  if (three.hero) pageJs.push('js/three/hero3d.js');
  if (three.map) pageJs.push('js/three/map3d.js');
  pageJs.push('js/pages/' + slug + '.js');
  for (const x of page.js ?? []) {
    let extra = String(x).replace(/^\/+/, '');
    extra = extra.startsWith('js/') ? extra : 'js/' + extra;
    if (!shellJs.includes(extra) && !onceJs.includes(extra) && !pageJs.includes(extra)) pageJs.push(extra);
  }
  return { slug, shellCss, pageCss, shellJs, onceJs, pageJs };
}

/** Import map: `three` + os módulos importados por caminho relativo, já versionados. */
export function importMap(): string {
  const imports: Record<string, string> = {
    three: asset('vendor/three/build/three.module.js'),
    'three/addons/': asset('vendor/three/examples/jsm/', false),
  };
  for (const rel of ['js/spa.js', 'js/unreal.js', 'js/carousel.js']) {
    if (assetExists(rel)) imports[asset(rel, false)] = asset(rel);
  }
  // JSON_HEX_TAG | JSON_HEX_AMP
  return JSON.stringify({ imports }).replace(/</g, '\\u003C').replace(/>/g, '\\u003E').replace(/&/g, '\\u0026');
}

/** Guarda a página atual para active() e os parciais. */
export function setCurrentPage(page: Page): void {
  ctx().cache.set('view.page', page);
}
