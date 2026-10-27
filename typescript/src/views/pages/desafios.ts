/**
 * Tela DESAFIOS — app/Views/pages/desafios.php.
 * $data vem inteiro do banco (ChallengesController + ChallengeRepo). Prazos chegam em
 * SEGUNDOS (`*_secs`); quem formata ao vivo é desafios.js — sem JS a view já imprime o
 * texto inicial. Abas trocam no cliente (hud.js) e cada uma tem rota própria (/desafios/{aba}).
 */
import { csrfField, icon, media, num, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { hex } from '../components';
import { btn } from '../components/btn';
import { tabs as tabsComp } from '../components/tabs';

const int = (v: unknown): number => Math.trunc(Number(v) || 0);
const obj = (v: unknown): Dict => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});

/** "12h 45m 09s" / "3d 12h 44m" — mesmo formato que desafios.js usa ao atualizar. */
function span(total: number): string {
  const t = Math.max(0, total);
  const p = (n: number): string => String(n).padStart(2, '0');
  if (t >= 86400) return `${Math.floor(t / 86400)}d ${p(Math.floor((t % 86400) / 3600))}h ${p(Math.floor((t % 3600) / 60))}m`;
  if (t >= 3600) return `${Math.floor(t / 3600)}h ${p(Math.floor((t % 3600) / 60))}m ${p(t % 60)}s`;
  return `${Math.floor(t / 60)}m ${p(t % 60)}s`;
}

/** Arco do anel semanal: um segmento por desafio da meta. */
function arc(i: number, total: number): string {
  const step = 360 / total;
  const gap = 5.2;
  const a1 = -90 + i * step + gap;
  const a2 = -90 + (i + 1) * step - gap;
  const pt = (deg: number): [number, number] => {
    const r = (deg * Math.PI) / 180;
    return [60 + 50 * Math.cos(r), 60 + 50 * Math.sin(r)];
  };
  const [x1, y1] = pt(a1);
  const [x2, y2] = pt(a2);
  const n = (v: number): string => (Math.round(v * 100) / 100).toFixed(2);
  return `M ${n(x1)} ${n(y1)} A 50 50 0 0 1 ${n(x2)} ${n(y2)}`;
}

export function desafios(s: Scope): SafeHtml {
  const d = s.data;
  const tabs = (Array.isArray(d.tabs) ? d.tabs : []) as Dict[];
  const groups = obj(d.groups) as Record<string, Dict>;
  const featured = obj(d.featured);
  const bonuses = obj(d.bonus) as Record<string, Dict>;
  const ring = obj(d.ring);
  const pass = obj(d.pass);
  const active = String(d.active_tab ?? 'diarios');

  const ringTotal = Math.max(1, int(ring.total ?? 1));
  const ringDone = Math.min(Math.max(0, int(ring.done ?? 0)), ringTotal);
  const ringPct = Math.min(100, Math.round((ringDone / ringTotal) * 100));

  const passLvl = int(pass.level ?? 0);
  const passXp = int(pass.xp ?? 0);
  const passNext = Math.max(1, int(pass.xp_next ?? 1));
  const passPct = Math.min(100, Math.round((passXp / passNext) * 100));

  const fCur = int(featured.cur ?? 0);
  const fMax = Math.max(1, int(featured.max ?? 1));
  const fPct = Math.min(100, Math.round((fCur / fMax) * 100));

  const headSecs = int(d.reset_secs ?? 0);
  const evSecs = int(featured.ends_secs ?? 0);
  const ringSecs = int(ring.reset_secs ?? 0);
  const passSecs = int(pass.ends_secs ?? 0);

  const tabItems = tabs.map((t) => {
    const key = String(t.key ?? '');
    return {
      label: String(t.label ?? ''),
      panel: key, // sem href: aba que só troca painel é <button> (SPEC)
      count: String(t.count ?? ''),
      active: key === active,
      attrs: {
        id: 'des-tab-' + key,
        'aria-controls': 'des-panel-' + key,
        'data-reset': String(t.seconds ?? '0'),
        'data-reset-label': String(t.reset_label ?? ''),
        'data-tab-url': url('desafios/' + key),
      },
    };
  });

  const painel = (key: string, g: Dict): SafeHtml => {
    const rows = (g.items ?? []) as Dict[];
    const b = obj(bonuses[key]);
    let bonus: SafeHtml | string = '';
    if (Object.keys(b).length > 0) {
      const bCur = int(b.cur ?? 0);
      const bMax = Math.max(1, int(b.max ?? 1));
      const bPct = Math.min(100, Math.round((bCur / bMax) * 100));
      const bReady = Boolean(b.ready);
      const bClaimed = Boolean(b.claimed);
      const bTip = bClaimed
        ? 'Bônus já resgatado neste período.'
        : bReady ? 'Recompensa liberada — resgate agora.' : 'Conclua os ' + num(bMax) + ' desafios desta aba para liberar.';
      bonus = html`          <form class="des-bonus${bReady ? ' is-ready' : ''}${bClaimed ? ' is-claimed' : ''}" method="post" action="${url('desafios/resgatar')}" data-bonus="${key}">
            ${csrfField()}
            <input type="hidden" name="escopo" value="${key}">
            <span class="des-bonus__thumb"><img src="${media(String(b.image ?? 'items/supply-crate.png'))}" alt="" loading="lazy"></span>
            <span class="des-bonus__text">
              <span class="des-bonus__label">${String(b.label ?? '')}</span>
              <strong class="des-bonus__title">${String(b.title ?? '')}</strong>
              <small class="des-bonus__desc">${String(b.desc ?? '')}</small>
            </span>
            <span class="des-bonus__prog">
              <span class="des-bonus__prog-head">
                <span>PROGRESSO</span>
                <span class="num">${num(bCur)} / ${num(bMax)}</span>
              </span>
              <span class="progress progress--anim des-bonus__bar" style="--v:${bPct}%" role="progressbar" aria-label="Progresso do bônus" aria-valuemin="0" aria-valuemax="${bMax}" aria-valuenow="${bCur}"><span class="progress__bar"></span></span>
            </span>
            <span class="chal__rw des-bonus__rw">
              <span class="rw rw--xp rw--gold"><span class="rw__badge">XP</span><span class="rw__val num">${num(int(b.xp ?? 0))}</span></span>
              <span class="rw rw--cr">${svg('coin-credit', 'rw__ic')}<span class="rw__val num">${num(int(b.credits ?? 0))}</span></span>
            </span>
            ${btn({
              label: bClaimed ? 'RESGATADO' : String(d.bonus_cta ?? 'RESGATAR'),
              type: 'submit',
              variant: 'gold',
              size: 'sm',
              disabled: !bReady,
              class: 'des-bonus__btn',
              tip: bTip,
              attrs: bReady ? {} : { 'aria-disabled': 'true' },
            })}          </form>
`;
    }

    return html`        <div class="des-pane${key === active ? ' is-shown' : ''}" data-tab-panel="${key}" id="des-panel-${key}" role="tabpanel" aria-labelledby="des-tab-${key}" tabindex="0"${key === active ? '' : raw(' hidden')}>
          <ul class="chal-list${rows.length >= 6 ? ' chal-list--dense' : ''}" data-stagger data-rows="${rows.length}">
${join(
  rows.map((it) => {
    const cur = int(it.cur ?? 0);
    const max = Math.max(1, int(it.max ?? 1));
    const done = Boolean(it.done);
    const hot = Boolean(it.hot);
    const pct = it.pct !== undefined && it.pct !== null ? int(it.pct) : Math.min(100, Math.round((cur / max) * 100));
    return html`            <li class="chal-item">
              <a class="chal${done ? ' is-done' : ''}${hot ? ' is-hot' : ''}" href="${url(String(g.href ?? 'jogar'))}" data-slug="${String(it.slug ?? '')}">
                <span class="chal__icon" aria-hidden="true">${icon(String(it.icon ?? 'flag'))}</span>
                <span class="chal__main">
                  <strong class="chal__label">${String(it.label ?? '')}</strong>
                  <span class="chal__desc">${String(it.desc ?? '')}</span>
                  <span class="chal__prog">
                    <span class="progress progress--anim chal__bar" style="--v:${pct}%" role="progressbar" aria-label="${String(it.label ?? 'Progresso')}" aria-valuemin="0" aria-valuemax="${max}" aria-valuenow="${cur}"><span class="progress__bar"></span></span>
                    <span class="chal__count num">${num(cur)}<small> / ${num(max)}</small></span>
                  </span>
                </span>
                <span class="chal__rw">
                  <span class="rw rw--xp"><span class="rw__badge">XP</span><span class="rw__val num">${num(int(it.xp ?? 0))}</span></span>
                  <span class="rw rw--cr">${svg('coin-credit', 'rw__ic')}<span class="rw__val num">${num(int(it.credits ?? 0))}</span></span>
                </span>
                <span class="chal__state" aria-hidden="true">${done ? icon('check_circle', 'chal__check') : icon('chevron_right', 'chal__chev')}</span>
              </a>
            </li>
`;
  }),
)}          </ul>

${bonus}        </div>
`;
  };

  const segs: SafeHtml[] = [];
  for (let i = 0; i < ringTotal; i++) {
    segs.push(html`            <path class="ring__seg${i < ringDone ? ' is-on' : ''}" style="--i:${i}" d="${arc(i, ringTotal)}"></path>
`);
  }

  return html`<div class="layout-2 layout-2--r desafios">

  <!-- ======================= COLUNA PRINCIPAL ============================ -->
  <div class="col des-main">

    <header class="des-head" data-boot>
      <div class="des-head__text">
        <h1 class="page-title">${String(d.title ?? 'DESAFIOS')}<span class="page-title__tag">${String(d.tag ?? '')}</span></h1>
        <p class="page-subtitle">${String(d.intro ?? '')}</p>
      </div>
      <div class="des-clock">
        ${icon('schedule', 'des-clock__ic')}
        <span class="des-clock__text">
          <span class="des-clock__label" data-clock-label>${String(d.reset_label ?? 'REINICIA EM')}</span>
          <span class="des-clock__val num" data-clock data-countdown="${headSecs}">${span(headSecs)}</span>
        </span>
      </div>
    </header>

    <!-- destaque do evento -->
    <section class="des-banner" data-boot>
      <img class="des-banner__img" src="${media(String(featured.image ?? 'missions/mission-cacada-ao-cacador.jpg'))}" alt="">
      <span class="des-banner__shade" aria-hidden="true"></span>
      <div class="des-banner__body">
        <span class="des-banner__tag">${String(featured.tag ?? '')}</span>
        <h2 class="des-banner__title">${String(featured.title ?? '')}</h2>
        <p class="des-banner__desc">${String(featured.desc ?? '')}</p>
      </div>
      <div class="des-banner__side">
        <div class="des-banner__clock">
          <span class="des-banner__clock-label">${String(featured.ends_label ?? '')}</span>
          <span class="des-banner__clock-val num" data-countdown="${evSecs}">${span(evSecs)}</span>
        </div>
        <div class="des-banner__prog">
          <div class="des-banner__prog-head">
            <span>${String(featured.meta_label ?? '')}</span>
            <span class="num">${num(fCur)} / ${num(fMax)}</span>
          </div>
          <span class="progress progress--anim des-banner__bar" style="--v:${fPct}%" role="progressbar" aria-label="${String(featured.meta_label ?? 'Progresso do evento')}" aria-valuemin="0" aria-valuemax="${fMax}" aria-valuenow="${fCur}"><span class="progress__bar"></span></span>
        </div>
        ${btn({ label: String(featured.cta ?? 'VER MISSÕES'), href: url(String(featured.href ?? 'missoes')), variant: 'outline', size: 'sm', chev: true, class: 'des-banner__cta' })}      </div>
    </section>

    <!-- quadro de desafios -->
    <section class="panel des-board panel--grow" data-boot data-tabs-scope>
      <header class="panel__head des-board__head">
        ${tabsComp({ items: tabItems, class: 'des-tabs', label: 'Tipos de desafio' })}        <a class="panel__action des-board__all" href="${url('conquistas')}">${String(d.board_action ?? 'VER CONQUISTAS')}${icon('chevron_right')}</a>
      </header>

      <div class="panel__body des-board__body">
${join(Object.entries(groups).map(([key, g]) => painel(key, obj(g))))}      </div>
    </section>
  </div>

  <!-- ========================= COLUNA DIREITA ============================ -->
  <aside class="col des-side" aria-label="Resumo dos desafios">

    <!-- anel de progresso semanal -->
    <section class="panel des-ring" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(ring.title ?? 'PROGRESSO SEMANAL')}</h2>
        <span class="panel__meta">${String(ring.reset_label ?? '')} <span class="num" data-countdown="${ringSecs}">${span(ringSecs)}</span></span>
      </header>
      <div class="panel__body des-ring__body">
        <div class="ring" role="img" aria-label="${ringDone + ' de ' + ringTotal + ' desafios concluídos'}">
          <svg class="ring__svg" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
${join(segs)}          </svg>
          <div class="ring__mid">
            <span class="ring__val num" data-count="${ringDone}" data-dur="900">${num(ringDone)}</span>
            <span class="ring__total num">/ ${num(ringTotal)}</span>
            <span class="ring__cap">${String(ring.caption ?? '')}</span>
          </div>
        </div>
        <div class="ring__pct num"><span data-count="${ringPct}" data-suffix="%" data-dur="900">${num(ringPct)}%</span></div>
        <ul class="ring__stats">
${join(
  ((ring.stats ?? []) as Dict[]).map(
    (st) => html`          <li class="ring__stat ring__stat--${String(st.color ?? 'lime')}">
            <span class="ring__stat-ic">${icon(String(st.icon ?? 'bolt'))}</span>
            <span class="ring__stat-label">${String(st.label ?? '')}</span>
            <span class="ring__stat-val num">${String(st.value ?? '')}</span>
          </li>
`,
  ),
)}        </ul>
      </div>
    </section>

    <!-- prévia do passe de temporada -->
    <section class="panel panel--hot des-pass panel--grow" data-boot>
      <div class="des-pass__art">
        <img class="des-pass__img" src="${media(String(pass.image ?? 'ui/season-pass.jpg'))}" alt="${String(pass.name ?? '')}">
        <span class="des-pass__shade" aria-hidden="true"></span>
        <span class="des-pass__season">${String(pass.season ?? '')}</span>
        <span class="des-pass__ends">${String(pass.ends_label ?? '')} <span class="num" data-countdown="${passSecs}">${span(passSecs)}</span></span>
        <span class="des-pass__name">${String(pass.name ?? '')}</span>
      </div>
      <div class="panel__body des-pass__body">
        <div class="des-pass__top">
          ${hex({ size: 'lg', color: 'lime', text: String(passLvl), class: 'des-pass__hex' })}
          <div class="des-pass__lines">
            <h2 class="des-pass__title">${String(pass.title ?? 'PASSE DE TEMPORADA')}</h2>
            <span class="progress progress--anim des-pass__bar" style="--v:${passPct}%" role="progressbar" aria-label="Progresso do passe" aria-valuemin="0" aria-valuemax="${passNext}" aria-valuenow="${passXp}"><span class="progress__bar"></span></span>
            <p class="des-pass__xp num"><span class="lime" data-count="${passXp}" data-dur="1100">${num(passXp)}</span> / ${num(passNext)} XP</p>
          </div>
        </div>

        <p class="des-pass__next">${String(pass.next_label ?? 'PRÓXIMAS RECOMPENSAS')}</p>
        <ul class="tier-list">
${join(
  ((pass.tiers ?? []) as Dict[]).map((tr) => {
    const state = String(tr.state ?? 'locked');
    return html`          <li class="tier${state === 'next' ? ' is-next' : ''}${state === 'owned' ? ' is-owned' : ''}">
            <span class="tier__box">
              <img class="tier__img" src="${media(String(tr.image ?? ''))}" alt="" loading="lazy">
              <span class="tier__lvl num">${num(int(tr.level ?? 0))}</span>
${state === 'locked'
  ? raw('              <span class="ms tier__lock" aria-hidden="true">lock</span>\n')
  : state === 'owned'
    ? raw('              <span class="ms tier__lock tier__lock--owned" aria-hidden="true">check</span>\n')
    : ''}            </span>
            <span class="tier__name">${String(tr.name ?? '')}</span>
          </li>
`;
  }),
)}        </ul>

        ${btn({ label: String(pass.cta ?? 'VER PASSE COMPLETO'), href: url(String(pass.href ?? 'loja')), variant: 'gold', block: true, chev: true, class: 'des-pass__cta' })}      </div>
    </section>
  </aside>
</div>
`;
}
