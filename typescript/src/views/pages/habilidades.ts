/**
 * Tela HABILIDADES — app/Views/pages/habilidades.php (referência 07-habilidades.png).
 * $data: title, points_label, points, redistribute, reset_category, unlocked_label, level_label,
 *        categories[], tiers[], tree (tiers x colunas), active_category, category, selected,
 *        learned, learned_all.
 */
import { csrfField, icon, media, svg, url } from '../../core/helpers';
import { e, html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { hex } from '../components/index';
import { row } from '../components/row';

/** rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') */
const fmt = (n: number): string => n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');

/** Texto escapado com o primeiro trecho destacado em <span class="lime"> (igual ao strpos do PHP). */
function destaque(texto: string, realce: string): SafeHtml {
  const t = e(texto);
  const h = e(realce);
  const p = h !== '' ? t.indexOf(h) : -1;
  return raw(p < 0 ? t : t.slice(0, p) + '<span class="lime">' + h + '</span>' + t.slice(p + h.length));
}

export function habilidades(s: Scope): SafeHtml {
  const data = s.data;
  const cats = (data.categories ?? []) as Dict[];
  const active = String(data.active_category ?? 'combate');
  const category: Dict = data.category ?? {};
  const tiers = (data.tiers ?? ['I', 'II', 'III', 'IV']) as string[];
  const tree = (data.tree ?? []) as Dict[][];
  const sel: Dict = data.selected ?? {};
  const redis: Dict = data.redistribute ?? {};
  const resetCat: Dict = data.reset_category ?? {};
  const points = Number(data.points ?? 0) | 0;

  /* --- geometria da árvore (px da referência 1672x941) ----------------------- */
  const NW = 84.0, NH = 81.0, CP = 150.5, RP = 155.6667;
  const rows = Math.max(1, tree.length);
  const cols = 5;
  const vbW = (cols - 1) * CP + NW; // 686
  const vbH = (rows - 1) * RP + NH; // 548
  const colX = (i: number): number => i * CP;
  const ctrX = (i: number): number => i * CP + 42.0;
  const rowY = (j: number): number => j * RP;
  const stateOf = (j: number, i: number): string => String(tree[j]?.[i]?.state ?? 'locked');

  /* --- conectores SVG (setas horizontais, trilhos e descidas verticais) ------ */
  const off: string[] = [];
  const on: string[] = [];
  const rail: string[] = [];
  const AH = 6.4, AW = 3.2; // ponta de seta (~20% menor que antes)
  const arrowR = (x1: number, x2: number, y: number): string => {
    const tip = x2, b = x2 - AH;
    return `<line x1="${fmt(x1)}" y1="${fmt(y)}" x2="${fmt(b + 1)}" y2="${fmt(y)}"/>` +
      `<polygon points="${fmt(tip)},${fmt(y)} ${fmt(b)},${fmt(y - AW)} ${fmt(b)},${fmt(y + AW)}"/>`;
  };
  const arrowD = (x: number, y1: number, y2: number): string => {
    const tip = y2, b = y2 - AH;
    return `<line x1="${fmt(x)}" y1="${fmt(y1)}" x2="${fmt(x)}" y2="${fmt(b + 1)}"/>` +
      `<polygon points="${fmt(x)},${fmt(tip)} ${fmt(x - AW)},${fmt(b)} ${fmt(x + AW)},${fmt(b)}"/>`;
  };

  for (let j = 0; j < rows; j++) {
    const y = rowY(j) + NH / 2;
    for (let i = 0; i < cols - 1; i++) {
      const lit = stateOf(j, i) !== 'locked' && stateOf(j, i + 1) !== 'locked';
      const seg = arrowR(colX(i) + NW + 6, colX(i + 1) - 6, y);
      (lit ? on : off).push(seg);
    }
    if (j < rows - 1) {
      const railY = rowY(j + 1) - 32.0;
      rail.push(`<line x1="${fmt(ctrX(0))}" y1="${fmt(railY)}" x2="${fmt(ctrX(cols - 1))}" y2="${fmt(railY)}"/>`);
      for (let i = 0; i < cols; i++) {
        const node: Dict = tree[j]?.[i] ?? {};
        const state = stateOf(j, i);
        const lit = state !== 'locked' && stateOf(j + 1, i) !== 'locked';
        const start = (Number(node.cost ?? 0) | 0) > 0 && state === 'locked'
          ? railY
          : rowY(j) + NH + (state === 'progress' ? 15.0 : 5.0);
        const seg = arrowD(ctrX(i), start, rowY(j + 1) - 5.0);
        (lit ? on : off).push(seg);
      }
    }
  }

  const no = (n: Dict, j: number, i: number): SafeHtml => {
    const state = String(n.state ?? 'locked');
    const cost = Number(n.cost ?? 0) | 0;
    const isSel = String(n.slug ?? '') === String(sel.slug ?? '') || Boolean(n.selected);
    const cur = Number(n.cur) | 0;
    const max = Number(n.max) | 0;
    const ni = String(n.icon);
    return html`          <button
            class="sk-node sk-node--${state}${isSel ? ' is-selected' : ''}"
            type="button"
            data-node="${String(n.slug)}"
            data-name="${String(n.name)}"
            data-state="${state}"
            data-cur="${cur}"
            data-max="${max}"
            data-cost="${cost}"
            data-desc="${String(n.desc ?? '')}"
            data-available="${n.available ? '1' : '0'}"
            title="${String(n.name)}"
            aria-pressed="${isSel ? 'true' : 'false'}"
            style="grid-area:${j + 1}/${i + 1}">
            <span class="sk-node__box">
              ${ni.startsWith('sk-') ? svg(ni, 'sk-node__ic sk-node__ic--svg') : icon(ni, 'sk-node__ic')}
              <span class="sk-node__count num">${cur}/${max}</span>
${state !== 'unlocked' ? html`              <span class="sk-node__lock" aria-hidden="true">${icon('lock')}</span>
` : ''}            </span>
            <span class="sk-node__name sr-only">${String(n.name)}</span>
${state === 'progress' ? html`            <span class="sk-node__bar" aria-hidden="true">
${join(Array.from({ length: max }, (_, k) => html`              <i class="sk-node__seg${k < cur ? ' is-on' : ''}" style="--k:${k}"></i>
`))}            </span>
` : ''}${cost > 0 && state === 'locked' ? html`            <span class="sk-node__cost">
              ${svg('coin-points', 'sk-node__cost-ic')}
              <span class="num">${cost}</span>
            </span>
` : ''}          </button>
`;
  };

  const canLearn = Boolean(sel.can_learn);
  const why = String(sel.reason ?? '');
  const cta: Dict = sel.cta ?? {};
  const desabilitado = raw(' disabled aria-disabled="true"');

  return html`<div class="hab">

  <!-- ====================== CATEGORIAS ====================== -->
  <section class="panel hab-cats" data-boot>
    <header class="panel__head">
      <h2 class="panel__title">${String(data.title ?? 'CATEGORIAS')}</h2>
    </header>
    <div class="panel__body">

      <nav class="cat-list" data-stagger aria-label="Categorias de habilidade">
${join(cats.map((c) => row({
    title: String(c.label),
    sub: String(c.desc),
    icon: String(c.icon),
    meta: String(c.unlocked) + '/' + String(c.total),
    active: String(c.slug) === active,
    href: url('habilidades/' + String(c.slug)),
    class: 'cat-row cat-row--' + String(c.slug),
  })))}      </nav>

      <div class="points" data-points="${points}">
        <span class="points__label">${String(data.points_label ?? 'PONTOS DISPONÍVEIS')}</span>
        <span class="points__val num" id="sk-points" data-count="${points}" data-dur="900">0</span>
        ${hex({ size: 'sm', color: 'lime', icon: 'keyboard_double_arrow_up', flat: true, class: 'points__hex' })}
      </div>

      <form class="hab-form" method="post" action="${url('habilidades/redefinir')}">
        ${csrfField()}
        <input type="hidden" name="escopo" value="tudo">
        <button class="key-btn key-btn--wide" type="submit"${(Number(data.learned_all ?? 0) | 0) > 0 ? '' : raw(' disabled aria-disabled="true" title="Nenhuma habilidade aprendida"')}>
          <span class="keycap">${String(redis.key ?? 'R')}</span>
          <span class="key-btn__label">${String(redis.label ?? 'REDISTRIBUIR')}</span>
        </button>
      </form>

    </div>
  </section>

  <!-- ====================== ÁRVORE ====================== -->
  <section class="panel hab-tree" data-boot>
    <header class="tree-head">
      <div class="tree-head__id">
        <h1 class="page-title">${String(category.label ?? '')}</h1>
        <p class="tree-head__sub">
          <span class="num lime">${String(category.unlocked ?? 0)}<i class="num-sep"> / </i>${String(category.total ?? 0)}</span>
          ${String(data.unlocked_label ?? 'HABILIDADES DESBLOQUEADAS')}
        </p>
      </div>
      <form class="hab-form" method="post" action="${url('habilidades/redefinir')}">
        ${csrfField()}
        <input type="hidden" name="escopo" value="categoria">
        <input type="hidden" name="categoria" value="${active}">
        <button class="key-btn key-btn--rev" type="submit"${(Number(data.learned ?? 0) | 0) > 0 ? '' : raw(' disabled aria-disabled="true" title="Nenhuma habilidade aprendida nesta categoria"')}>
          <span class="key-btn__label">${String(resetCat.label ?? 'REDEFINIR CATEGORIA')}</span>
          <span class="keycap">${String(resetCat.key ?? 'R')}</span>
        </button>
      </form>
    </header>

    <div class="tree-wrap">
      <ol class="tier-col" aria-hidden="true">
        ${join(tiers.map((t) => html`<li class="tier-col__n">${String(t)}</li>`))}
      </ol>

      <div class="tree-frame">
        <div class="tree-grid" data-stagger style="--tree-w:${fmt(vbW / 16)}rem">

          <svg class="tree-links" viewBox="0 0 ${fmt(vbW)} ${fmt(vbH)}" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <g class="lnk lnk--rail">${raw(rail.join(''))}</g>
            <g class="lnk lnk--off">${raw(off.join(''))}</g>
            <g class="lnk lnk--on">${raw(on.join(''))}</g>
          </svg>

${join(tree.map((r, j) => join(r.map((n, i) => no(n, j, i)))))}
        </div>
      </div>
    </div>
  </section>

  <!-- ====================== DETALHE ====================== -->
  <aside class="panel hab-detail" data-boot>
    <header class="panel__head">
      <h2 class="panel__title" id="sk-name">${String(sel.name ?? '')}</h2>
      <p class="sk-level">
        ${String(data.level_label ?? 'NÍVEL')}
        <span class="num" id="sk-level">${String(sel.level ?? 0)}<i class="num-sep"> / </i>${String(sel.max ?? 0)}</span>
      </p>
    </header>
    <div class="panel__body">

      <figure class="sk-shot">
        <img class="sk-shot__img" id="sk-img" src="${media(String(sel.image ?? 'ui/skill-preview.jpg'))}" alt="${String(sel.name ?? '')}" decoding="async">
        <span class="sk-shot__shade" aria-hidden="true"></span>
      </figure>

      <p class="sk-desc" id="sk-desc">${destaque(String(sel.desc ?? ''), String(sel.desc_highlight ?? ''))}</p>

      <h3 class="sk-next-title">${String(sel.next_title ?? 'PRÓXIMO NÍVEL')}</h3>
      <p class="sk-next" id="sk-next">${destaque(String(sel.next ?? ''), String(sel.next_highlight ?? ''))}</p>

      <hr class="divider sk-rule">

      <h3 class="sk-cost-title">${String(sel.cost_title ?? 'CUSTO')}</h3>
      <p class="sk-cost">
        ${svg('coin-points', 'sk-cost__ic')}
        <span class="num sk-cost__n" id="sk-cost">${String(sel.cost ?? 0)}</span>
        <span class="sk-cost__label">${String(sel.cost_label ?? 'PONTO(S) DE HABILIDADE')}</span>
      </p>

      <form class="sk-actions" id="sk-form" method="post" action="${url('habilidades/aprender')}">
        ${csrfField()}
        <input type="hidden" name="habilidade" id="sk-slug" value="${String(sel.slug ?? '')}">
        <button class="sk-learn" type="submit" id="sk-learn"${canLearn ? '' : desabilitado}
                title="${canLearn ? 'Aprender habilidade' : why}">
          <span class="keycap keycap--fill">${String(cta.key ?? 'F')}</span>
          <span class="sk-learn__label">${String(cta.label ?? 'APRENDER HABILIDADE')}</span>
        </button>
        <button class="sk-hold" type="submit" id="sk-hold"${canLearn ? '' : desabilitado}
                title="${canLearn ? 'Segure para confirmar' : why}">
          <span class="sk-hold__fill" aria-hidden="true"></span>
          <span class="sk-hold__label">${String(sel.cta_hold ?? 'SEGURAR PARA APRENDER')}</span>
        </button>
        <p class="sk-why" id="sk-why"${canLearn ? raw(' hidden') : ''}>${why}</p>
      </form>

    </div>
  </aside>

</div>
`;
}
