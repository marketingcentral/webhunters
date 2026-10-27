/**
 * Tela INÍCIO — app/Views/pages/inicio.php (referência "01-Menu-do-jogo-inicio.png").
 */
import * as GR from '../../core/game-rules';
import { asset, csrfField, icon, media, num, url } from '../../core/helpers';
import { e, html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge, carousel, hex, logo } from '../components';

const r2 = (n: number) => Math.round(n * 100) / 100;

export function inicio(s: Scope): SafeHtml {
  const d = s.data;
  const menu = (d.menu ?? []) as Dict[];
  const season: Dict = d.season ?? {};
  const contract: Dict = d.contract ?? {};
  const newsHead: Dict = d.news_panel ?? {};
  const newsPages = (d.news_pages ?? []) as Dict[][];

  const sXp = Number(season.xp ?? 0);
  const sXpNext = Math.max(1, Number(season.xp_next ?? 1));
  const sPct = r2(Math.min(100, Math.max(0, (sXp / sXpNext) * 100)));
  let sRewards = (season.rewards ?? []) as Dict[];
  if (!sRewards.length) {
    sRewards = [{ level: Number(season.level ?? 1), name: String(season.title ?? ''), tier: '', image: String(season.image ?? 'ui/season-pass.jpg'), pos: '52% 26%' }];
  }
  const seasonSlides = sRewards.map(
    (r) => html`
<figure class="season__slide">
  <img class="season__img" src="${media(String(r.image ?? 'ui/season-pass.jpg'))}" alt="${String(r.name ?? '')}" decoding="async" style="object-position:${String(r.pos ?? '52% 26%')}">
  <figcaption class="season__cap">
    <span class="season__cap-lvl num">NÍVEL ${String(r.level ?? '')}</span>
    <span class="season__cap-name">${String(r.name ?? '')}</span>
    <span class="season__cap-tier">${String(r.tier ?? '')}</span>
  </figcaption>
</figure>
`,
  );

  const cDone = Number(contract.progress ?? 0);
  const cTotal = Math.max(1, Number(contract.total ?? 1));
  const cPct = r2(Math.min(100, Math.max(0, (cDone / cTotal) * 100)));

  const newsSlides = newsPages.map(
    (itens) => html`
<ul class="news__list">
${join(
  (itens ?? []).map(
    (n) => html`  <li class="news__row">
    <a class="news__item" href="${url(String(n.route ?? 'noticias'))}">
      <span class="news__thumb"><img src="${media(String(n.image ?? 'news/news-atualizacao.jpg'))}" alt="" loading="lazy" decoding="async"></span>
      <span class="news__txt">
        <strong class="news__title">${String(n.title ?? '')}</strong>
        <span class="news__sum">${String(n.summary ?? '')}</span>
        <span class="news__date num">${String(n.date ?? '')}</span>
      </span>
    </a>
  </li>
`,
  ),
)}</ul>
`,
  );

  const menuHtml = join(
    menu.map((item) => {
      const slug = String(item.slug ?? '');
      // ENTRAR EM OPERAÇÃO usa a tag do Hunter (assets/img/icons/Tag_hunter).
      const ic =
        (item.emblem ?? '') === 'mountain'
          ? raw(
              '<span class="menu-btn__icon menu-btn__icon--emblem" aria-hidden="true">' +
                `<picture><source srcset="${e(asset('img/icons/Tag_hunter.webp'))}" type="image/webp">` +
                `<img src="${e(asset('img/icons/Tag_hunter.png'))}" alt="" decoding="async"></picture></span>`,
            )
          : icon(String(item.icon ?? 'chevron_right'), 'menu-btn__icon' + (item.icon_fill ? ' fill' : ''));
      const inner = html`${ic}<span class="menu-btn__text"><strong>${String(item.title ?? '')}</strong><small>${String(item.desc ?? '')}</small></span>${icon('chevron_right', 'menu-btn__chev')}`;
      const cls = 'menu-btn menu-btn--' + e(slug) + (item.active ? ' is-active' : '');
      if ((item.href ?? null) === null) {
        return html`      <form class="inicio__logout" method="post" action="${url('sair')}">
        ${csrfField()}
        <button class="${raw(cls)}" type="submit" data-boot>${inner}</button>
      </form>
`;
      }
      return html`      <a class="${raw(cls)}" href="${url(String(item.href))}" data-boot>${inner}</a>
`;
    }),
  );

  const st: Dict = d.status_op ?? {};
  const stOp: Dict = st.op ?? {};
  const cOrigin = GR.origin(contract.origin ?? null);

  const recompensas = join(
    ((contract.rewards ?? []) as Dict[]).map((rw) => {
      const tipo = String(rw.type ?? '');
      const val = Number(rw.value ?? 0);
      const ic =
        tipo === 'xp'
          ? raw('<span class="rw rw--xp" aria-hidden="true">XP</span>')
          : tipo === 'cash'
            ? raw('<span class="rw rw--cash" aria-hidden="true">$</span>')
            : html`<span class="rw rw--rep" aria-hidden="true">${icon('handshake')}</span>`;
      return html`            <li class="contract__reward-item" title="${String(rw.label ?? '')}">
              ${ic}
              <span class="contract__reward-val num" data-count="${val}" data-dur="1000">${num(val)}</span>
              <span class="sr-only">${String(rw.label ?? '')}</span>
            </li>
`;
    }),
  );

  return html`<div class="inicio">

  <!-- ============================ coluna esquerda ============================ -->
  <section class="inicio__left" aria-label="Menu principal">
    <div class="inicio__logo" data-boot>
      ${logo({ variant: 'hero' })}
    </div>

    <nav class="inicio__menu" aria-label="Ações">
${menuHtml}    </nav>
  </section>

  <!-- ============================ centro (cena) ============================== -->
  <div class="inicio__stage" aria-hidden="true"></div>

  <!-- ============================ coluna direita ============================= -->
  <aside class="inicio__right" aria-label="Resumo do caçador">

    <!-- passe de temporada -->
    <section class="panel panel--hot season">
      <div class="season__art">
        ${carousel({ slides: seasonSlides, interval: 9000, over: true, fixed: true, label: 'Recompensas do passe de temporada' })}
      </div>
      <div class="season__body">
        ${hex({ size: 'lg', color: 'lime', text: String(season.level ?? ''), class: 'season__hex' })}
        <div class="season__info">
          <h2 class="season__title">${String(season.title ?? 'PASSE DE TEMPORADA')}</h2>
          <p class="season__sub">${String(season.subtitle ?? '')}</p>
          <div class="progress progress--anim season__bar" style="--v:${String(sPct)}%" role="progressbar" aria-label="Progresso do passe" aria-valuemin="0" aria-valuemax="${sXpNext}" aria-valuenow="${sXp}"><div class="progress__bar"></div></div>
          <p class="season__xp num"><span class="lime" data-count="${sXp}" data-dur="1200">${num(sXp)}</span> / ${num(sXpNext)} XP</p>
        </div>
        <a class="season__link" href="${url(String(season.href ?? 'loja'))}"><span class="sr-only">${String(season.title ?? 'Passe de temporada')}</span></a>
      </div>
      <span class="season__clock" data-countdown="${String(season.ends_at ?? '')}" aria-hidden="true"></span>
    </section>

    <!-- status operacional (PRD v3 §16.3) -->
    <a class="panel opstat opstat--${String(stOp.state ?? 'hunter').toLowerCase()}" href="${url(String(st.href ?? 'perfil'))}" data-boot>
      <span class="opstat__head">
        <span class="opstat__title">${String(st.title ?? 'STATUS OPERACIONAL')}</span>
        ${badge({ kind: 'state', value: stOp, level: Number(s.player.level ?? 0), class: 'opstat__badge' })}
      </span>
      <ul class="opstat__list">
${join(
  ((st.items ?? []) as Dict[]).map(
    (it) => html`        <li class="opstat__i${it.cls ? ' opstat__i--' + e(String(it.cls)) : ''}">
          <span class="opstat__k">${String(it.label)}</span>
          <span class="opstat__v${it.num ? ' num' : ''}">${String(it.value)}</span>
        </li>
`,
  ),
)}      </ul>
    </a>

    <!-- contrato ativo -->
    <section class="panel contract">
      <header class="panel__head">
        <h2 class="panel__title">${String(contract.title ?? 'CONTRATO ATIVO')}</h2>
        <a class="panel__action" href="${url(String(contract.href ?? 'missoes'))}">${String(contract.action ?? 'VER TODOS')}${icon('add')}</a>
        <span class="contract__origin">
          <span class="contract__origin-k">${String(contract.origin_label ?? 'ORIGEM')}</span>
          ${badge({ kind: 'origin', value: String(contract.origin ?? 'hunter_command'), tip: cOrigin.badge, class: 'contract__origin-badge' })}
        </span>
      </header>
      <div class="panel__body contract__body">
        <a class="contract__mission" href="${url(String(contract.href ?? 'missoes'))}">
          <span class="contract__icon">
            <svg class="contract__ring" viewBox="0 0 48 48" aria-hidden="true">
              <circle cx="24" cy="24" r="17.2" fill="none" stroke="currentColor" stroke-width="1.7"/>
              <path fill="none" stroke="currentColor" stroke-width="2.2" d="M24 1.6V6.6M24 41.4v5M1.6 24h5M41.4 24h5M37.6 10.4l3.4-3.4M7 41l3.4-3.4M10.4 10.4 7 7M37.6 37.6l3.4 3.4"/>
            </svg>
            ${icon('park', 'contract__glyph')}
          </span>
          <span class="contract__txt">
            <strong class="contract__name">${String(contract.name ?? '')}</strong>
            <small class="contract__desc">${String(contract.desc ?? '')}</small>
            <span class="progress progress--anim contract__bar" style="--v:${String(cPct)}%" role="progressbar" aria-label="Progresso do contrato" aria-valuemin="0" aria-valuemax="${cTotal}" aria-valuenow="${cDone}"><span class="progress__bar"></span></span>
          </span>
          <span class="contract__count num">${cDone} / ${cTotal}</span>
        </a>
        <div class="contract__reward">
          <p class="contract__label">${String(contract.reward_label ?? 'RECOMPENSA')}</p>
          <ul class="contract__rewards">
${recompensas}          </ul>
        </div>
      </div>
    </section>

    <!-- notícias -->
    <section class="panel news">
      <header class="panel__head">
        <h2 class="panel__title">${String(newsHead.title ?? 'NOTÍCIAS')}</h2>
        <a class="panel__action" href="${url(String(newsHead.href ?? 'noticias'))}">${String(newsHead.action ?? 'VER TODAS')}${icon('add')}</a>
      </header>
      <div class="panel__body news__body">
        ${carousel({ slides: newsSlides, interval: 7500, fixed: true, dots: false, class: 'news__car', label: 'Notícias' })}
      </div>
    </section>

  </aside>
</div>
`;
}
