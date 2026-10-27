/**
 * Tela CONQUISTAS — app/Views/pages/conquistas.php (referência 09-conquistas.png).
 * $data: title, progress_title, done, total, sort_label, sorts[], categories_title, categories[],
 *        filter{}, status_labels{}, items[], active_category, selected, pagination{}, detail{}, sort
 */
import { icon, media, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { hex, progress } from '../components/index';

export function conquistas(s: Scope): SafeHtml {
  const data = s.data;
  const cats = (data.categories ?? []) as Dict[];
  const items = (data.items ?? []) as Dict[];
  const labels: Dict = data.status_labels ?? {};
  const detail: Dict = data.detail ?? {};
  const sorts = (data.sorts ?? []) as Dict[];
  const filter: Dict = data.filter ?? {};
  const pag: Dict = data.pagination ?? {};
  const catSlug = String(data.active_category ?? 'todas');
  const sortSlug = String(data.sort ?? 'recentes');
  const selected: Dict = data.selected ?? items[0] ?? {};
  const selSlug = String(selected.slug ?? '');

  const done = Number(data.done ?? 0) | 0;
  const total = Number(data.total ?? 1) | 0;
  const pct = total > 0 ? Math.floor((100 * done) / total) : 0;

  const base = 'conquistas' + (catSlug !== 'todas' ? '/' + catSlug : '');
  let sortNow = 'MAIS RECENTES';
  for (const so of sorts) if ((so.slug ?? '') === sortSlug) sortNow = String(so.label);

  /** Rótulo de estado do card / do detalhe. */
  const stateLabel = (a: Dict): string => String(labels[String(a.status ?? 'locked')] ?? '');
  const cta: Dict = detail.cta ?? {};

  const cur = Number(pag.current ?? 1) | 0;
  const pages = Number(pag.pages ?? 1) | 0;
  const vis = (pag.visible ?? [1]) as unknown[];

  const card = (a: Dict): SafeHtml => {
    const st = String(a.status ?? 'locked');
    const on = String(a.slug) === selSlug;
    const col = String(a.color ?? 'muted');
    let foot: SafeHtml;
    if (st === 'done') {
      foot = html`          <b class="ach__state">${stateLabel(a)}</b>
          <i class="ach__date num">${String(a.date ?? '')}</i>
`;
    } else if (st === 'progress') {
      foot = html`          <span class="ach__meter">
            ${progress({ value: Number(a.cur) | 0, max: Number(a.max) | 0, anim: true, class: 'ach__meter-bar' })}
            <i class="ach__meter-val num">${String(a.cur)}/${String(a.max)}</i>
          </span>
`;
    } else {
      foot = html`          <b class="ach__state ach__state--locked">${stateLabel(a)}</b>
`;
    }
    return html`      <button class="ach ach--${st}${on ? ' is-selected' : ''}" type="button"
              data-ach="${String(a.slug)}" aria-pressed="${on ? 'true' : 'false'}">
        ${hex({ size: 'md', color: col, icon: String(a.icon), class: 'ach__hex', flat: st !== 'done' })}
        <span class="ach__title">${String(a.title)}</span>
        <span class="ach__desc">${String(a.desc)}</span>
        <span class="ach__foot">
${foot}        </span>
      </button>
`;
  };

  const det = (a: Dict): SafeHtml => {
    const st = String(a.status ?? 'locked');
    const on = String(a.slug) === selSlug;
    const col = String(a.color ?? 'muted');
    const rw = (a.rewards ?? []) as Dict[];
    const it: Dict | null = a.reward_item && typeof a.reward_item === 'object' ? a.reward_item : null;
    const stateKey = st === 'done' ? 'done_label' : st === 'progress' ? 'progress_label' : 'locked_label';
    let fim: SafeHtml;
    if (st === 'done') {
      fim = html`        <h3 class="det__section">${String(detail.unlocked_title ?? 'DESBLOQUEADA EM')}</h3>
        <p class="det__date num">${String(a.unlocked_at ?? '')}</p>
`;
    } else if (st === 'progress') {
      fim = html`        <h3 class="det__section">${String(detail.progress_title ?? 'PROGRESSO ATUAL')}</h3>
        <div class="det__meter">
          ${progress({ value: Number(a.cur) | 0, max: Number(a.max) | 0, anim: true, size: 'thick' })}
          <span class="det__meter-val num">${String(a.cur)}<small>/${String(a.max)}</small></span>
        </div>
`;
    } else {
      fim = html`        <h3 class="det__section">${String(detail.locked_title ?? 'REQUISITO')}</h3>
        <p class="det__date det__date--locked">${String(detail.locked_text ?? '')}</p>
`;
    }
    return html`      <article class="det det--${st}${on ? ' is-on' : ''}" data-detail="${String(a.slug)}"${on ? '' : raw(' hidden')}>

        <header class="det__head">
          ${hex({ size: 'xl', color: col, icon: String(a.icon), class: 'det__hex', flat: true })}
          <span class="det__id">
            <h2 class="det__title">${String(a.title)}</h2>
            <span class="det__state">${String(detail[stateKey] ?? '')}</span>
          </span>
        </header>

        <p class="det__desc">${String(a.desc)}</p>

        <hr class="divider det__rule">

        <h3 class="det__section">${String(detail.rewards_title ?? 'RECOMPENSAS')}</h3>
        <div class="det__rewards">
${join(rw.map((r) => {
  const t = String(r.type ?? 'xp');
  return html`          <span class="rw rw--${t}">
            <span class="rw__badge">${t === 'xp' ? raw('<span class="rw__xp">XP</span>') : svg(t === 'cash' ? 'coin-cash' : 'coin-points', 'rw__ic')}</span>
            <span class="rw__val num">${String(r.text ?? '')}</span>
          </span>
`;
}))}        </div>

${it !== null && Object.keys(it).length ? html`        <div class="det__item">
          <span class="det__item-media"><img class="det__item-img" src="${media(String(it.image))}" alt="" loading="lazy" decoding="async"></span>
          <span class="det__item-text">
            <strong class="det__item-name">${String(it.name)}</strong>
            <small class="det__item-desc">${String(it.desc)}</small>
          </span>
        </div>
` : ''}
${fim}
        <hr class="divider det__rule det__rule--tail">

        <button class="det__cta" type="button" data-track aria-pressed="false"
                data-label-off="${String(cta.label ?? 'ACOMPANHAR')}"
                data-label-on="${String(cta.label_on ?? 'ACOMPANHANDO')}">
          ${icon(String(cta.icon ?? 'star'), 'fill det__cta-ic')}
          <span class="det__cta-label">${String(cta.label ?? 'ACOMPANHAR')}</span>
        </button>

      </article>
`;
  };

  return html`<header class="page-head">
  <h1 class="page-title">${String(data.title ?? 'CONQUISTAS')}</h1>
</header>

<div class="cq">

  <!-- ====================== CATEGORIAS ====================== -->
  <section class="panel cq-cats" data-boot>
    <h2 class="cq-cats__title">${String(data.categories_title ?? 'CATEGORIAS')}</h2>
    <nav class="cq-cats__list" aria-label="${String(data.categories_title ?? 'Categorias')}">
${join(cats.map((c) => {
  const on = String(c.slug) === catSlug;
  return html`      <a class="cat-row${on ? ' is-active' : ''}" href="${url('conquistas/' + String(c.slug))}"${on ? raw(' aria-current="page"') : ''}>
        ${icon(String(c.icon), 'cat-row__ic')}
        <span class="cat-row__label">${String(c.label)}</span>
        <span class="cat-row__meta num">${String(c.done)}<i>/</i>${String(c.total)}</span>
      </a>
`;
}))}    </nav>
    <button class="cq-filter" type="button" data-filter aria-pressed="false"
            data-label-off="${String(filter.label ?? 'FILTRAR')}"
            data-label-on="${String(filter.label_on ?? 'MOSTRAR TODAS')}">
      <span class="cq-filter__key num">${String(filter.key ?? 'R')}</span>
      <span class="cq-filter__label">${String(filter.label ?? 'FILTRAR')}</span>
      ${icon(String(filter.icon ?? 'filter_alt'), 'cq-filter__ic')}
    </button>
  </section>

  <!-- ====================== CENTRO ====================== -->
  <div class="cq__main">

    <header class="cq-head" data-boot>
      <div class="cq-prog">
        <div class="cq-prog__top">
          <span class="cq-prog__title">${String(data.progress_title ?? 'PROGRESSO GERAL')}</span>
          <span class="cq-prog__val num">${done}/${total} (${pct}%)</span>
        </div>
        ${progress({ pct, anim: true, class: 'cq-prog__bar' })}
      </div>

      <div class="cq-sort" data-sort>
        <button class="cq-sort__btn" type="button" aria-haspopup="listbox" aria-expanded="false">
          <span class="cq-sort__text">${String(data.sort_label ?? 'ORDENAR:')} <b>${sortNow}</b></span>
          ${icon('expand_more', 'cq-sort__chev')}
        </button>
        <div class="cq-sort__menu" role="listbox" hidden>
${join(sorts.map((so) => {
  const on = String(so.slug) === sortSlug;
  return html`          <a class="cq-sort__opt${on ? ' is-active' : ''}" role="option"
             aria-selected="${on ? 'true' : 'false'}"
             href="${url(base, { ordem: String(so.slug) })}">${String(so.label)}</a>
`;
}))}        </div>
      </div>
    </header>

    <div class="cq-grid" data-stagger>
${join(items.map(card))}    </div>
  </div>

  <!-- ====================== PAGINAÇÃO ====================== -->
  <nav class="cq-pager" aria-label="Paginação das conquistas">
${cur > 1
    ? html`    <a class="pg pg--arrow" href="${url(base, { ordem: sortSlug, pagina: cur - 1 })}" aria-label="Página anterior">${icon('chevron_left')}</a>
`
    : html`    <span class="pg pg--arrow is-off" aria-hidden="true">${icon('chevron_left')}</span>
`}
${join(vis.map((n) => {
  const isCur = (Number(n) | 0) === cur;
  return html`    <a class="pg num${isCur ? ' is-active' : ''}" href="${url(base, { ordem: sortSlug, pagina: Number(n) | 0 })}"${isCur ? raw(' aria-current="page"') : ''}>${String(n)}</a>
`;
}))}
${pages > vis.length + 1 ? raw('    <span class="pg pg--gap" aria-hidden="true">…</span>\n') : ''}${pages > vis.length
    ? html`    <a class="pg num" href="${url(base, { ordem: sortSlug, pagina: pages })}">${pages}</a>
`
    : ''}
    <a class="pg pg--arrow" href="${url(base, { ordem: sortSlug, pagina: Math.min(pages, cur + 1) })}" aria-label="Próxima página">${icon('chevron_right')}</a>
  </nav>

  <!-- ====================== DETALHE ====================== -->
  <aside class="panel cq-detail" data-boot>
    <div class="cq-detail__stack">
${join(items.map(det))}    </div>
  </aside>

</div>
`;
}
