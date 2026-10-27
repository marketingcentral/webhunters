/**
 * Tela MISSÕES / CONTRATOS — app/Views/pages/missoes.php.
 * PRD v3 §24: card com ORIGEM, ESQUADRÃO 1–4 e REQUER CONTRATO quando a operação é de
 * facção. Os filtros da esquerda são por ORIGEM (data-origin) — ver assets/js/pages/missoes.js.
 */
import { icon, media, num, svg, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge } from '../components';

const int = (v: unknown): number => Math.trunc(Number(v) || 0);

/** round($x, 1) do PHP, impresso como o PHP imprime float (50 e não 50.0). */
const round1 = (v: number): string => String(Math.round(v * 10) / 10);

/** hh:mm:ss a partir de segundos. */
function hms(s0: number): string {
  const s = Math.max(0, s0);
  const p = (n: number): string => String(n).padStart(2, '0');
  return `${p(Math.floor(s / 3600))}:${p(Math.floor((s % 3600) / 60))}:${p(s % 60)}`;
}

/** Pips de dificuldade (1..4). */
function pips(level: number): SafeHtml {
  const tone = ({ 1: 'lime', 2: 'lime', 3: 'amber', 4: 'red' } as Record<number, string>)[level] ?? 'lime';
  let out = `<span class="pips pips--${tone}" aria-hidden="true">`;
  for (let i = 1; i <= 4; i++) out += `<i class="pips__i${i <= level ? ' is-on' : ''}"></i>`;
  return raw(out + '</span>');
}

/** Anel da recompensa: "XP" em texto ou o glifo do sprite. */
function anel(r: Dict, pref: string): SafeHtml {
  return (r.icon ?? 'xp') === 'xp' ? html`<span class="${pref}__xp">XP</span>` : svg(String(r.icon), pref + '__ic');
}

export function missoes(s: Scope): SafeHtml {
  const d = s.data;
  const items = (d.items ?? []) as Dict[];
  const filters = (d.filters ?? []) as Dict[];
  const types = (d.types ?? []) as Dict[];
  const labels: Dict = d.labels ?? {};
  const reset: Dict = d.reset ?? {};
  const board: Dict = d.board ?? {};

  /** Missão selecionada (fallback: a primeira). */
  let selected = String(items[0]?.slug ?? '');
  const marcada = items.find((it) => it.selected);
  if (marcada) selected = String(marcada.slug);

  const squad = (m: Dict): string => String(m.squad ?? int(m.players ?? 4));
  const bv = int(board.total ?? 0) > 0 ? round1((int(board.done) / int(board.total)) * 100) : '0';

  const cards = items.map((m) => {
    const st = String(m.status ?? 'available');
    const sel = String(m.slug) === selected;
    const rw = (m.rewards ?? []) as Dict[];
    const pg: Dict = m.progress ?? {};
    const temPg = Object.keys(pg).length > 0;
    const rq: Dict = m.requirement && typeof m.requirement === 'object' ? m.requirement : {};
    const temRq = Object.keys(rq).length > 0;
    const it: Dict = m.reward_item ?? {};
    const temIt = Object.keys(it).length > 0;
    const pv = temPg && int(pg.max) > 0 ? round1((int(pg.cur) / int(pg.max)) * 100) : '0';

    let estado: SafeHtml | string = '';
    if (st === 'active' && temPg) {
      estado = html`<span class="mprog">
                    <span class="mprog__num num">${int(pg.cur)} / ${int(pg.max)}</span>
                    <span class="progress progress--anim mprog__bar" style="--v:${pv}%"><span class="progress__bar"></span></span>
                  </span>`;
    } else if (m.expires_in) {
      estado = html`<span class="mexp">${icon('schedule', 'mexp__ic')}<span class="num" data-countdown="${int(m.expires_in)}">${hms(int(m.expires_in))}</span></span>`;
    } else if (st === 'done') {
      estado = html`<span class="mdone">${icon('check_circle', 'fill')}${String(m.status_label ?? '')}</span>`;
    } else if (st === 'locked') {
      estado = html`<span class="mlock">${icon('lock')}NÍVEL ${int(m.level_req ?? 1)}</span>`;
    }

    return html`
          <button class="mcard mcard--${st}${sel ? ' is-active' : ''}" type="button" data-boot data-select
                  data-value="${String(m.slug)}" data-status="${st}" data-type="${String(m.type ?? '')}"
                  data-origin="${String(m.origin ?? 'hunter_command')}" data-requires="${temRq && !rq.met ? '1' : '0'}"
                  aria-pressed="${sel ? 'true' : 'false'}">
            <span class="mcard__media">
              <img class="mcard__img" src="${media(String(m.image))}" alt="" loading="lazy" decoding="async">
              <span class="mcard__shade" aria-hidden="true"></span>
              <span class="mcard__type mcard__type--${String(m.type ?? 'contrato')}">${String(m.type_label ?? '')}</span>
              ${st === 'locked' ? html`<span class="mcard__lock">${icon('lock')}</span>` : ''}
              ${st === 'done' ? html`<span class="mcard__done">${icon('check_circle', 'fill')}</span>` : ''}
            </span>

            <span class="mcard__body">
              <span class="mcard__top">
                <strong class="mcard__title">${String(m.title)}</strong>
                <span class="mcard__status mcard__status--${st}"><i aria-hidden="true"></i>${String(m.status_label ?? '')}</span>
              </span>
              <span class="mcard__sub">
                ${badge({ kind: 'origin', value: String(m.origin ?? 'hunter_command'), class: 'mcard__origin' })}
                <span class="mcard__giver">${String(m.faction)} <i aria-hidden="true">•</i> ${String(m.region)}</span>
              </span>

              <span class="mcard__meta">
                <span class="mmeta">
                  <span class="mmeta__label">${String(labels.difficulty ?? 'DIFICULDADE')}</span>
                  <span class="mmeta__val">${pips(int(m.difficulty_level ?? 1))}${String(m.difficulty)}</span>
                </span>
                <span class="mmeta">
                  <span class="mmeta__label">${String(labels.duration ?? 'DURAÇÃO')}</span>
                  <span class="mmeta__val">${icon('schedule', 'mmeta__ic')}${String(m.duration)}</span>
                </span>
                <span class="mmeta">
                  <span class="mmeta__label">${String(labels.players ?? 'ESQUADRÃO')}</span>
                  <span class="mmeta__val">${icon('group', 'mmeta__ic')}<span class="num">${squad(m)}</span></span>
                </span>
              </span>
              ${temRq
                ? html`<span class="mcard__req${rq.met ? ' is-met' : ''}">
                ${badge({ kind: 'requirement', value: { faction: String(rq.faction ?? ''), tier: String(rq.tier ?? '') }, label: String(rq.label ?? '') })}
                ${rq.met ? html`<small class="mcard__req-ok">${icon('check', 'mcard__req-ic')}ATENDIDO</small>` : ''}
              </span>`
                : ''}

              <span class="mcard__foot">
                <span class="mrewards">
                  ${join(
                    rw.map(
                      (r) => html`<span class="mrw mrw--${String(r.type ?? 'xp')}">
                    <span class="mrw__ring">${anel(r, 'mrw')}</span>
                    <span class="mrw__val num">${String(r.text)}</span>
                  </span>
                  `,
                    ),
                  )}
                  ${temIt
                    ? html`<span class="mrw mrw--item" data-tip="${String(it.name)} — ${String(it.desc)}">
                    <img class="mrw__img" src="${media(String(it.image))}" alt="" loading="lazy" decoding="async">
                  </span>`
                    : ''}
                </span>

                <span class="mcard__state">
                  ${estado}
                </span>
              </span>
            </span>
            <span class="mcard__edge" aria-hidden="true"></span>
          </button>`;
  });

  const detalhes = items.map((m) => {
    const st = String(m.status ?? 'available');
    const sel = String(m.slug) === selected;
    const pg: Dict = m.progress ?? {};
    const temPg = Object.keys(pg).length > 0;
    const it: Dict = m.reward_item ?? {};
    const temIt = Object.keys(it).length > 0;
    const rq: Dict = m.requirement && typeof m.requirement === 'object' ? m.requirement : {};
    const temRq = Object.keys(rq).length > 0;
    const rewards = (m.rewards ?? []) as Dict[];
    const linhas = (m.objective_lines ?? [String(m.objective ?? '')]) as string[];
    const pv = temPg && int(pg.max) > 0 ? round1((int(pg.cur) / int(pg.max)) * 100) : '0';

    let nota: SafeHtml | string = '';
    if (st === 'active' && temPg) {
      nota = html`<div class="mission__track">
              <span class="mission__track-head">
                <span class="mission__track-label">${String(pg.label)}</span>
                <span class="mission__track-val num">${int(pg.cur)}<small>/${int(pg.max)}</small></span>
              </span>
              <div class="progress progress--anim" style="--v:${pv}%"><div class="progress__bar"></div></div>
            </div>`;
    } else if (st === 'locked' && m.unlock_hint) {
      nota = html`<div class="mission__note mission__note--lock">
              ${icon('lock', 'mission__note-ic')}
              <span><strong>${String(labels.locked ?? 'DESBLOQUEIO')}</strong><small>${String(m.unlock_hint)}</small></span>
            </div>`;
    } else if (st === 'done') {
      nota = html`<div class="mission__note mission__note--done">
              ${icon('check_circle', 'fill mission__note-ic')}
              <span><strong>CONTRATO CONCLUÍDO</strong><small>Finalizado em <span class="num">${String(m.completed_at ?? '')}</span>.</small></span>
            </div>`;
    } else if (m.expires_in) {
      nota = html`<div class="mission__note mission__note--time">
              ${icon('schedule', 'mission__note-ic')}
              <span><strong>${String(labels.expires ?? 'EXPIRA EM')}</strong><small class="num" data-countdown="${int(m.expires_in)}">${hms(int(m.expires_in))}</small></span>
            </div>`;
    }

    const mapLabel = String(labels.map ?? 'VER NO MAPA');
    return html`
      <div class="mdetail${sel ? ' is-shown' : ''}" data-detail="${String(m.slug)}"${sel ? '' : raw(' hidden')}>

        <section class="panel mission">
          <header class="panel__head">
            <h2 class="panel__title">${String(d.detail_title ?? 'MISSÃO SELECIONADA')}</h2>
            <span class="panel__meta mission__tag mission__tag--${st}">${String(m.status_label ?? '')}</span>
          </header>
          <div class="panel__body">

            <a class="mission__media" href="${url('mapa')}" data-tip="${mapLabel}">
              <img class="mission__img" src="${media(String(m.image))}" alt="${String(m.title)}" loading="lazy" decoding="async">
              <span class="mission__shade" aria-hidden="true"></span>
              <span class="mission__head">
                <strong class="mission__title">${String(m.title)}</strong>
                <span class="mission__sub">
                  ${badge({ kind: 'origin', value: String(m.origin ?? 'hunter_command'), class: 'mission__origin' })}
                  <span>${String(m.faction)}</span>
                </span>
              </span>
              <span class="mission__stats">
                <span class="mstat">
                  <span class="mstat__label">${String(labels.difficulty ?? '')}</span>
                  <span class="mstat__val">${String(m.difficulty)}</span>
                </span>
                <span class="mstat">
                  <span class="mstat__label">${String(labels.duration ?? '')}</span>
                  <span class="mstat__val">${String(m.duration)}</span>
                </span>
                <span class="mstat">
                  <span class="mstat__label">${String(labels.players ?? 'ESQUADRÃO')}</span>
                  <span class="mstat__val num">${squad(m)}</span>
                </span>
              </span>
            </a>

            <h3 class="mission__section">${String(labels.objective ?? 'OBJETIVO')}</h3>
            <p class="mission__objective">
              ${join(linhas.map((line, i) => html`${i ? raw('<br>') : ''}${String(line)}
              `))}
            </p>

            ${nota}

            ${temRq
              ? html`<div class="mission__note mission__note--req${rq.met ? ' is-met' : ''}">
              ${icon(rq.met ? 'verified' : 'lock', 'mission__note-ic')}
              <span>
                <strong>${String(labels.requires ?? 'REQUISITO')}</strong>
                <small>${String(rq.label ?? '')}${rq.met ? ' · ATENDIDO' : rq.hint ? ' · ' + String(rq.hint) : ''}</small>
              </span>
            </div>`
              : ''}

            <div class="mission__facts">
              <span class="mfact">
                <span class="mfact__label">${String(labels.region ?? 'REGIÃO')}</span>
                <span class="mfact__val">${icon('map', 'mfact__ic')}${String(m.region)}</span>
              </span>
              <span class="mfact">
                <span class="mfact__label">${String(labels.type ?? 'TIPO')}</span>
                <span class="mfact__val mfact__val--${String(m.type ?? '')}">${String(m.type_label ?? '')}</span>
              </span>
              <span class="mfact">
                <span class="mfact__label">${String(labels.level ?? 'NÍVEL REC.')}</span>
                <span class="mfact__val num">${int(m.level_req ?? 1)}</span>
              </span>
              <span class="mfact">
                <span class="mfact__label">${String(labels.origin ?? 'ORIGEM')}</span>
                <span class="mfact__val mfact__val--origin-${String(m.origin ?? 'hunter_command')}">${String(m.origin_label ?? 'HUNTER COMMAND')}</span>
              </span>
              <span class="mfact">
                <span class="mfact__label">${String(labels.human ?? 'ATIVIDADE HUMANA')}</span>
                <span class="mfact__val">${icon('radar', 'mfact__ic')}${String(m.human_activity ?? 'PROVÁVEL').toUpperCase()}</span>
              </span>
            </div>

            <hr class="divider mission__rule">

            <h3 class="mission__section">${String(labels.rewards ?? 'RECOMPENSAS')}</h3>
            <div class="rewards rewards--${rewards.length}">
              ${join(
                rewards.map(
                  (r) => html`<span class="reward reward--${String(r.type ?? 'xp')}">
                <span class="reward__ring">${anel(r, 'reward')}</span>
                <span class="reward__val num">${String(r.text)}</span>
              </span>
              `,
                ),
              )}
              ${temIt
                ? html`<span class="reward-item">
                <img class="reward-item__img" src="${media(String(it.image))}" alt="" loading="lazy" decoding="async">
              </span>
              <span class="reward-item__text">
                <strong class="reward-item__name">${String(it.name)}</strong>
                <small class="reward-item__desc">${String(it.desc)}</small>
              </span>`
                : ''}
            </div>

          </div>
        </section>

        <div class="mission-cta">
          ${m.cta_href
            ? html`<a class="btn btn--primary accept-btn" href="${url(String(m.cta_href))}">
            ${icon('check_circle', 'fill accept-btn__ic')}<span class="accept-btn__label">${String(m.cta ?? d.cta ?? 'ACEITAR CONTRATO')}</span>
          </a>`
            : html`<span class="btn accept-btn accept-btn--off is-disabled" aria-disabled="true">
            ${icon(st === 'done' ? 'check_circle' : 'lock', 'accept-btn__ic')}<span class="accept-btn__label">${String(m.cta ?? '')}</span>
          </span>`}
          ${temRq && !rq.met && String(m.cta_href ?? '') !== 'faccoes'
            ? html`<a class="btn accept-alt" href="${url('faccoes')}">${icon('handshake', 'accept-alt__ic')}<span>VER CONTRATO DA FACÇÃO</span></a>`
            : ''}
          <a class="btn accept-map" href="${url('mapa')}" aria-label="${mapLabel}" data-tip="${mapLabel}">
            ${icon('map')}
          </a>
        </div>

      </div>`;
  });

  return html`<div class="missions">

  <!-- ====================== CABEÇALHO ====================== -->
  <header class="missions-head" data-boot>
    <div class="missions-head__id">
      <h1 class="page-title">${String(d.title ?? 'MISSÕES')}<small>${String(d.title_tag ?? '')}</small></h1>
      <p class="page-subtitle">${String(d.subtitle ?? '')}</p>
    </div>
    <div class="missions-head__meta">
      ${join(
        ((d.summary ?? []) as Dict[]).map(
          (x) => html`<span class="hstat">
        <span class="hstat__val num" data-count="${int(x.value)}" data-dur="1100">${num(int(x.value))}</span>
        <span class="hstat__label">${String(x.label)}</span>
      </span>
      `,
        ),
      )}<a class="hstat hstat--link" href="${url('desafios')}">
        <span class="hstat__val hstat__val--ic">${icon('flag')}</span>
        <span class="hstat__label">DESAFIOS</span>
      </a>
    </div>
  </header>

  <div class="missions-grid">

    <!-- ====================== COLUNA ESQUERDA ====================== -->
    <aside class="missions__left">

      <section class="panel filters-panel">
        <header class="panel__head">
          <h2 class="panel__title">${String(d.filters_title ?? 'FILTROS')}</h2>
        </header>
        <div class="panel__body panel__body--tight">
          <div class="filters" data-select-group data-filters>
            ${join(
              filters.map(
                (f, k) => html`<button class="row filter-row${k === 0 ? ' is-active' : ''}" type="button" data-select data-value="${String(f.slug)}" aria-pressed="${k === 0 ? 'true' : 'false'}">
              ${icon(String(f.icon ?? 'flag'), 'row__icon filter-row__ic')}
              <span class="row__text"><strong>${String(f.label)}</strong></span>
              <span class="row__meta num filter-row__count">${int(f.count ?? 0)}</span>
            </button>
            `,
              ),
            )}
          </div>
        </div>
      </section>

      <section class="panel types-panel">
        <header class="panel__head">
          <h2 class="panel__title">${String(d.types_title ?? 'TIPO DE CONTRATO')}</h2>
        </header>
        <div class="panel__body panel__body--tight">
          <div class="types" data-select-group="multi" data-types>
            ${join(
              types.map(
                (t) => html`<button class="chip chip--${String(t.color ?? 'lime')} type-chip is-active" type="button" data-select data-value="${String(t.slug)}" aria-pressed="true">
              <i class="type-chip__dot" aria-hidden="true"></i>${String(t.label)}
            </button>
            `,
              ),
            )}
          </div>
        </div>
      </section>

      <section class="panel reset-panel">
        <div class="panel__body">
          <span class="reset__label">${icon('schedule', 'reset__ic')}${String(reset.title ?? '')}</span>
          <span class="reset__clock num" data-countdown="${int(reset.seconds ?? 0)}">${hms(int(reset.seconds ?? 0))}</span>
          <small class="reset__note">${String(reset.note ?? '')}</small>
        </div>
      </section>

      <section class="panel board-panel">
        <header class="panel__head">
          <h2 class="panel__title">${String(board.title ?? '')}</h2>
        </header>
        <div class="panel__body">
          <div class="board__row">
            <span class="board__label">${String(board.label ?? '')}</span>
            <span class="board__val num">${int(board.done ?? 0)}<small>/${int(board.total ?? 0)}</small></span>
          </div>
          <div class="progress progress--anim board__bar" style="--v:${bv}%"><div class="progress__bar"></div></div>
          <div class="board__row board__row--sub">
            <span class="board__label">${icon('local_fire_department', 'board__fire')}${String(board.streak?.label ?? '')}</span>
            <span class="board__streak">${String(board.streak?.value ?? '')}</span>
          </div>
        </div>
      </section>

    </aside>

    <!-- ====================== COLUNA CENTRAL ====================== -->
    <section class="panel missions__list">
      <header class="panel__head">
        <h2 class="panel__title">${String(d.list_title ?? 'QUADRO DE CONTRATOS')}</h2>
        <span class="panel__meta"><span class="num" data-list-count>${items.length}</span> <span data-list-word>${items.length === 1 ? 'CONTRATO' : 'CONTRATOS'}</span></span>
      </header>
      <div class="panel__body panel__body--list">
        <div class="mlist scroll-y" data-select-group data-list>${join(cards)}

          <p class="mlist__empty" hidden data-empty>
            <strong>${String(d.empty?.title ?? '')}</strong>
            <small>${String(d.empty?.desc ?? '')}</small>
          </p>
        </div>
      </div>
    </section>

    <!-- ====================== COLUNA DIREITA ====================== -->
    <aside class="missions__right">${join(detalhes)}
    </aside>

  </div>
</div>
`;
}
