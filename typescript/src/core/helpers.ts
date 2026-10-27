/**
 * Helpers globais das views — o app/Core/helpers.php. Mesmos nomes (em camelCase), mesmo
 * comportamento; os que devolvem HTML devolvem SafeHtml para o html`` não escapar de novo.
 */
import { BASE_URL } from './config';
import { ctx } from './context';
import { e, raw, SafeHtml } from './html';
import { csrfField as csrfFieldStr } from './security';
import manifesto from '../generated/assets.json';
import mediaManifest from '../generated/media.json';
import { DATA } from '../generated/data-index';

const versoes = (manifesto as { versoes: Record<string, number> }).versoes;
/** O PHP roda em Windows/OLS, onde 'soldier.glb' acha 'Soldier.glb'. No Worker o nome é exato. */
const aliases = (manifesto as { aliases?: Record<string, string> }).aliases ?? {};
const real = (p: string): string => (p in versoes ? p : (aliases[p.toLowerCase()] ?? p));
const mediaV = mediaManifest as Record<string, number>;

type Q = Record<string, string | number | boolean | null | undefined>;

/** BASE_URL + caminho amigável (+ query RFC 3986). url('mapa/floresta-verde', {x: 1}) */
export function url(path = '', q: Q = {}): string {
  let out = BASE_URL + path.replace(/^\/+/, '');
  const pares = Object.entries(q).filter(([, v]) => v !== null && v !== undefined);
  if (pares.length) {
    out += '?' + pares.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join('&');
  }
  return out;
}

/** O arquivo existe dentro de /assets? (View::assetExists) */
export function assetExists(path: string): boolean {
  return real(path.replace(/^\/+/, '')) in versoes;
}

/** BASE_URL/assets/{path}?v={mtime} — o mtime vem do build, igual ao filemtime() do PHP. */
export function asset(path: string, version = true): string {
  const p = real(path.replace(/\\/g, '/').replace(/^\/+/, ''));
  let u = BASE_URL + 'assets/' + p;
  if (version && !p.endsWith('/') && versoes[p] !== undefined) u += '?v=' + versoes[p];
  return u;
}

export function img(path: string): string {
  return asset('img/' + path.replace(/^\/+/, ''));
}

/**
 * Mídia vinda do banco ("items/ak103.png"): /media/{path}?v={mtime}, servida do R2.
 * Cai para assets/img/ quando o arquivo não está em media/ — mesma regra do PHP.
 */
export function media(path: string): string {
  const p = path.replace(/\\/g, '/').replace(/^\/+/, '');
  if (mediaV[p] !== undefined) return BASE_URL + 'media/' + p + '?v=' + mediaV[p];
  if (p.startsWith('uploads/')) return BASE_URL + 'media/' + p; // enviado em produção, fora do manifesto
  return img(p);
}

/** Ligadura Material Symbols. */
export function icon(name: string, cls = '', attrs: Record<string, unknown> = {}): SafeHtml {
  const n = name.toLowerCase().replace(/[^a-z0-9_]/g, '');
  const c = ('ms ' + cls).trim();
  let extra = '';
  for (const [k0, v] of Object.entries(attrs)) {
    const k = k0.replace(/[^a-zA-Z0-9_:-]/g, '');
    if (k === '' || k === 'class') continue;
    extra += ` ${k}="${e(v)}"`;
  }
  if (!('aria-label' in attrs) && !('role' in attrs)) extra += ' aria-hidden="true"';
  return raw(`<span class="${e(c)}"${extra}>${e(n)}</span>`);
}

/** Glifo do sprite SVG. */
export function svg(id: string, cls = ''): SafeHtml {
  const i = id.toLowerCase().replace(/[^a-z0-9_-]/g, '');
  const c = ('ic ' + cls).trim();
  return raw(
    `<svg class="${e(c)}" aria-hidden="true" focusable="false"><use href="${e(asset('icons/sprite.svg') + '#' + i)}"/></svg>`,
  );
}

export function csrfField(): SafeHtml {
  return raw(csrfFieldStr());
}

/** Número pt-BR: num(125500) = "125.500", num(2.5, 1) = "2,5" (number_format). */
export function num(n: unknown, dec = 0): string {
  const v = Number(n) || 0;
  const fixo = Math.abs(v).toFixed(dec);
  const [int, frac] = fixo.split('.');
  const milhar = int!.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const sinal = v < 0 && Number(fixo) !== 0 ? '-' : '';
  return sinal + milhar + (frac ? ',' + frac : '');
}

export function attrClass(...classes: Array<string | false | null | undefined>): string {
  return classes
    .map((c) => (c || '').trim())
    .filter((c) => c !== '')
    .join(' ');
}

/** app/Data/{name}.php, exportado para JSON (scripts/export-data.php). */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function dataMock(name: string): any {
  if (!/^[a-z0-9_-]+$/.test(name)) return {};
  const d = (DATA as Record<string, unknown>)[name];
  // cópia: as views às vezes mexem no array, e o módulo é compartilhado entre requisições
  return d === undefined ? {} : structuredClone(d);
}

/** flash('ok', 'msg') grava para a próxima requisição; flash('ok') lê e consome. */
export function flash(key: string, msg?: string): string | null {
  const s = ctx().session;
  const fila = { ...((s.get<Record<string, string>>('_flash')) ?? {}) };
  if (msg !== undefined) {
    fila[key] = msg;
    s.set('_flash', fila);
    return null;
  }
  const consumidos = (ctx().cache.get('_flash_lidos') as Record<string, string>) ?? {};
  if (key in fila) {
    consumidos[key] = String(fila[key]);
    delete fila[key];
    if (Object.keys(fila).length) s.set('_flash', fila);
    else s.delete('_flash');
    ctx().cache.set('_flash_lidos', consumidos);
  }
  return consumidos[key] ?? null;
}

export function flashAll(): Record<string, string> {
  const out: Record<string, string> = {};
  for (const k of Object.keys(ctx().session.get<Record<string, string>>('_flash') ?? {})) {
    const v = flash(k);
    if (v !== null) out[k] = v;
  }
  return out;
}

/** ' is-active' quando a página atual (nav ou slug) é $slug. */
export function active(slug: string): string {
  const page = (ctx().cache.get('view.page') as Record<string, unknown>) ?? {};
  return String(page.nav ?? page.slug ?? '') === slug ? ' is-active' : '';
}

/**
 * Id do usuário logado; 0 sem login. O atalho de QA do PHP (cair no menor id em localhost)
 * NÃO foi portado: o LoginGuard já barra anônimo antes de qualquer controller.
 */
export function currentUserId(): number {
  return Number(ctx().session.get('user_id') ?? 0) || 0;
}

export function isAuthenticated(): boolean {
  return currentUserId() > 0;
}

/** Glifo do mapa (<svg> inline) ou o brasão de uma organização. */
export function mapGlyph(id: string, cls = '', set: Record<string, string> | null = null): SafeHtml {
  const emblemas: Record<string, string> = { 'gov-base': 'hunter', aegis: 'aegis', frontier: 'frontier' };
  if (set === null && emblemas[id]) {
    const arq = 'img/emblemas/' + emblemas[id] + '-sm';
    return raw(
      `<picture class="gl gl--emblema${cls !== '' ? ' ' + e(cls) : ''}">` +
        `<source srcset="${e(asset(arq + '.webp'))}" type="image/webp">` +
        `<img src="${e(asset(arq + '.png'))}" alt="" loading="lazy" decoding="async">` +
        '</picture>',
    );
  }
  const s = set ?? (DATA['map_glyphs'] as Record<string, string>);
  if (!s[id]) return raw('');
  return raw(
    `<svg class="gl${cls !== '' ? ' ' + e(cls) : ''}" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor">${s[id]}</svg>`,
  );
}

/** Ícone de um marcador do catálogo de pontos (glyph:/symbol:/image:), com fallback no kind. */
export function mapPointIcon(m: Record<string, unknown>, fallback: string): SafeHtml {
  const p = String(m.icon ?? '').split(':');
  if (p.length >= 2) {
    const tipo = p[0]!;
    const v = p.slice(1).join(':');
    if (v !== '') {
      if (tipo === 'symbol') return icon(v, 'mk__ms');
      if (tipo === 'image') return raw(`<img class="mk__art" src="${e(media(v))}" alt="" loading="lazy" decoding="async">`);
      if (tipo === 'glyph') {
        const g = mapGlyph(v);
        if (g.value !== '') return g;
      }
    }
  }
  return mapGlyph(fallback);
}

/** Retrato de criatura (assets/img/criaturas/<slug>.webp|.jpg) ou null se ainda não há arte. */
export function creatureImg(slug: string): string | null {
  if (!/^[a-z0-9-]+$/.test(slug)) return null;
  for (const ext of ['webp', 'jpg']) {
    const p = 'img/criaturas/' + slug + '.' + ext;
    if (assetExists(p)) return asset(p);
  }
  return null;
}
