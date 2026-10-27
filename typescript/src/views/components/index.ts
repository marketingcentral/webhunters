/**
 * Componentes visuais — app/Views/components/*.php. Cada função recebe as mesmas props
 * documentadas no componente PHP de mesmo nome e devolve SafeHtml.
 */
import * as GR from '../../core/game-rules';
import { icon, media, num, svg } from '../../core/helpers';
import { e, html, raw, SafeHtml } from '../../core/html';
import type { Dict } from '../../core/view';
import * as OperationalRepo from '../../repo/operational-repo';

const CORES_CHIP = ['lime', 'gold', 'cyan', 'amber', 'red', 'purple', 'muted'];

/** component('hex') — selo hexagonal. */
export function hex(p: Dict = {}): SafeHtml {
  const size = ['sm', 'md', 'lg', 'xl', 'xxl'].includes(p.size) ? p.size : 'md';
  const color = ['lime', 'gold', 'cyan', 'amber', 'red', 'purple', 'muted', 'white'].includes(p.color) ? p.color : 'lime';
  let cls = 'hex hex--' + size;
  if (color !== 'lime') cls += ' hex--' + color;
  if (p.hot) cls += ' hex--hot';
  if (p.ring) cls += ' hex--ring';
  if (p.flat) cls += ' hex--flat';
  const temTexto = p.text !== undefined && p.text !== null && p.text !== '';
  if (temTexto) cls += ' hex--text';
  if (p.class) cls += ' ' + p.class;
  let attrs = '';
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ` ${e(k)}="${e(String(v))}"`;
  }
  if (p.title) attrs += ` title="${e(p.title)}" aria-label="${e(p.title)}"`;
  const dentro = p.svg ? svg(String(p.svg)) : p.icon ? icon(String(p.icon)) : temTexto ? html`<span class="num">${String(p.text)}</span>` : '';
  return raw(`<span class="${e(cls)}"${attrs}><span class="hex__in">${String(dentro)}</span></span>`);
}

/** component('chip') — etiqueta. */
export function chip(p: Dict = {}): SafeHtml {
  let cls = 'chip';
  if (CORES_CHIP.includes(p.color)) cls += ' chip--' + p.color;
  if (p.solid) cls += ' chip--solid';
  if (p.dot) cls += ' chip--dot';
  if (p.lg) cls += ' chip--lg';
  if (p.class) cls += ' ' + p.class;
  const tip = p.tip ? ` data-tip="${e(String(p.tip))}"` : '';
  const ic = p.svg ? svg(String(p.svg)) : p.icon ? icon(String(p.icon)) : '';
  return raw(`<span class="${e(cls)}"${tip}>${String(ic)}${e(String(p.label ?? ''))}</span>`);
}

/** component('badge') — os badges de contexto operacional do PRD v3 §37 (ver o .php). */
export function badge(p: Dict = {}): SafeHtml {
  const kind = String(p.kind ?? 'state');
  const v = p.value ?? null;
  let label: string | null = null;
  let color = 'muted';
  let tip = String(p.tip ?? '');
  let ic: string | null = null;
  let sv: string | null = null;
  let extra = '';

  switch (kind) {
    case 'state': {
      const b: OperationalRepo.Badge =
        v && typeof v === 'object' && 'label' in v && 'color' in v
          ? (v as OperationalRepo.Badge)
          : OperationalRepo.badge(v && typeof v === 'object' ? (v as OperationalRepo.Operational) : OperationalRepo.defaults(), Number(p.level ?? 0));
      label = b.label;
      if (p.sub) label += ' · ' + b.sub;
      color = b.color;
      if (b.survivor) extra += ' chip--survivor';
      else if ((p.icon ?? true) && b.state === GR.STATE_HUNTER) sv = 'emblem';
      break;
    }
    case 'faction': {
      const f = GR.faction(typeof v === 'string' ? v : null);
      label = f?.label ?? String(v ?? '').toUpperCase();
      color = f?.color ?? 'muted';
      break;
    }
    case 'rank':
      label = String(v ?? '').toUpperCase();
      color = GR.rankIndex(label) >= 3 ? 'lime' : 'muted';
      break;
    case 'role': {
      const key = GR.role(typeof v === 'string' ? v : null);
      const r = key !== null ? GR.ROLES[key]! : null;
      label = r?.label ?? String(v ?? '').toUpperCase();
      color = 'lime';
      if (r && (p.icon ?? true)) sv = r.icon;
      if (r && tip === '') tip = r.desc;
      break;
    }
    case 'activity':
      label = String(v ?? '').toUpperCase();
      color = GR.activityColor(label);
      break;
    case 'base':
      label = String(v ?? '').toUpperCase();
      color = GR.BASE_STATUS[label] ?? 'muted';
      break;
    case 'extraction': {
      const type = v && typeof v === 'object' ? String((v as Dict).type ?? '') : String(v ?? '');
      const state = v && typeof v === 'object' ? String((v as Dict).state ?? '') : '';
      const t = GR.EXTRACTION_TYPES[type.toLowerCase()] ?? null;
      const s = GR.EXTRACTION_STATES[state.toLowerCase()] ?? null;
      label = s !== null && t === null ? s.label : t?.label ?? type.toUpperCase();
      color = state === 'blocked' ? 'muted' : s !== null && t === null ? s.color : t?.color ?? 'muted';
      if (state === 'blocked' && tip === '') tip = GR.SURVIVOR_EXTRACTION_TIP;
      else if (t !== null && tip === '') tip = t.who;
      break;
    }
    case 'requirement': {
      const fs = v && typeof v === 'object' ? String((v as Dict).faction ?? '') : String(v ?? '');
      const tier = v && typeof v === 'object' ? String((v as Dict).tier ?? '') : '';
      const f = GR.faction(fs);
      const fl = fs === '' ? 'MERCENÁRIO' : f?.label ?? fs.toUpperCase();
      label = tier !== '' ? fl + ' — ' + tier.toUpperCase() : 'REQUER CONTRATO ' + fl;
      color = f?.color ?? 'muted';
      ic = 'lock';
      if (tip === '') {
        const com = fs === '' ? 'qualquer facção' : fl;
        tip = tier !== '' ? `Reputação mínima com ${com}: ${tier.toUpperCase()}.` : `Exige contrato mercenário ativo com ${com}.`;
      }
      break;
    }
    case 'origin': {
      const o = GR.origin(typeof v === 'string' ? v : null);
      label = o.label;
      color = o.color;
      break;
    }
    case 'discipline':
      label = String(v ?? '').toUpperCase();
      color = { REGULAR: 'lime', 'EM REVISÃO': 'amber', PUNIDO: 'red' }[label] ?? 'muted';
      break;
  }
  if (p.label !== undefined) label = String(p.label);
  const chipColor = CORES_CHIP.includes(color) ? color : '';
  return chip({
    label: label ?? '',
    color: chipColor,
    svg: sv,
    icon: ic,
    lg: Boolean(p.lg),
    solid: Boolean(p.solid),
    dot: Boolean(p.dot),
    tip,
    class: ('badge badge--' + e(kind) + extra + ' ' + String(p.class ?? '')).trim(),
  });
}

/** component('logo') — hero (com tagline), auth (sem) ou mark (só emblema). Arte servida do R2 via media(). */
export function logo(p: Dict = {}): SafeHtml {
  const variant = ['hero', 'auth', 'mark'].includes(p.variant) ? p.variant : 'hero';
  const href = p.href ?? null;
  const extra = String(p.class ?? '').trim();
  const tag = href ? 'a' : 'div';
  const classes = 'logo logo--' + variant + (extra !== '' ? ' ' + extra : '');
  const file = variant === 'hero' ? 'logo/logo-final' : 'logo/logo-final-sm';
  const dentro =
    variant === 'mark'
      ? html`
  <span class="logo__emblem" aria-hidden="true"></span>
`
      : html`
  <picture class="logo__art">
    <source srcset="${media(file + '.webp')}" type="image/webp">
    <img src="${media(file + '.png')}" alt="" decoding="async"
         ${raw(variant === 'hero' ? 'fetchpriority="high"' : 'loading="lazy"')}>
  </picture>
${variant === 'hero' ? raw('  <span class="logo__tag" aria-hidden="true"><span>Sobreviva. Extraia. Evolua.</span></span>\n') : ''}`;
  return html`<${raw(tag)} class="${classes}"${href ? html` href="${String(href)}"` : ''} role="img" aria-label="Extract Hunters">${dentro}</${raw(tag)}>
`;
}

/** component('carousel') — os slides já chegam como HTML pronto. */
export function carousel(p: Dict = {}): SafeHtml {
  const slides = (p.slides ?? []) as SafeHtml[];
  let cls = 'carousel';
  if (p.over) cls += ' carousel--dots-over';
  if (p.class) cls += ' ' + p.class;
  return html`<div class="${cls}" data-carousel data-interval="${Number(p.interval ?? 5000) | 0}"${p.fixed ? raw(' data-height="fixed"') : ''}${p.id ? html` id="${String(p.id)}"` : ''} aria-label="${String(p.label ?? 'Carrossel')}" tabindex="0">
  <div class="carousel__track">
${slides.map((s) => html`    <div class="carousel__slide">${s}</div>
`)}  </div>
${p.arrows ? raw(`  <button class="carousel__prev ms" type="button" aria-label="Anterior">chevron_left</button>
  <button class="carousel__next ms" type="button" aria-label="Próximo">chevron_right</button>
`) : ''}${(p.dots ?? true) ? raw('  <div class="carousel__dots" aria-hidden="true"></div>\n') : ''}</div>
`;
}

/** component('progress') — barra; com `label` vira a linha rotulada. */
export function progress(p: Dict = {}): SafeHtml {
  const value = Number(p.value ?? 0);
  const max = Number(p.max ?? 100);
  let pct = p.pct !== undefined ? Number(p.pct) : max > 0 ? (value / max) * 100 : 0;
  pct = Math.max(0, Math.min(100, pct));
  let cls = 'progress';
  if (['cyan', 'amber', 'gold', 'red', 'purple', 'white'].includes(p.color)) cls += ' progress--' + p.color;
  if (['thin', 'thick', 'xl'].includes(p.size)) cls += ' progress--' + p.size;
  if (p.anim) cls += ' progress--anim';
  if (p.seg) cls += ' progress--seg';
  if (p.flat) cls += ' progress--flat';
  if (p.class) cls += ' ' + p.class;
  let attrs = '';
  for (const [k, v] of Object.entries((p.attrs as Dict) ?? {})) {
    if (/^[a-zA-Z][\w:-]*$/.test(k)) attrs += ` ${e(k)}="${e(String(v))}"`;
  }
  const pctStr = pct.toFixed(2).replace(/\.?0+$/, '') + '%';
  const bar = raw(
    `<div class="${e(cls)}" style="--v:${e(pctStr)}" role="progressbar" aria-valuemin="0" aria-valuemax="${e(String(max))}" aria-valuenow="${e(String(value))}"${attrs}><div class="progress__bar"></div></div>`,
  );
  if (!p.label) return html`${bar}
`;
  const val =
    p.suffix !== undefined && p.suffix !== ''
      ? html`${num(value)}${String(p.suffix)}`
      : html`${num(value)}<small>/${num(max)}</small>`;
  return html`<div class="progress-row">
  <div class="progress-row__head">
    <span class="progress-row__label">${String(p.label)}</span>
${(p.show ?? true) ? html`    <span class="progress-row__val num">${val}</span>
` : ''}  </div>
  ${bar}
</div>
`;
}

/** component('infestation') — medidor de infestação Veyra com a escala do canon. */
export function infestation(p: Dict = {}): SafeHtml {
  const pct = Math.max(0, Math.min(100, Number(p.value ?? 0) | 0));
  const esc = GR.infestation(pct);
  const head = 'label' in p ? String(p.label) : 'INFESTAÇÃO VEYRA';
  const cls = ('infest infest--' + e(esc.color) + (p.compact ? ' infest--compact' : '') + ' ' + String(p.class ?? '')).trim();
  return html`<div class="${raw(cls)}" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}" aria-label="${head !== '' ? head : 'Infestação Veyra'}">
${head !== '' ? html`  <span class="infest__label">${head}</span>
` : ''}  <span class="infest__val num">${pct}<small>%</small></span>
  <span class="infest__state">${esc.label}</span>
${!p.compact ? html`  ${progress({ value: pct, max: 100, color: esc.color === 'lime' ? '' : esc.color, anim: true, class: 'infest__bar' })}` : ''}</div>
`;
}

/** Os demais componentes (panel, slot, stat, tabs...) entram conforme as telas forem portadas. */
