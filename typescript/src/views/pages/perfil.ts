/**
 * Tela PERFIL — app/Views/pages/perfil.php. Layout hud, três colunas
 * (operador | estatísticas | histórico). CSS: css/pages/perfil.css  JS: js/pages/perfil.js
 * $data: src/data/perfil.json + o que o ProfileController troca pelo banco.
 */
import { icon, media, num, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { btn } from '../components/btn';
import { badge, hex } from '../components/index';
import { tabs } from '../components/tabs';

const CORES = ['lime', 'gold', 'cyan', 'amber', 'red', 'purple'];
const colorClass = (c: string): string => (CORES.includes(c) ? ' is-' + c : '');

/** "AAAA-MM-DD HH:MM:SS" -> "HH:MM:SS" (o date('H:i:s', strtotime()) do PHP). */
function hora(dt: string): string {
  const m = /(\d{2}:\d{2}:\d{2})/.exec(dt);
  return m ? m[1]! : '00:00:00';
}

export function perfil(s: Scope): SafeHtml {
  const data = s.data;
  const player = s.player;
  const op: Dict = data.operator ?? {};
  const opMeta = (op.meta ?? []) as Dict[];
  const stats = (data.stats ?? []) as Dict[];
  const achieves = (data.achievements ?? []) as Dict[];
  const weapons = (data.weapons ?? []) as Dict[];
  const classes = (data.classes ?? []) as Dict[];
  const recent = (data.recent ?? []) as Dict[];
  const badges = (data.badges ?? []) as Dict[];
  const actions: Dict = data.actions ?? {};

  const pName = String(player.name ?? 'HUNTER_07');
  const pLevel = Number(player.level ?? 1) | 0;
  const pXp = Number(player.xp ?? 0) | 0;
  const pXpMax = Math.max(1, Number(player.xp_next ?? 1) | 0);
  const pPct = Math.round(Math.min(100, Math.max(0, (pXp / pXpMax) * 100)) * 100) / 100;
  const portrait = String(data.portrait ?? 'portraits/op-assault.jpg');
  const pfOp: Dict = data.operational ?? {};

  /* PRD v3 §21.6: estatísticas por estado. GERAL é a grade que já existia; as outras
     abas vêm do controller com o que o backend já mede (o resto aparece como "—"). */
  const stTabs = (data.stats_tabs ?? []) as Dict[];
  const tabItems: Dict[] = [{ label: 'GERAL', panel: 'geral', active: true }];
  for (const t of stTabs) tabItems.push({ label: String(t.label), panel: String(t.slug) });

  /* PRD v3 §21.3–21.4: contrato mercenário e reputação com as duas facções */
  const fc: Dict = data.contract_block ?? {};
  const fcAtiva: Dict | null = fc.active && typeof fc.active === 'object' ? fc.active : null;
  const fcCta: Dict = fc.cta ?? {};

  return html`<div class="page-head" data-boot>
  <h1 class="page-title">${String(data.title ?? 'PERFIL')} <small>${pName}</small></h1>
  <p class="pf-head__sub">
    <span class="pf-head__org">${icon('handshake')}${String(data.organization ?? 'HUNTER COMMAND')}</span>
    <span class="pf-head__sep" aria-hidden="true"></span>
    ${badge({ kind: 'state', value: pfOp, level: pLevel })}
    <span class="pf-head__sep" aria-hidden="true"></span>
    <span class="pf-head__rank">${icon('military_tech')}${String(data.faction_rank ?? '')}</span>
${data.reputation_of ? html`    <span class="pf-head__sep" aria-hidden="true"></span>
    <span class="pf-head__rep">REP ${String(data.reputation_of)} <span class="num lime">${num(Number(data.reputation ?? 0) | 0)}</span></span>
` : ''}  </p>
</div>

<div class="pf">

  <!-- ======================= coluna 1 — operador ======================= -->
  <div class="pf__col pf__col--l col">

    <section class="panel panel--hot panel--flush panel--grow op" data-boot>
      <div class="op__media">
        <img class="op__img" src="${media(portrait)}" alt="${pName}" decoding="async">
        <span class="op__tag op__tag--class">${svg(String(op.class_icon ?? 'class-assault'))}${String(op.class ?? 'ASSAULT')}</span>
        <span class="op__tag op__tag--status"><i aria-hidden="true"></i>${String(op.status ?? '')}</span>
        <div class="op__id">
          ${hex({ size: 'lg', color: 'lime', text: pLevel, hot: true, ring: true, class: 'op__hex', title: 'Nível ' + pLevel })}
          <div class="op__idtxt">
            <span class="op__label">${String(op.callsign ?? 'INDICATIVO')}</span>
            <strong class="op__name">${pName}</strong>
            <span class="op__squad">${icon('group')}${String(op.squad ?? '')}</span>
          </div>
        </div>
      </div>

      <div class="op__body">
        <div class="op__xp">
          <div class="op__xp-head">
            <span class="op__xp-lvl">NÍVEL <span class="num">${pLevel}</span></span>
            <span class="op__xp-val num"><span class="lime" data-count="${pXp}" data-dur="1200">${num(pXp)}</span> / ${num(pXpMax)} XP</span>
          </div>
          <div class="progress progress--anim op__bar" style="--v:${String(pPct)}%" role="progressbar" aria-label="Progresso de nível" aria-valuemin="0" aria-valuemax="${pXpMax}" aria-valuenow="${pXp}"><div class="progress__bar"></div></div>
        </div>

        <ul class="op__meta" data-stagger>
${join(opMeta.map((m) => html`          <li class="op__meta-row">
            <span class="op__meta-ic${colorClass(String(m.color ?? ''))}">${icon(String(m.icon ?? 'chevron_right'))}</span>
            <span class="op__meta-label">${String(m.label ?? '')}</span>
            <span class="op__meta-val${colorClass(String(m.color ?? ''))}">${String(m.value ?? '')}</span>
          </li>
`))}          <li class="op__meta-row op__meta-row--live">
            <span class="op__meta-ic is-lime">${icon('radar')}</span>
            <span class="op__meta-label">SESSÃO ATUAL</span>
            <span class="op__meta-val is-lime num" data-session="${String(op.session_seconds ?? 0)}">00:00:00</span>
          </li>
        </ul>

        ${btn({
          label: String(actions.edit ?? 'EDITAR PERFIL'),
          href: url('configuracoes/conta'),
          variant: 'primary',
          icon: 'account_circle',
          chev: true,
          block: true,
          class: 'op__edit',
        })}
      </div>
    </section>

    <section class="panel bg-badges" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(data.badges_title ?? 'INSÍGNIAS')} <small class="num">${badges.length}</small></h2>
        <a class="panel__action" href="${url('conquistas')}">VER TODAS${icon('add')}</a>
      </header>
      <div class="panel__body panel__body--tight bg-badges__body" data-stagger>
${join(badges.map((b) => html`        <a class="bdg" href="${url('conquistas')}" data-tip="${String(b.label ?? '')}">
          ${hex({ size: 'md', icon: String(b.icon ?? 'star'), color: String(b.rarity ?? 'lime'), ring: true, class: 'bdg__hex' })}
          <span class="bdg__label">${String(b.label ?? '')}</span>
        </a>
`))}      </div>
    </section>

  </div>

  <!-- ================== coluna 2 — estatísticas / armas ================== -->
  <div class="pf__col pf__col--c col">

    <section class="panel st" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('bar_chart')}${String(data.stats_title ?? 'ESTATÍSTICAS')}</h2>
        <span class="panel__meta">${String(data.stats_meta ?? '')}</span>
      </header>
      <div class="st__tabs">${tabs({ items: tabItems, label: 'Estatísticas por estado', class: 'st__tabnav' })}</div>
${join(stTabs.map((t) => html`      <div class="panel__body st__body" data-tab-panel="${String(t.slug)}" hidden>
${join(((t.items ?? []) as Dict[]).map((c) => html`        <div class="st-cell${colorClass(String(c.color ?? ''))}${c.dep ? ' is-dep' : ''}">
          <span class="st-cell__ic">${icon(String(c.icon ?? 'star'))}</span>
          <span class="st-cell__val num">${c.n !== undefined && c.n !== null ? num(Number(c.n) | 0) : '—'}</span>
          <span class="st-cell__label">${String(c.label ?? '')}</span>
          <span class="st-cell__sub${c.dep ? ' is-dep' : ''}">${String(c.sub ?? '')}</span>
        </div>
`))}      </div>
`))}      <div class="panel__body st__body" data-stagger data-tab-panel="geral">
${join(stats.map((c) => {
  const sn = c.n ?? 0;
  const sDec = Number(c.dec ?? 0) | 0;
  const sSuf = String(c.suffix ?? '');
  return html`        <div class="st-cell${c.hot ? ' is-hot' : ''}${colorClass(String(c.color ?? ''))}">
          <span class="st-cell__ic">${icon(String(c.icon ?? 'star'))}</span>
          <span class="st-cell__val num"><span data-count="${String(sn)}" data-dec="${sDec}" data-suffix="${sSuf}" data-dur="1100">${num(sn, sDec) + sSuf}</span>${c.unit ? html`<small>${String(c.unit)}</small>` : ''}</span>
          <span class="st-cell__label">${String(c.label ?? '')}</span>
          <span class="st-cell__sub">${String(c.sub ?? '')}</span>
        </div>
`;
}))}      </div>
    </section>

    <section class="panel ac" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('military_tech')}${String(data.achievements_title ?? 'CONQUISTAS RECENTES')}</h2>
        <a class="panel__action" href="${url('conquistas')}">${String(data.achievements_action ?? 'VER TODAS')}${icon('add')}</a>
      </header>
      <div class="panel__body ac__body" data-stagger>
${join(achieves.map((a) => html`        <a class="ac-card${colorClass(String(a.color ?? 'lime'))}" href="${url('conquistas')}">
          ${hex({ size: 'xl', icon: String(a.icon ?? 'star'), color: String(a.color ?? 'lime'), ring: true, hot: true, class: 'ac-card__hex' })}
          <strong class="ac-card__name">${String(a.label ?? '')}</strong>
          <small class="ac-card__desc">${String(a.desc ?? '')}</small>
          <span class="ac-card__foot">
            <span class="ac-card__state">CONCLUÍDA</span>
            <span class="ac-card__date num">${String(a.date ?? '')}</span>
          </span>
          <span class="ac-card__xp num">+${num(Number(a.xp ?? 0) | 0)} XP</span>
        </a>
`))}      </div>
    </section>

    <section class="panel wp" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('swords')}${String(data.weapons_title ?? 'ARMAS FAVORITAS')}</h2>
        <a class="panel__action" href="${url('inventario')}">${String(data.weapons_action ?? 'INVENTÁRIO')}${icon('chevron_right')}</a>
      </header>
      <div class="panel__body wp__body" data-stagger>
${join(weapons.map((w) => html`        <a class="wp-card${w.main ? ' is-main' : ''}" href="${url('inventario')}">
          <span class="wp-card__art">
            <img class="wp-card__img" src="${media(String(w.img ?? 'items/ak103.png'))}" alt="${String(w.name ?? '')}" loading="lazy" decoding="async" draggable="false">
          </span>
          <span class="wp-card__info">
            <span class="wp-card__head">
              <strong class="wp-card__name">${String(w.name ?? '')}</strong>
${w.main ? raw('              <span class="chip chip--lime">PRINCIPAL</span>\n') : ''}            </span>
            <small class="wp-card__type">${String(w.type ?? '')} <i class="num">${String(w.caliber ?? '')}</i></small>
            <span class="wp-card__stats">
              <span class="wp-stat"><b class="num">${num(Number(w.kills ?? 0) | 0)}</b><i>ELIMINAÇÕES</i></span>
              <span class="wp-stat"><b class="num">${String(w.accuracy ?? 0)}%</b><i>PRECISÃO</i></span>
              <span class="wp-stat wp-stat--use">
                <b class="num">${String(w.usage ?? 0)}%</b><i>USO</i>
                <span class="progress progress--anim progress--thin wp-stat__bar" style="--v:${Number(w.usage ?? 0) | 0}%"><span class="progress__bar"></span></span>
              </span>
            </span>
          </span>
        </a>
`))}      </div>
    </section>

  </div>

  <!-- ================= coluna 3 — classes / atividade ================= -->
  <div class="pf__col pf__col--r col">

    <section class="panel fc" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(fc.title ?? 'CONTRATO MERCENÁRIO')}</h2>
        <a class="panel__action" href="${url(String(fcCta.href ?? 'faccoes'))}">FACÇÕES${icon('chevron_right')}</a>
      </header>
      <div class="fc__body">
${fc.survivor
    ? html`        <p class="fc__none">${badge({ kind: 'state', value: pfOp })}<span>SEM BANDEIRA · NENHUMA FACÇÃO ASSINA</span></p>
`
    : fcAtiva === null
      ? html`        <p class="fc__none"><span>${String(fc.none ?? 'NENHUM CONTRATO ATIVO')}</span>${badge({ kind: 'origin', value: 'hunter_command' })}</p>
`
      : html`        <p class="fc__none">${badge({ kind: 'faction', value: String(pfOp.faction ?? ''), solid: true })}<span>REP <b class="num">${num(Number(fcAtiva.rep ?? 0) | 0)}</b> · ${String(fcAtiva.tier ?? '')}</span></p>
`}${join(((fc.factions ?? []) as Dict[]).map((f) => html`        <div class="fc__row fc__row--${String(f.color)}${f.active ? ' is-active' : ''}">
          ${badge({ kind: 'faction', value: String(f.slug) })}
          <span class="fc__rep"><b class="num">${num(Number(f.rep) | 0)}</b><small>${String(f.tier)}</small></span>
${String(f.state) !== String(f.tier) || f.locked_until
    ? html`          <span class="fc__state${f.locked ? ' is-locked' : ''}">${String(f.state)}${f.locked_until ? html` <span class="num" data-countdown-to="${String(f.locked_until)}">${hora(String(f.locked_until))}</span>` : ''}</span>
`
    : ''}        </div>
`))}        <div class="fc__actions">
          ${btn({ label: String(fcCta.benefits ?? 'VER BENEFÍCIOS'), href: url(String(fcCta.href ?? 'faccoes')), variant: 'outline', size: 'sm' })}
          ${btn({ label: String(fcCta.manage ?? 'GERENCIAR CONTRATO'), href: url(String(fcCta.href ?? 'faccoes')), variant: 'primary', size: 'sm', chev: true })}
        </div>
      </div>
    </section>

    <section class="panel cl" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(data.classes_title ?? 'FUNÇÕES HUNTER')}</h2>
        <a class="panel__action" href="${url('habilidades')}">HABILIDADES${icon('chevron_right')}</a>
      </header>
      <div class="panel__body cl__body" data-stagger>
${join(classes.map((c) => html`        <a class="cl-row${c.main ? ' is-main' : ''}" href="${url('habilidades')}">
          <span class="cl-row__ic">${svg(String(c.icon ?? 'class-assault'))}</span>
          <span class="cl-row__txt">
            <span class="cl-row__head">
              <strong class="cl-row__label">${String(c.label ?? '')}</strong>
              <span class="cl-row__lvl num">NV ${String(c.level ?? 0)}</span>
            </span>
            <span class="progress progress--anim progress--thin cl-row__bar" style="--v:${Number(c.pct ?? 0) | 0}%"><span class="progress__bar"></span></span>
          </span>
          <span class="cl-row__pct num">${String(c.pct ?? 0)}%</span>
        </a>
`))}      </div>
    </section>

    <section class="panel panel--grow rc" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${icon('schedule')}${String(data.recent_title ?? 'ATIVIDADE RECENTE')}</h2>
        <a class="panel__action" href="${url('missoes')}">MISSÕES${icon('chevron_right')}</a>
      </header>
      <div class="panel__body rc__body">
        <ol class="rc__list scroll-y" data-stagger>
${join(recent.map((r) => html`          <li class="rc-item${colorClass(String(r.color ?? ''))}">
            <span class="rc-item__dot" aria-hidden="true"></span>
            <span class="rc-item__ic">${icon(String(r.icon ?? 'star'))}</span>
            <span class="rc-item__txt">
              <span class="rc-item__text">${String(r.text ?? '')}</span>
              <span class="rc-item__time num">${String(r.time ?? '')}</span>
            </span>
          </li>
`))}        </ol>
        <div class="rc__foot">
          <a class="btn btn--outline btn--sm rc__btn" href="${url('amigos')}">${icon('group')}AMIGOS</a>
          <a class="btn btn--outline btn--sm rc__btn" href="${url('mensagens')}">${icon('mail')}MENSAGENS</a>
        </div>
      </div>
    </section>

  </div>
</div>
`;
}
