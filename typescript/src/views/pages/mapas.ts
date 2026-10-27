/**
 * Tela MAPAS (/mapas) — app/Views/pages/mapas.php: grade das regiões + painel de detalhe.
 * Os brasões dos modos são símbolos SVG locais (MAPAS_DEFS, copiados do PHP). Ids prefixados mp-.
 */
import { icon, media, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { btn } from '../components/btn';

const MAPAS_DEFS = `<svg class="mapas-defs" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <!-- caveira (MATA-MATA EM EQUIPE) -->
    <symbol id="mp-skull" viewBox="0 0 24 24">
      <path fill="currentColor" fill-rule="evenodd" d="M12 1.9c-4.6 0-8.2 3.4-8.2 7.8 0 2.7 1.1 4.8 2.9 6.2v1.9c0 .7.6 1.3 1.3 1.3h8c.7 0 1.3-.6 1.3-1.3v-1.9c1.8-1.4 2.9-3.5 2.9-6.2 0-4.4-3.6-7.8-8.2-7.8ZM8.2 7.8a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6Zm7.6 0a2.3 2.3 0 1 0 0 4.6 2.3 2.3 0 0 0 0-4.6ZM12 12.5l-1.2 2.3h2.4L12 12.5Zm-1.55 3.7h.9v3.2h-.9Zm2.2 0h.9v3.2h-.9Z"/>
      <path fill="currentColor" d="M6.6 20.1h10.8v1.5H6.6z" opacity=".5"/>
    </symbol>
    <!-- escudo + estandarte (DOMINAÇÃO) -->
    <symbol id="mp-shield" viewBox="0 0 24 24">
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 1.9 21 5v6.8c0 5-3.7 9.3-9 11-5.3-1.7-9-6-9-11V5Z"/>
      <path fill="currentColor" d="M11.1 6.2h1.7v11.4h-1.7z"/>
      <path fill="currentColor" d="M13.5 6.7h4.6l-1.5 2.2 1.5 2.2h-4.6z"/>
    </symbol>
    <!-- retículo (ZONA DE CONFLITO) -->
    <symbol id="mp-target" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="7.4" fill="none" stroke="currentColor" stroke-width="1.8"/>
      <circle cx="12" cy="12" r="2.4" fill="currentColor"/>
      <path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M12 1.8v3.4M12 18.8v3.4M1.8 12h3.4M18.8 12h3.4"/>
    </symbol>
  </defs>
</svg>`;

const SYMS = ['mp-skull', 'mp-shield', 'mp-target'];
const pad2 = (n: number): string => String(n).padStart(2, '0');

export function mapas(s: Scope): SafeHtml {
  const d = s.data;
  const items = (Array.isArray(d.items) ? d.items : d.items && typeof d.items === 'object' ? Object.values(d.items) : []) as Dict[];
  const modes = (Array.isArray(d.modes) ? d.modes : d.modes && typeof d.modes === 'object' ? Object.values(d.modes) : []) as string[];
  const labels: Dict = d.labels && typeof d.labels === 'object' ? d.labels : {};
  const mIcons: Dict = d.mode_icons && typeof d.mode_icons === 'object' ? d.mode_icons : {};

  const sym = (mode: string): string => {
    const id = String(mIcons[mode] ?? '');
    return SYMS.includes(id) ? id : 'mp-skull';
  };
  /** Chave estável do modo para o filtro (sem acento, sem depender do texto). */
  const modeKey = (mode: string): string => {
    const i = modes.indexOf(mode);
    return i === -1 ? 'x' : 'm' + String(i);
  };

  // mapa selecionado inicialmente (primeiro com selected, senão o primeiro)
  let sel = 0;
  for (let i = 0; i < items.length; i++) {
    if (items[i]!.selected) {
      sel = i;
      break;
    }
  }
  const cur: Dict = items[sel] ?? {};
  const total = items.length;

  // contagem por modo (chips de filtro)
  const counts: Record<string, number> = {};
  for (const m of items) {
    const k = String(m.mode ?? '');
    counts[k] = (counts[k] ?? 0) + 1;
  }
  const lbl = (k: string, def: string): string => String(labels[k] ?? def);

  const chipModo = (mode: string): SafeHtml => html`          <button class="chip mode-chip" type="button" data-select data-filter="${modeKey(mode)}" aria-pressed="false">
            <svg class="mp-hex mode-chip__ic" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
              <polygon class="mp-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
              <use href="#${sym(mode)}" x="11" y="15.5" width="30" height="30"></use>
            </svg>
            ${mode}<span class="mode-chip__n num">${String(counts[mode] ?? 0)}</span>
          </button>
          `;

  const card = (mp: Dict, i: number): SafeHtml => {
    const mode = String(mp.mode ?? '');
    const isSel = i === sel;
    return html`          <article class="card map-card${isSel ? ' is-selected' : ''}"
                   data-value="${String(mp.slug ?? '')}"
                   data-index="${String(i + 1)}"
                   data-mode-key="${modeKey(mode)}"
                   data-name="${String(mp.name ?? '')}"
                   data-mode="${mode}"
                   data-players="${String(mp.players ?? '')}"
                   data-img="${media(String(mp.image ?? ''))}"
                   data-sym="${sym(mode)}"
                   data-size="${String(mp.size ?? '')}"
                   data-biome="${String(mp.biome ?? '')}"
                   data-weather="${String(mp.weather ?? '')}"
                   data-best="${String(mp.best ?? '')}"
                   data-best-by="${String(mp.best_by ?? '')}"
                   data-your-best="${String(mp.your_best ?? '')}"
                   data-desc="${String(mp.desc ?? '')}"
                   role="button" tabindex="0"
                   aria-pressed="${isSel ? 'true' : 'false'}"
                   aria-label="${String(mp.name ?? '')} — ${mode}">
            <div class="card__media">
              <img src="${media(String(mp.image ?? ''))}" alt="${String(mp.name ?? '')}" decoding="async">
            </div>
            <div class="card__body card__body--row">
              <svg class="mp-hex map-card__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
                <polygon class="mp-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
                <use href="#${sym(mode)}" x="11" y="15.5" width="30" height="30"></use>
              </svg>
              <div class="grid min-0 map-card__lines">
                <h3 class="card__title">${String(mp.name ?? '')}</h3>
                <span class="card__sub">${mode}</span>
                <span class="map-card__foot">
                  <span class="card__meta">${String(mp.players ?? '')}</span>
                  <span class="map-card__time" title="${lbl('best', 'MELHOR TEMPO')}">${icon('schedule', 'map-card__time-ic')}<span class="num">${String(mp.best ?? '')}</span></span>
                </span>
              </div>
            </div>
            <span class="map-card__check" aria-hidden="true">${icon('check')}</span>
          </article>
`;
  };

  return html`${raw(MAPAS_DEFS)}

<div class="mapas">

  <header class="mapas-head" data-boot>
    <div class="mapas-head__id">
      <h1 class="page-title">${String(d.title ?? 'MAPAS')}<span class="page-title__tag">${String(d.tag ?? '')}</span></h1>
      <p class="page-subtitle">${String(d.intro ?? '')}</p>
    </div>
    <div class="rotation" aria-live="off">
      <span class="rotation__label">${String(d.rotation_label ?? 'ROTAÇÃO DE MAPAS EM')}</span>
      <span class="rotation__val num" data-countdown="${String(Number(d.rotation_secs ?? 0) | 0)}">--:--:--</span>
    </div>
  </header>

  <div class="layout-2 layout-2--r mapas-body">

    <!-- ===================== GRADE DE MAPAS ===================== -->
    <section class="panel mapas-grid panel--grow" data-boot>
      <header class="panel__head">
        <h2 class="panel__title">${String(d.grid_title ?? 'SELEÇÃO DE MAPAS')} <small class="num" data-map-count>${String(total)} / ${String(total)}</small></h2>
        <div class="mode-filters" data-select-group data-filters>
          <button class="chip mode-chip is-active" type="button" data-select data-filter="all" aria-pressed="true">
            ${String(d.filters_all ?? 'TODOS')}<span class="mode-chip__n num">${String(total)}</span>
          </button>
          ${join(modes.map((m) => chipModo(String(m))))}</div>
      </header>

      <div class="panel__body">
        <div class="map-grid" data-select-group data-map-grid>
${join(items.map(card))}        </div>
        <p class="map-grid__empty" data-map-empty hidden>${lbl('empty', 'NENHUM MAPA NESTE MODO')}</p>
      </div>
    </section>

    <!-- ===================== DETALHE ===================== -->
    <aside class="col mapas-side">
      <section class="panel panel--hot map-detail" data-boot data-detail>
        <header class="panel__head">
          <h2 class="panel__title">${String(d.detail_title ?? 'INFORMAÇÕES DO MAPA')}</h2>
          <span class="panel__meta num" data-detail-index>${pad2(sel + 1)} / ${pad2(total)}</span>
        </header>

        <div class="panel__body">
          <div class="detail__media" data-detail-media>
            <img class="detail__img" src="${media(String(cur.image ?? ''))}" alt="${String(cur.name ?? '')}" data-detail-img decoding="async">
            <span class="detail__shade" aria-hidden="true"></span>
            <span class="detail__scan" aria-hidden="true"></span>
            <span class="chip chip--lime detail__players num" data-detail-players>${String(cur.players ?? '')}</span>
            <span class="detail__over">
              <svg class="mp-hex detail__hex" viewBox="0 0 52 60" aria-hidden="true" focusable="false">
                <polygon class="mp-hex__frame" points="26,1.4 50.6,15.7 50.6,44.3 26,58.6 1.4,44.3 1.4,15.7"/>
                <use href="#${sym(String(cur.mode ?? ''))}" x="11" y="15.5" width="30" height="30" data-detail-use></use>
              </svg>
              <span class="detail__id">
                <strong class="detail__name" data-detail-name>${String(cur.name ?? '')}</strong>
                <span class="detail__mode" data-detail-mode>${String(cur.mode ?? '')}</span>
              </span>
            </span>
          </div>

          <p class="detail__desc" data-detail-desc>${String(cur.desc ?? '')}</p>

          <div class="detail__stats">
            <div class="dstat">
              <span class="dstat__label">${lbl('size', 'TAMANHO')}</span>
              <span class="dstat__val" data-detail-size>${String(cur.size ?? '')}</span>
            </div>
            <div class="dstat">
              <span class="dstat__label">${lbl('biome', 'BIOMA')}</span>
              <span class="dstat__val" data-detail-biome>${String(cur.biome ?? '')}</span>
            </div>
            <div class="dstat">
              <span class="dstat__label">${lbl('weather', 'CLIMA')}</span>
              <span class="dstat__val" data-detail-weather>${String(cur.weather ?? '')}</span>
            </div>
            <div class="dstat dstat--hot">
              <span class="dstat__label">${lbl('best', 'MELHOR TEMPO')}</span>
              <span class="dstat__val num lime" data-detail-best>${String(cur.best ?? '')}</span>
            </div>
          </div>

          <hr class="divider detail__rule">

          <div class="record">
            <span class="record__icon">${icon('military_tech', 'fill')}</span>
            <span class="record__text">
              <span class="record__label">${lbl('record', 'RECORDE DO SERVIDOR')}</span>
              <strong class="record__by" data-detail-best-by>${String(cur.best_by ?? '')}</strong>
            </span>
            <span class="record__yours">
              <span class="record__label">${lbl('yours', 'SEU MELHOR')}</span>
              <strong class="record__val num" data-detail-your>${String(cur.your_best ?? '')}</strong>
            </span>
          </div>
        </div>

        <footer class="panel__foot detail__foot">
          ${btn({ label: String(d.cta ?? 'JOGAR NESTE MAPA'), href: url(String(d.cta_href ?? 'jogar')), variant: 'primary', block: true, icon: 'play_arrow', class: 'detail__cta' })}          ${btn({ label: String(d.alt_cta ?? 'MAPA TÁTICO'), href: url(String(d.alt_href ?? 'mapa')), variant: 'outline', block: true, icon: 'map', class: 'detail__alt' })}        </footer>
      </section>
    </aside>

  </div>
</div>
`;
}
