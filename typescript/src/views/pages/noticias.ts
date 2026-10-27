/**
 * Tela NOTÍCIAS — app/Views/pages/noticias.php: /noticias (listagem) e /noticias/{slug} (artigo).
 * $data: title, subtitle, featured, items[], agenda[], links[], article|null.
 */
import { icon, media, url } from '../../core/helpers';
import { html, join, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

export function noticias(s: Scope): SafeHtml {
  const d = s.data;
  const items = (Array.isArray(d.items) ? d.items : []) as Dict[];
  const article: Dict | null = d.article && typeof d.article === 'object' ? d.article : null;
  const agenda = (d.agenda ?? []) as Dict[];
  const links = (d.links ?? []) as Dict[];

  /* destaque: item marcado em `featured`, senão o primeiro */
  const featSlug = String(d.featured ?? '');
  const feat: Dict | null = items.find((it) => String(it.slug ?? '') === featSlug) ?? items[0] ?? null;

  /* grade: tudo que não é o destaque */
  const rest = items.filter((it) => !(feat !== null && String(it.slug ?? '') === String(feat.slug ?? '')));

  /* outras notícias (coluna direita do artigo) */
  const others = article !== null ? items.filter((it) => String(it.slug ?? '') !== String(article.slug ?? '')).slice(0, 4) : [];

  /* ---- coluna direita: agenda + atalhos (compartilhada pelas duas vistas) ---- */
  const agendaBody = html`<ul class="agenda" data-stagger>
${join(
  agenda.map(
    (ev) => html`  <li class="agenda__item">
    <a class="agenda__link" href="${url(String(ev.href ?? 'noticias'))}">
      <span class="agenda__icon agenda__icon--${String(ev.color ?? 'lime')}">${icon(String(ev.icon ?? 'schedule'))}</span>
      <span class="agenda__txt">
        <strong class="agenda__label">${String(ev.label ?? '')}</strong>
        <small class="agenda__sub">${String(ev.sub ?? '')}</small>
      </span>
      <span class="agenda__clock num ${String(ev.color ?? 'lime')}" data-countdown="${String(ev.at ?? '')}">--:--:--</span>
    </a>
  </li>
`,
  ),
)}</ul>
`;

  const linksBody = html`<ul class="shortcuts" data-stagger>
${join(
  links.map(
    (lk) => html`  <li class="shortcuts__item">
    <a class="row shortcuts__row" href="${url(String(lk.href ?? 'inicio'))}">
      ${icon(String(lk.icon ?? 'chevron_right'), 'row__icon')}
      <span class="row__text"><strong>${String(lk.label ?? '')}</strong><small>${String(lk.sub ?? '')}</small></span>
      ${icon('chevron_right', 'row__chev')}
    </a>
  </li>
`,
  ),
)}</ul>
`;

  if (article !== null) {
    /* ====================== ARTIGO ====================== */
    const highlights = (Array.isArray(article.highlights) ? article.highlights : []) as unknown[];
    return html`
<div class="noticias noticias--article">
  <div class="noticias__grid">

    <article class="panel panel--hot art" data-boot>
      <div class="art__hero">
        <img class="art__img" src="${media(String(article.image ?? 'news/news-atualizacao.jpg'))}" alt="" decoding="async" style="object-position:${String(article.pos ?? '50% 45%')}">
        <span class="art__scan" aria-hidden="true"></span>
        <div class="art__hero-txt">
          <div class="art__chips">
            <span class="chip chip--${String(article.tag_cls ?? 'lime')}">${String(article.tag ?? '')}</span>
            <span class="art__date num">${String(article.date ?? '')}</span>
          </div>
          <h1 class="page-title art__title">${String(article.title ?? '')}</h1>
        </div>
      </div>

      <div class="panel__body art__body">
        <p class="art__lead">${String(article.lead ?? article.summary ?? '')}</p>
        <div class="art__meta">
          <span class="art__meta-item">${icon('person', 'art__meta-icon')}${String(article.author ?? 'EQUIPE EXTRACT HUNTERS')}</span>
          <span class="art__meta-sep" aria-hidden="true"></span>
          <span class="art__meta-item">${icon('schedule', 'art__meta-icon')}<span class="num">${String(article.read ?? 3)}</span> MIN DE LEITURA</span>
          <span class="art__meta-sep" aria-hidden="true"></span>
          <span class="art__meta-item art__meta-item--tag">${icon('radar', 'art__meta-icon')}PATCH <span class="num">8.8.1</span></span>
        </div>

        <div class="scroll-y art__text">
${join(((article.body ?? []) as unknown[]).map((p) => html`          <p>${String(p)}</p>
`))}${highlights.length
  ? html`          <div class="box box--hot art__high">
            <h2 class="art__high-title">${icon('bolt')}PRINCIPAIS MUDANÇAS</h2>
            <ul class="art__high-list">
${join(highlights.map((h) => html`              <li>${icon('check_circle', 'art__high-icon')}<span>${String(h)}</span></li>
`))}            </ul>
          </div>
`
  : ''}        </div>
      </div>

      <footer class="panel__foot art__foot">
        <a class="btn btn--outline btn--sm" href="${url('noticias')}">${icon('chevron_left')}TODAS AS NOTÍCIAS</a>
        <div class="art__foot-right">
          <a class="btn btn--outline btn--sm" href="${url('missoes')}">${icon('swords')}MISSÕES</a>
          <a class="btn btn--primary btn--sm" href="${url('desafios')}">${icon('flag')}DESAFIOS DO EVENTO${icon('chevron_right', 'btn__chev')}</a>
        </div>
      </footer>
    </article>

    <aside class="noticias__side col" aria-label="Mais notícias">
      <section class="panel news-more" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">OUTRAS NOTÍCIAS</h2>
          <a class="panel__action" href="${url('noticias')}">VER TODAS${icon('add')}</a>
        </header>
        <div class="panel__body news-more__body">
          <ul class="news-more__list" data-stagger>
${join(
  others.map(
    (o) => html`            <li class="news-more__item">
              <a class="news-more__link" href="${url('noticias/' + String(o.slug ?? ''))}">
                <span class="news-more__thumb"><img src="${media(String(o.image ?? 'news/news-atualizacao.jpg'))}" alt="" loading="lazy" decoding="async"></span>
                <span class="news-more__txt">
                  <strong class="news-more__title">${String(o.title ?? '')}</strong>
                  <span class="news-more__date num">${String(o.tag ?? '')} · ${String(o.date ?? '')}</span>
                </span>
              </a>
            </li>
`,
  ),
)}          </ul>
        </div>
      </section>

      <section class="panel shortcuts-panel" data-boot>
        <header class="panel__head"><h2 class="panel__title">ATALHOS</h2></header>
        <div class="panel__body shortcuts-panel__body">${linksBody}</div>
      </section>
    </aside>

  </div>
</div>

`;
  }

  /* ===================== LISTAGEM ===================== */
  return html`
<div class="noticias">

  <header class="page-head noticias__head" data-boot>
    <div class="noticias__head-txt">
      <h1 class="page-title">${String(d.title ?? 'NOTÍCIAS')}<small><span class="num">${items.length}</span> PUBLICAÇÕES</small></h1>
      <p class="page-subtitle">${String(d.subtitle ?? '')}</p>
    </div>
    <div class="noticias__legend">
      <span class="noticias__legend-label">CATEGORIAS</span>
      <ul class="noticias__legend-list">
${join(items.map((lg) => html`        <li><span class="chip chip--${String(lg.tag_cls ?? 'lime')} chip--dot">${String(lg.tag ?? '')}</span></li>
`))}      </ul>
    </div>
  </header>

  <div class="noticias__grid">

    <div class="noticias__main">

${feat !== null
  ? html`      <a class="feat" href="${url('noticias/' + String(feat.slug ?? ''))}" data-boot>
        <img class="feat__img" src="${media(String(feat.image ?? 'news/news-atualizacao.jpg'))}" alt="" decoding="async" style="object-position:${String(feat.pos ?? '50% 42%')}">
        <span class="feat__scan" aria-hidden="true"></span>
        <span class="feat__flag">EM DESTAQUE</span>
        <div class="feat__body">
          <div class="feat__chips">
            <span class="chip chip--${String(feat.tag_cls ?? 'lime')}">${String(feat.tag ?? '')}</span>
            <span class="feat__date num">${String(feat.date ?? '')}</span>
          </div>
          <h2 class="feat__title">${String(feat.title ?? '')}</h2>
          <p class="feat__lead">${String(feat.lead ?? feat.summary ?? '')}</p>
          <div class="feat__foot">
            <span class="feat__author">${icon('person', 'feat__foot-icon')}${String(feat.author ?? '')}</span>
            <span class="feat__read">${icon('schedule', 'feat__foot-icon')}<span class="num">${String(feat.read ?? 3)}</span> MIN</span>
            <span class="feat__cta">LER MATÉRIA${icon('chevron_right')}</span>
          </div>
        </div>
      </a>
`
  : ''}
      <ul class="ncards" data-stagger>
${join(
  rest.map(
    (n) => html`        <li class="ncards__item">
          <a class="ncard" href="${url('noticias/' + String(n.slug ?? ''))}">
            <span class="ncard__media">
              <img src="${media(String(n.image ?? 'news/news-atualizacao.jpg'))}" alt="" loading="lazy" decoding="async" style="object-position:${String(n.pos ?? '50% 45%')}">
              <span class="chip chip--${String(n.tag_cls ?? 'lime')} ncard__chip">${String(n.tag ?? '')}</span>
            </span>
            <span class="ncard__body">
              <strong class="ncard__title">${String(n.title ?? '')}</strong>
              <span class="ncard__sum">${String(n.summary ?? '')}</span>
              <span class="ncard__foot">
                <span class="ncard__date num">${String(n.date ?? '')}</span>
                <span class="ncard__cta">LER${icon('chevron_right')}</span>
              </span>
            </span>
          </a>
        </li>
`,
  ),
)}      </ul>

    </div>

    <aside class="noticias__side col" aria-label="Agenda e atalhos">
      <section class="panel agenda-panel" data-boot>
        <header class="panel__head">
          <h2 class="panel__title">AGENDA</h2>
          <span class="panel__meta">AO VIVO<span class="dot agenda-panel__dot"></span></span>
        </header>
        <div class="panel__body agenda-panel__body">${agendaBody}</div>
      </section>

      <section class="panel shortcuts-panel panel--grow" data-boot>
        <header class="panel__head"><h2 class="panel__title">ATALHOS</h2></header>
        <div class="panel__body shortcuts-panel__body">${linksBody}</div>
      </section>
    </aside>

  </div>
</div>
`;
}
