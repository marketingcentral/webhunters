/**
 * Tela OPERAÇÃO (/jogar) — app/Views/pages/jogar.php (PRD v3 §17).
 *
 * Os brasões dos modos e os glifos dos hexágonos são símbolos SVG locais (JOGAR_DEFS, copiados
 * do PHP): a sprite compartilhada só tem silhuetas maciças. Ids sempre prefixados jg-.
 */
import * as GR from '../../core/game-rules';
import { icon, media, num, svg, url } from '../../core/helpers';
import { e, html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge, progress } from '../components';
import { btn } from '../components/btn';
import { stepper } from '../components/stepper';
import { toggle } from '../components/toggle';

const JOGAR_DEFS = `<svg class="jogar-defs" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <!-- caveira + ossos cruzados (PARTIDA RÁPIDA / banner) -->
    <symbol id="jg-quick" viewBox="0 0 24 24">
      <g fill="currentColor">
        <g transform="rotate(22 12 19)">
          <rect x="4.7" y="18.2" width="14.6" height="1.5" rx=".75"/>
          <circle cx="5.2" cy="18.1" r="1"/><circle cx="5.2" cy="19.8" r="1"/>
          <circle cx="18.8" cy="18.1" r="1"/><circle cx="18.8" cy="19.8" r="1"/>
        </g>
        <g transform="rotate(-22 12 19)">
          <rect x="4.7" y="18.2" width="14.6" height="1.5" rx=".75"/>
          <circle cx="5.2" cy="18.1" r="1"/><circle cx="5.2" cy="19.8" r="1"/>
          <circle cx="18.8" cy="18.1" r="1"/><circle cx="18.8" cy="19.8" r="1"/>
        </g>
      </g>
      <path fill="currentColor" fill-rule="evenodd" d="M12 1.6c-4.2 0-7.5 3.1-7.5 7.1 0 2.4 1 4.3 2.6 5.6v1.5c0 .6.5 1.1 1.1 1.1h7.6c.6 0 1.1-.5 1.1-1.1v-1.5c1.6-1.3 2.6-3.2 2.6-5.6 0-4-3.3-7.1-7.5-7.1ZM8.6 6.9a2.1 2.1 0 1 0 0 4.2 2.1 2.1 0 0 0 0-4.2Zm6.8 0a2.1 2.1 0 1 0 0 4.2 2.1 2.1 0 0 0 0-4.2ZM12 11.1l-1.1 2.1h2.2L12 11.1Zm-1.4 3.5h.85v3h-.85Zm2 0h.85v3h-.85Z"/>
    </symbol>

    <!-- três operadores (MATA-MATA EM EQUIPE) -->
    <symbol id="jg-team" viewBox="0 0 24 24">
      <g fill="currentColor" opacity=".5">
        <circle cx="4.4" cy="9.9" r="2.3"/>
        <path d="M4.4 13c-2 0-3.6 1.5-3.6 3.4V19h7.2v-2.6c0-1.9-1.6-3.4-3.6-3.4Z"/>
        <circle cx="19.6" cy="9.9" r="2.3"/>
        <path d="M19.6 13c2 0 3.6 1.5 3.6 3.4V19H16v-2.6c0-1.9 1.6-3.4 3.6-3.4Z"/>
      </g>
      <g fill="currentColor">
        <circle cx="12" cy="7.6" r="3.3"/>
        <path d="M12 11.8c-2.7 0-4.9 2-4.9 4.5V19h9.8v-2.7c0-2.5-2.2-4.5-4.9-4.5Z"/>
      </g>
    </symbol>

    <!-- brasão: escudo vazado + estandarte (DOMINAÇÃO) -->
    <symbol id="jg-dom" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M12 2.3 20.4 5.2v6.4c0 4.7-3.4 8.7-8.4 10.3-5-1.6-8.4-5.6-8.4-10.3V5.2Z"/>
      <path fill="currentColor" d="M9.1 6.5h1.3v11H9.1z"/>
      <path fill="currentColor" d="M11.1 6.9h5.6l-1.6 2.3 1.6 2.3h-5.6z"/>
    </symbol>

    <!-- brasão: escudo vazado + zona disputada (ZONA DE CONFLITO) -->
    <symbol id="jg-zone" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M12 2.3 20.4 5.2v6.4c0 4.7-3.4 8.7-8.4 10.3-5-1.6-8.4-5.6-8.4-10.3V5.2Z"/>
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="m7.4 8.4 3 3.1-3 3.1M16.6 8.4l-3 3.1 3 3.1"/>
      <path fill="currentColor" d="M11.4 5.9h1.2v11.2h-1.2z" opacity=".55"/>
    </symbol>

    <!-- brasão: escudo vazado + retículo (CAÇA) -->
    <symbol id="jg-hunt" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M12 2.3 20.4 5.2v6.4c0 4.7-3.4 8.7-8.4 10.3-5-1.6-8.4-5.6-8.4-10.3V5.2Z"/>
      <circle cx="12" cy="11.2" r="3.7" fill="none" stroke="currentColor" stroke-width="1.4"/>
      <path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" d="M12 5.6v2.1M12 14.7v2.1M6.4 11.2h2.1M15.5 11.2h2.1"/>
      <circle cx="12" cy="11.2" r="1.2" fill="currentColor"/>
    </symbol>

    <!-- glifos compactos dos hexágonos dos cards de mapa -->
    <symbol id="jg-hx-skull" viewBox="0 0 24 24">
      <path fill="currentColor" fill-rule="evenodd" d="M12 1.9c-4.6 0-8.2 3.4-8.2 7.8 0 2.7 1.1 4.8 2.9 6.2v1.9c0 .7.6 1.3 1.3 1.3h8c.7 0 1.3-.6 1.3-1.3v-1.9c1.8-1.4 2.9-3.5 2.9-6.2 0-4.4-3.6-7.8-8.2-7.8ZM8.2 7.8a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6Zm7.6 0a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6ZM12 12.5l-1.2 2.3h2.4L12 12.5Zm-1.55 3.7h.9v3.2h-.9Zm2.2 0h.9v3.2h-.9Z"/>
      <path fill="currentColor" d="M6.6 20.1h10.8v1.5H6.6z" opacity=".5"/>
    </symbol>
    <symbol id="jg-hx-shield" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 1.9 21 5v6.8c0 5-3.7 9.3-9 11-5.3-1.7-9-6-9-11V5Z"/>
      <path fill="currentColor" d="M11.1 6.2h1.7v11.4h-1.7z"/>
      <path fill="currentColor" d="M13.5 6.7h4.6l-1.5 2.2 1.5 2.2h-4.6z"/>
    </symbol>
    <symbol id="jg-hx-target" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="7.4" fill="none" stroke="currentColor" stroke-width="1.8"/>
      <circle cx="12" cy="12" r="2.4" fill="currentColor"/>
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 1.8v3.4M12 18.8v3.4M1.8 12h3.4M18.8 12h3.4"/>
    </symbol>
  </defs>
</svg>`;

/** Ids permitidos dos símbolos locais (nada vindo do mock entra cru no href). */
const CRESTS = ['jg-quick', 'jg-team', 'jg-dom', 'jg-zone', 'jg-hunt', 'jg-hx-skull', 'jg-hx-shield', 'jg-hx-target'];
const crest = (id: unknown, fallback: string): string => (CRESTS.includes(String(id ?? '')) ? String(id) : fallback);

const obj = (v: unknown): Dict => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const arr = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : v && typeof v === 'object' ? (Object.values(v) as Dict[]) : []);

/** json_encode(..., JSON_UNESCAPED_UNICODE): o PHP escapa "/" como "\/". */
const jsonPhp = (v: unknown): string => JSON.stringify(v).replace(/\//g, '\\/');

/** sprintf com um único %d. */
const sprintfD = (fmt: string, n: number): string => fmt.replace('%d', String(n | 0));

export function jogar(s: Scope): SafeHtml {
  const d = s.data;
  const player = s.player;
  const maps = arr(d.maps);
  const challenges = arr(d.challenges);
  const modes = arr(d.modes);
  const prefs = arr(d.prefs);
  const banner = obj(d.banner);
  const squad = obj(d.squad);
  const reward = obj(d.daily_reward);
  const modeIcons = obj(d.mode_icons);
  const resetIn = String(d.reset_in ?? '12h 45m');
  const members = arr(squad.members);
  const slots = Math.max(0, (Number(squad.max ?? 4) | 0) - members.length);

  // ---------------------------------------------------------------- coluna esquerda
  const modo = (m: Dict): SafeHtml => {
    /* PRD v3 §17.2: os itens são ESTADOS OPERACIONAIS. `href` vira link (FACÇÕES abre
       /faccoes); `survivor` é informativo — nunca selecionável por vontade. */
    const locked = Boolean(m.locked);
    const act = Boolean(m.active);
    const sym = crest(m.icon, 'jg-quick');
    const href = m.href !== undefined && m.href !== null ? String(m.href) : null;
    const isSurv = Boolean(m.survivor);
    const tag = href !== null ? 'a' : locked || isSurv ? 'div' : 'button';
    const cls = 'row jogar-mode jogar-mode--' + e(String(m.slug ?? '')) + (act ? ' is-active' : '') + (locked ? ' is-locked' : '') + (isSurv ? ' jogar-mode--survivor' : '');
    const heroKey = isSurv ? 'survivor' : String(m.slug ?? 'hunter');
    const extra =
      href !== null
        ? html` href="${url(href)}"`
        : locked
          ? html` data-tip="${String(m.lock_tip ?? 'Indisponível')}"`
          : isSurv
            ? raw(' aria-current="true"')
            : raw(` type="button" data-select aria-pressed="${act ? 'true' : 'false'}"`);
    const fim = locked
      ? raw('            <span class="ms row__lock" aria-hidden="true">lock</span>\n')
      : isSurv
        ? html`            ${badge({ kind: 'state', value: d.operational ?? {}, label: 'STATUS ATIVO', class: 'jogar-mode__badge' })}`
        : raw(`            <span class="ms row__chev" aria-hidden="true">${href !== null ? 'open_in_new' : 'chevron_right'}</span>\n`);
    return html`          <${raw(tag)} class="${raw(cls)}" data-value="${String(m.slug ?? '')}" data-hero="${heroKey}"${extra}>
            <span class="row__icon jogar-mode__crest" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><use href="#${sym}"></use></svg></span>
            <span class="row__text"><strong>${String(m.title ?? '')}</strong><small>${String(m.desc ?? '')}</small></span>
${m.faction ? html`            ${badge({ kind: 'faction', value: String(m.faction), class: 'jogar-mode__badge' })}` : ''}${fim}          </${raw(tag)}>
`;
  };

  const pref = (p: Dict): SafeHtml => html`          <div class="pref">
            <span class="pref__label">${String(p.label ?? '')}</span>
${(p.type ?? '') === 'toggle'
    ? html`            ${toggle({ name: 'pref_' + String(p.key ?? ''), checked: Boolean(p.value), on: 'Ativado', off: 'Desativado', lime: true, class: 'pref__toggle' })}`
    : html`            ${stepper({ options: Array.isArray(p.options) ? p.options : [String(p.text ?? '')], value: String(p.text ?? ''), name: 'pref_' + String(p.key ?? ''), class: 'pref__stepper' })}`}          </div>
`;

  // ---------------------------------------------------------------- coluna central
  /* Hero central por ESTADO (PRD v3 §17.4–17.6); os outros heróis ficam em data-* para o
     jogar.js trocar sem recarregar. */
  const hero = d.hero && typeof d.hero === 'object' ? (d.hero as Dict) : banner;
  const heroes = obj(d.heroes);
  const heroKey = String(hero.key ?? 'hunter');
  const heroesJson: Dict = {};
  for (const [k, h0] of Object.entries(heroes)) {
    const h = obj(h0);
    heroesJson[k] = { title: String(h.title ?? ''), desc: String(h.desc ?? ''), cta: String(h.cta ?? '') };
  }
  const heroUse = heroKey === 'mercenary' ? 'jg-hunt' : heroKey === 'survivor' ? 'jg-zone' : 'jg-quick';

  const cap = d.capacity && typeof d.capacity === 'object' ? (d.capacity as Dict) : GR.REGION_CAPACITY;
  const capL = obj(d.capacity_labels);
  const mapL = obj(d.map_labels);

  const mapCard = (mp: Dict): SafeHtml => {
    const mode = String(mp.mode ?? '');
    const sel = Boolean(mp.selected);
    const sym = crest(modeIcons[mode], 'jg-hx-skull');
    const inf = Number(mp.infestation ?? 0) | 0;
    const infE = GR.infestation(inf);
    const hum = String(mp.human_activity ?? 'MODERADA');
    return html`          <article class="card map-card${sel ? ' is-selected' : ''}" data-value="${String(mp.slug ?? '')}" data-ue-level="${String(mp.ue_level ?? '')}"${sel ? raw(' aria-selected="true"') : ''}>
            <div class="card__media">
              <img src="${media(String(mp.image ?? ''))}" alt="${String(mp.name ?? '')}" loading="lazy">
            </div>
            <div class="card__body card__body--row">
              <svg class="jg-hex map-card__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
                <polygon class="jg-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
                <use href="#${sym}" x="11" y="15.5" width="30" height="30"></use>
              </svg>
              <div class="grid min-0 map-card__lines">
                <h3 class="card__title">${String(mp.name ?? '')}</h3>
                <span class="card__sub map-card__world">
                  <span class="map-card__inf map-card__inf--${infE.color}">${String(mapL.infest ?? 'INFESTAÇÃO')} <b class="num">${String(inf)}%</b></span>
                </span>
                <span class="card__meta map-card__human">
                  ${String(mapL.human ?? 'ATIVIDADE HUMANA')} <b class="map-card__act map-card__act--${GR.activityColor(hum)}">${hum}</b>
                  <i class="map-card__persist">${String(mapL.world ?? 'MUNDO')}: ${String(mapL.persistent ?? 'PERSISTENTE')}</i>
                </span>
              </div>
            </div>
            <span class="map-card__check" aria-hidden="true">${icon('check')}</span>
          </article>
`;
  };

  // ---------------------------------------------------------------- coluna direita
  const membro = (mb: Dict): SafeHtml => {
    const self = Boolean(mb.self);
    const mName = self ? String(player.name ?? mb.name ?? '') : String(mb.name ?? '');
    const mLvl = self ? Number(player.level ?? mb.level ?? 0) | 0 : Number(mb.level ?? 0) | 0;
    const mImg = self ? String(player.avatar ?? mb.avatar ?? '') : String(mb.avatar ?? '');
    return html`        <a class="squad-member" href="${url('perfil')}">
          <span class="squad-member__pic"><img class="squad-member__avatar" src="${media(mImg)}" alt=""></span>
          <span class="squad-member__text">
            <strong>${mName}</strong>
            <small>Nível <span class="num">${num(mLvl)}</span>${mb.leader ? svg('crown', 'squad-member__crown') : ''}</small>
          </span>
          <span class="dot${!mb.online ? ' dot--off' : ''} squad-member__dot" aria-hidden="true"></span>
        </a>
`;
  };

  const desafio = (ch: Dict): SafeHtml => {
    const cur = Number(ch.cur ?? 0) | 0;
    const max = Math.max(1, Number(ch.max ?? 1) | 0);
    const xp = Number(ch.xp ?? 0) | 0;
    const hot = xp >= 5000;
    return html`          <li class="chal">
            <div class="chal__main">
              <div class="chal__head">
                <span class="chal__label">${String(ch.label ?? '')}</span>
                <span class="chal__count num">${num(cur)} / ${num(max)}</span>
              </div>
              ${progress({ value: cur, max, size: 'thin', anim: true, flat: true, class: 'chal__bar' })}            </div>
            <span class="xp-chip${hot ? ' xp-chip--purple' : ''}">
              <span class="xp-chip__badge">XP</span>
              <span class="xp-chip__val num">${String(xp)}</span>
            </span>
          </li>
`;
  };

  return html`${raw(JOGAR_DEFS)}

<div class="layout-3 jogar">

  <!-- ================= COLUNA ESQUERDA ================= -->
  <div class="col jogar-left">

    <header class="jogar-head" data-boot>
      <h1 class="page-title">${String(d.title ?? 'JOGAR SOLO')}<span class="page-title__tag">${String(d.subtitle ?? '')}</span></h1>
      <p class="page-subtitle">${String(d.intro ?? '')}</p>
    </header>

    <section class="panel jogar-modes panel--grow" data-boot>
      <div class="panel__body">
        <h2 class="panel__section">${String(d.modes_title ?? 'MODO DE JOGO')}</h2>
        <div class="list jogar-modes__list" data-select-group data-stagger>
${join(modes.map(modo))}        </div>
      </div>
    </section>

    <section class="panel jogar-prefs" data-boot>
      <div class="panel__body">
        <h2 class="panel__section">${String(d.prefs_title ?? 'PREFERÊNCIAS DE PARTIDA')}</h2>
        <div class="pref-list">
${join(prefs.map(pref))}        </div>
      </div>
    </section>

    <section class="panel jogar-cta" data-boot>
      <div class="panel__body">
        ${btn({ label: String(d.cta ?? 'BUSCAR PARTIDA'), href: url('lobby'), variant: 'primary', size: 'lg', block: true, chev: true, class: 'jogar-cta__btn', attrs: { 'data-ue-play': '' } })}        <p class="jogar-eta">${icon('schedule')}<span>${String(d.eta_label ?? 'TEMPO ESTIMADO:')}</span> <span class="num">${String(d.eta ?? '')}</span></p>
      </div>
    </section>
  </div>

  <!-- ================= COLUNA CENTRAL ================= -->
  <div class="col jogar-mid">

    <section class="jogar-banner jogar-hero jogar-hero--${heroKey}" data-boot data-hero-current="${heroKey}"
             data-heroes="${jsonPhp(heroesJson)}">
      <img class="jogar-banner__img" src="${media(String(hero.image ?? 'bg/jogar-banner.jpg'))}" alt="">
      <div class="jogar-banner__shade" aria-hidden="true"></div>
      <div class="jogar-banner__body">
        <svg class="jg-hex jogar-banner__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
          <polygon class="jg-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
          <g class="jg-hex__dots"><circle cx="5.4" cy="18.2" r="1"/><circle cx="46.6" cy="18.2" r="1"/><circle cx="5.4" cy="41.8" r="1"/><circle cx="46.6" cy="41.8" r="1"/></g>
          <use href="#${heroUse}" x="10.5" y="15.5" width="31" height="31"></use>
        </svg>
        <div class="jogar-banner__text">
          <h2 class="jogar-banner__title" data-hero-title>${String(hero.title ?? '')}</h2>
          <p class="jogar-banner__desc" data-hero-desc>${String(hero.desc ?? '')}</p>
        </div>
      </div>
      <ul class="jogar-hero__stats" aria-label="Situação operacional">
${join(arr(hero.stats).map((hs) => html`        <li class="hstat${hs.cls ? ' hstat--' + String(hs.cls) : ''}">
          <span class="hstat__k">${String(hs.k)}</span>
          <span class="hstat__v${hs.num ? ' num' : ''}">${String(hs.v)}${hs.sub ? html` <small>${String(hs.sub)}</small>` : ''}</span>
        </li>
`))}      </ul>
    </section>

    <section class="panel jogar-maps panel--grow" data-boot>
      <div class="panel__body">
        <div class="jogar-maps__head">
          <h2 class="panel__section">${String(d.maps_title ?? 'REGIÕES OPERACIONAIS')}</h2>
          <p class="jogar-cap" data-tip="Sobreviventes não ampliam o teto: ocupam vagas existentes.">
            <span class="jogar-cap__k">${String(capL.title ?? 'CAPACIDADE DO SETOR')}</span>
            <span class="jogar-cap__v num">${String(cap.total ?? 20)}</span>
            <span class="jogar-cap__sep" aria-hidden="true">·</span>
            <span class="jogar-cap__k">${String(capL.structure ?? 'ESTRUTURA')}</span>
            <span class="jogar-cap__v"><span class="num">${String(cap.hunters ?? 12)}</span> ${String(capL.hunters ?? 'HUNTERS')} / ${sprintfD(String(capL.mercs ?? 'ATÉ %d MERCENÁRIOS'), Number(cap.mercenaries ?? 8))}</span>
          </p>
        </div>
        <div class="map-grid" data-select-group>
${join(maps.slice(0, 8).map(mapCard))}        </div>
        <div class="jogar-maps__foot">
          ${btn({ label: String(d.maps_action ?? 'VER TODOS OS MAPAS'), href: url('mapas'), variant: 'outline', class: 'jogar-maps__all' })}        </div>
      </div>
    </section>
  </div>

  <!-- ================= COLUNA DIREITA ================= -->
  <div class="col jogar-right">

    <section class="panel jogar-squad" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(squad.title ?? 'ESQUADRÃO')}</h2>
        <span class="panel__meta num">${num(Number(squad.count ?? members.length) | 0)} / ${num(Number(squad.max ?? 4) | 0)}</span>
      </header>
      <div class="panel__body">
${join(members.map(membro))}${slots > 0 ? html`        <div class="squad-slots">
          <a class="squad-slot" href="${url('amigos')}" data-tip="${String(squad.add_label ?? 'CONVIDAR JOGADOR')}" aria-label="${String(squad.add_label ?? 'CONVIDAR JOGADOR')}">${icon('add')}</a>
        </div>
` : ''}      </div>
    </section>

    <section class="panel jogar-challenges panel--grow" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(d.challenges_title ?? 'DESAFIOS DIÁRIOS')}</h2>
        <span class="panel__meta">${String(d.challenges_reset ?? 'REINICIA EM:')} <span class="num" data-countdown="${resetIn}">${resetIn}</span></span>
      </header>
      <div class="panel__body">
        <ul class="chal-list">
${join(challenges.map(desafio))}        </ul>
      </div>
      <footer class="panel__foot">
        <a class="panel__action panel__action--lime chal-all" href="${url('desafios')}">${String(d.challenges_action ?? 'VER TODOS OS DESAFIOS')}</a>
      </footer>
    </section>

    <section class="panel jogar-reward" data-boot>
      <div class="panel__body">
        <h2 class="panel__section reward__title">${String(reward.title ?? 'RECOMPENSA DIÁRIA')}</h2>
        <p class="reward__next">${String(reward.next_label ?? '')} <span class="num lime" data-countdown="${String(reward.next_in ?? '')}">${String(reward.next_in ?? '')}</span></p>
        <div class="reward__item">
          <span class="reward__thumb"><img src="${media(String(reward.image ?? 'items/supply-crate.png'))}" alt="${String(reward.name ?? '')}"></span>
          <span class="reward__text">
            <strong>${String(reward.name ?? '')}</strong>
            <small>${String(reward.desc ?? '')}</small>
          </span>
        </div>
        ${btn({ label: String(reward.cta ?? 'RESGATAR RECOMPENSA'), type: 'button', variant: 'lime', block: true, class: 'reward__btn', attrs: { 'data-claim': '' } })}      </div>
    </section>
  </div>
</div>
`;
}
