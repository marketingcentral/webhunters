/**
 * Tela MAPA DO MUNDO — app/Views/pages/mapa.php (referência 08-mapas.png, PRD v3 §23).
 */
import * as GR from '../../core/game-rules';
import { icon, mapGlyph, media, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { badge, infestation } from '../components';
import { mapStage } from '../partials/map-stage';

/** Glifos das regiões (coluna esquerda). */
const REGION_GLYPHS: Record<string, string> = {
  pine: '<path d="M12 1.6 17.4 9h-2.2l4 5.6h-2.5L21 20.2h-7.6V23h-2.8v-2.8H3l4.3-5.6H4.8L8.8 9H6.6z"/>',
  swamp: '<path d="M11.2 9.6h1.6V23h-1.6z"/><path d="M12 1.2c1.6 1.9 2.3 4 2 6.4-1.6-.6-2.5-2.7-2-6.4ZM4.4 4.2c2.4.4 4.1 1.7 5.1 3.9-1.7.5-3.6-.9-5.1-3.9ZM19.6 4.2c-1.5 3-3.4 4.4-5.1 3.9 1-2.2 2.7-3.5 5.1-3.9ZM2.6 10.4c2.5-.3 4.4.4 5.8 2.2-1.8 1-4 .3-5.8-2.2ZM21.4 10.4c-1.8 2.5-4 3.2-5.8 2.2 1.4-1.8 3.3-2.5 5.8-2.2Z"/>',
  mountain: '<path d="m8.6 4.4 5 8.2-2.1 2.6-1.8-1.9-2.4 2.9-2.1-1.6L1.2 20h11.2z"/><path d="m16.4 8.2 6.4 11.8h-8.3l-2.1-3.6 2.2-2.9 1.5 1.7 2-2.4-1.9-1.5z" opacity=".85"/>',
  cactus: '<path fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" d="M12 20.2V4.2"/><path fill="none" stroke="currentColor" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round" d="M12 13.2H7.6V7.4M12 11h4.4V5.4"/><path d="M5.4 20.2h13.2v2.4H5.4z"/>',
  factory: '<path d="M2.4 21.6V9.4l5.6 3.4V9.4l5.6 3.4V6.2h1.6l.6-4h2.4l.6 4h1.6v15.4zM5.4 15.6v3.2h2.8v-3.2zm5.2 0v3.2h2.8v-3.2zm5.2 0v3.2h2.8v-3.2z"/>',
};

export function mapa(s: Scope): SafeHtml {
  const d = s.data;
  const regions = (d.regions ?? []) as Dict[];
  const bases = (d.bases ?? []) as Dict[];
  const sites = (d.faction_sites ?? []) as Dict[];
  const labels: Dict = d.labels ?? {};
  const survivor = Boolean(d.survivor);
  const region: Dict = d.region ?? {};
  const rstate: Dict = region.state && typeof region.state === 'object' ? region.state : {};
  const markers = (d.markers ?? []) as Dict[];
  const legend = (d.legend ?? []) as Dict[];
  const toggle: Dict = d.legend_toggle ?? {};
  const ctaR: Dict = d.cta_route ?? {};
  const ctaM: Dict = d.cta_mark ?? {};
  const event: Dict | null = region.event && typeof region.event === 'object' ? region.event : null;
  const temEstado = Object.keys(rstate).length > 0;

  return html`<div class="mapa">

  <!-- ====================== COLUNA ESQUERDA ====================== -->
  <aside class="mapa__left">
    <section class="panel mp" data-boot>

      <h1 class="mp__title">${String(d.title ?? 'MAPA DO MUNDO')}</h1>

      <!-- ---- REGIÕES ---- -->
      <div class="mgroup mgroup--regions">
        <h2 class="mgroup__title">${String(d.regions_title ?? 'REGIÕES')}</h2>
        <div class="mgroup__list" data-stagger>
${join(
  regions.map((r) => {
    const ativo = String(r.slug) === String(d.active_region ?? '');
    return html`          <a class="row rrow${ativo ? ' is-active' : ''}${r.locked ? ' is-locked' : ''}"
             href="${url('mapa/' + String(r.slug))}"
             ${ativo ? raw(' aria-current="true"') : ''}>
            <span class="rrow__icon">${mapGlyph(String(r.icon ?? 'pine'), '', REGION_GLYPHS)}</span>
            <span class="row__text">
              <strong>${String(r.name)}</strong>
              <small>${String(d.level_label ?? 'NÍVEL RECOMENDADO:')} <span class="num">${String(r.level)}</span></small>
            </span>
${r.locked ? html`            <span class="row__lock" title="${String(d.locked_note ?? 'REGIÃO TRAVADA')}">${icon('lock', 'fill')}</span>
` : ''}          </a>
`;
  }),
)}        </div>
      </div>

      <!-- ---- CAMADAS (PRD v3 §23.9): a legenda É o filtro ---- -->
      <div class="mgroup mgroup--legend" id="mapa-legend">
        <h2 class="mgroup__title">${String(d.legend_title ?? 'CAMADAS')}</h2>
        <div class="mlegend" data-map-filters role="group" aria-label="${String(d.legend_title ?? 'CAMADAS')}">
          <button class="mlegend__i mlayer mlayer--all is-active" type="button" data-value="todos" aria-pressed="true">
            <span class="mlegend__ic mlegend__ic--lime">${icon('done_all')}</span>
            <span class="mlegend__label">${String(d.legend_all ?? 'TODOS')}</span>
          </button>
${join(
  legend.map(
    (l) => html`          <button class="mlegend__i mlayer" type="button" data-value="${String(l.layer ?? '')}" aria-pressed="false">
            <span class="mlegend__ic mlegend__ic--${String(l.color ?? 'lime')}">${mapGlyph(String(l.glyph))}</span>
            <span class="mlegend__label">${String(l.label)}</span>
          </button>
`,
  ),
)}        </div>
      </div>

      <button class="mp__toggle" type="button" data-legend-toggle
              data-label-on="${String(toggle.label ?? 'OCULTAR LEGENDA')}"
              data-label-off="${String(toggle.label_off ?? 'MOSTRAR LEGENDA')}"
              aria-controls="mapa-legend" aria-expanded="true">
        <span class="key">${String(toggle.key ?? 'X')}</span>
        <span class="mp__toggle-label">${String(toggle.label ?? 'OCULTAR LEGENDA')}</span>
      </button>

    </section>
  </aside>

  <!-- ====================== MAPA ====================== -->
  <div class="mapa__map">
${mapStage(s, { markers, opacidade_form: true })}  </div>

  <!-- ====================== COLUNA DIREITA ====================== -->
  <aside class="mapa__right">
    <section class="panel rp" data-boot>

      <h2 class="rp__title">${String(region.name ?? '')}</h2>

      <span class="rp__media">
        <img class="rp__img" src="${media(String(region.image ?? 'regions/region-floresta-verde.jpg'))}"
             alt="${String(region.name ?? '')}" decoding="async">
        <span class="rp__shade" aria-hidden="true"></span>
      </span>

      <p class="rp__desc">${String(region.desc ?? '')}</p>

      <!-- INFORMAÇÕES -->
      <section class="rsec">
        <h3 class="rsec__title">${String(d.info_title ?? 'INFORMAÇÕES')}</h3>
        <ul class="rinfo">
${join(
  ((region.info ?? []) as Dict[]).map((it) => {
    const isNum = /^[0-9]/.test(String(it.value));
    return html`          <li class="rinfo__i">
            <span class="rinfo__k">${String(it.label)}</span>
            <span class="rinfo__v${isNum ? ' num' : ''}${it.cls ? ' ' + String(it.cls) : ''}"${it.tip ? html` data-tip="${String(it.tip)}"` : ''}>${String(it.value)}${it.icon ? icon(String(it.icon), 'rinfo__ic fill') : ''}</span>
          </li>
`;
  }),
)}        </ul>
      </section>

${temEstado ? html`      <!-- ESTADO DO SETOR (PRD §23.2–§23.3) -->
      <section class="rsec rsec--state">
        <h3 class="rsec__title">${String(d.state_title ?? 'ESTADO DO SETOR')}</h3>
        <div class="rstate">
          ${infestation({ value: Number(rstate.infestation ?? 0), label: String(labels.infestation ?? 'INFESTAÇÃO VEYRA'), class: 'rstate__infest' })}
          <div class="rstate__grid">
            <div class="rstate__row">
              <span class="rstate__k">${String(labels.veyra ?? 'ATIVIDADE VEYRA')}</span>
              ${badge({ kind: 'activity', value: String(rstate.veyra_activity ?? 'MODERADA') })}
            </div>
            <div class="rstate__row">
              <span class="rstate__k">${String(labels.human ?? 'ATIVIDADE HUMANA')}</span>
              ${badge({ kind: 'activity', value: String(rstate.human_activity ?? 'MODERADA') })}
            </div>
          </div>
        </div>
      </section>
` : ''}
${bases.length || sites.length ? html`      <!-- BASES HUNTER (§23.5) + INSTALAÇÕES DE FACÇÃO (§23.6) -->
      <section class="rsec rsec--bases">
        <h3 class="rsec__title">${String(d.bases_title ?? 'BASES E INSTALAÇÕES')}</h3>
        <ul class="rbases" data-tip="${String(d.bases_note ?? '')}">
${join(bases.map((b) => html`          <li class="rbase rbase--${String(b.color)} rbase--hunter">
            <span class="rbase__ic">${mapGlyph('gov-base')}</span>
            <span class="rbase__n">${String(b.name)}</span>
            ${badge({ kind: 'base', value: String(b.status), class: 'rbase__st' })}
          </li>
`))}${join(sites.map((st) => html`          <li class="rbase rbase--${String(st.color)} rbase--${String(st.faction)}"${st.tip !== '' ? html` data-tip="${String(st.tip)}"` : ''}>
            <span class="rbase__ic">${mapGlyph(String(st.faction))}</span>
            <span class="rbase__n">${String(st.name)}</span>
            <span class="chip chip--${String(st.color)} badge rbase__st">${String(st.status)}</span>
          </li>
`))}        </ul>
      </section>
` : ''}
      <!-- EXTRAÇÕES DISPONÍVEIS -->
      <section class="rsec rsec--ext${survivor ? ' rsec--blocked' : ''}">
        <h3 class="rsec__title">${String(d.extractions_title ?? 'EXTRAÇÕES DISPONÍVEIS')}</h3>
        <ul class="rext">
${join(
  ((region.extractions ?? []) as Dict[]).map((x) => {
    const st = String(x.state ?? 'active');
    const tc = String(x.type_color ?? '');
    const ic = st === 'blocked' ? icon('lock') : st === 'active' ? mapGlyph('extraction') : st === 'conditional' ? icon('adjust') : icon('close');
    return html`          <li class="rext__i rext__i--${st}${tc !== '' ? ' rext__i--t-' + tc : ''}"${x.tip ? html` data-tip="${String(x.tip)}"` : ''}>
            <span class="rext__ic">${ic}</span>
            <span class="rext__n">${String(x.name)}${x.type_label ? html`<small class="rext__t">${String(x.type_label)}</small>` : ''}</span>
            <span class="rext__s">${String(x.status)}</span>
            <span class="rext__d" aria-hidden="true"></span>
          </li>
`;
  }),
)}        </ul>
${survivor ? html`        <p class="rext__warn">${icon('block', 'rext__warn-ic')}${GR.SURVIVOR_EXTRACTION_TIP}</p>
` : ''}      </section>

      <!-- EVENTO ATIVO -->
      <section class="rsec rsec--event">
        <h3 class="rsec__title">${String(d.event_title ?? 'EVENTO ATIVO')}</h3>
${event ? html`        <div class="revent">
          <span class="revent__ic">${mapGlyph('event')}</span>
          <span class="revent__name">${String(event.name)}</span>
          <span class="revent__timer">
            <small class="revent__lab">${String(event.ends_label ?? 'TERMINA EM')}</small>
            <strong class="revent__val num" data-countdown="${String(event.ends_in)}">${String(event.ends_in)}</strong>
          </span>
        </div>
` : raw('        <p class="revent revent--none muted">SEM EVENTO ATIVO NESTA REGIÃO.</p>\n')}      </section>

      <div class="rp__cta">
        <a class="btn btn--primary btn--block rp__route" href="${url(String(ctaR.href ?? 'jogar'))}" data-route-cta>
          <span class="key key--btn">${String(ctaR.key ?? 'F')}</span>
          <span class="btn__label">${String(ctaR.label ?? 'DEFINIR ROTA')}</span>
        </a>
        <button class="btn btn--outline btn--block rp__mark" type="button" data-mark-cta
                data-label="${String(ctaM.label ?? 'MARCAR PONTO')}"
                data-label-on="${String(ctaM.label_on ?? 'PONTO MARCADO')}"
                aria-pressed="false">
          ${icon(String(ctaM.icon ?? 'star'), 'fill')}
          <span class="btn__label">${String(ctaM.label ?? 'MARCAR PONTO')}</span>
        </button>
      </div>

    </section>
  </aside>

</div>
`;
}
