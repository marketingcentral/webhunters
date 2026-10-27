/**
 * PALCO DO MAPA — app/Views/partials/map-stage.php.
 * Compartilhado pela tela /mapa e pela landing (/): mesma arte, mesmos pontos, mesmo CSS e
 * o mesmo assets/js/three/map3d.js. Um parcial só faz os dois mapas não divergirem.
 */
import { csrfField, dataMock, icon, mapPointIcon, media, url } from '../../core/helpers';
import { html, join, raw, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

const rnd2 = (v: number) => Math.round(v * 100) / 100;

const ROSA = raw(`<svg class="map3d__rose" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
          <defs>
            <linearGradient id="mapRoseA" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="#f2f6ef"/><stop offset="1" stop-color="#8d968a"/>
            </linearGradient>
            <linearGradient id="mapRoseB" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#4d554b"/><stop offset="1" stop-color="#98a194"/>
            </linearGradient>
          </defs>
          <circle cx="50" cy="50" r="46" fill="none" stroke="rgba(232,237,230,.42)" stroke-width="1.1"/>
          <circle cx="50" cy="50" r="37" fill="none" stroke="rgba(232,237,230,.26)" stroke-width="1"/>
          <g stroke="rgba(232,237,230,.62)" stroke-width="1.5">
            <path d="M50 4.4v7.4M50 95.6v-7.4M4.4 50h7.4M95.6 50h-7.4"/>
          </g>
          <g stroke="rgba(232,237,230,.34)" stroke-width="1.3">
            <path d="m21.5 21.5 4.6 4.6M78.5 21.5l-4.6 4.6M21.5 78.5l4.6-4.6M78.5 78.5l-4.6-4.6"/>
          </g>
          <g fill="url(#mapRoseA)">
            <path d="M50 12 53.18 46.82 50 50Z"/>
            <path d="M50 88 46.82 53.18 50 50Z"/>
            <path d="M88 50 53.18 53.18 50 50Z"/>
            <path d="M12 50 46.82 46.82 50 50Z"/>
            <path d="M65.6 34.4 54.5 50 50 50Z"/>
            <path d="M65.6 65.6 50 54.5 50 50Z"/>
            <path d="M34.4 65.6 45.5 50 50 50Z"/>
            <path d="M34.4 34.4 50 45.5 50 50Z"/>
          </g>
          <g fill="url(#mapRoseB)">
            <path d="M50 12 46.82 46.82 50 50Z"/>
            <path d="M50 88 53.18 53.18 50 50Z"/>
            <path d="M88 50 53.18 46.82 50 50Z"/>
            <path d="M12 50 46.82 53.18 50 50Z"/>
            <path d="M65.6 34.4 50 45.5 50 50Z"/>
            <path d="M65.6 65.6 54.5 50 50 50Z"/>
            <path d="M34.4 65.6 50 54.5 50 50Z"/>
            <path d="M34.4 34.4 45.5 50 50 50Z"/>
          </g>
        </svg>`);

function marcador(m: Dict, glyphs: Dict): SafeHtml {
  const lab = m.label ?? false;
  const g = glyphs[String(m.glyph)] !== undefined ? String(m.glyph) : 'event';
  const dica = (String(m.name) + (m.sub ? ' · ' + m.sub : '') + (m.tip ? ' — ' + m.tip : '')).trim();
  const cls =
    `mk mk--${m.color}` +
    (lab === 'right' ? ' mk--lab-right' : '') +
    (m.narrow ? ' mk--lab-narrow' : '') +
    (m.pulse ? ' mk--pulse' : '') +
    (m.blocked ? ' mk--blocked' : '');
  const rotulo = lab
    ? html`          <span class="mk__label">${String(m.name)}${
        m.tag
          ? html`<small class="mk__tag">${String(m.tag)}</small>`
          : m.sub
            ? html`<small class="mk__sub mk__sub--${String(m.sub_color ?? 'muted')}">${String(m.sub)}</small>`
            : ''
      }</span>`
    : html`          <span class="sr-only">${String(m.name)}${m.sub ? ' — ' + String(m.sub) : ''}</span>`;
  return html`        <div class="${cls}"
             data-marker="${String(m.id)}" data-x="${String(m.x)}" data-y="${String(m.y)}"
             data-kind="${String(m.kind ?? g)}" data-layer="${String(m.layer ?? 'eventos')}"${m.expires ? html` data-expires="${Number(m.expires) | 0}"` : ''} data-tip="${dica}"
             style="--x:${String(m.x)}%;--y:${String(m.y)}%">
          <span class="mk__glyph mk__glyph--${g}">${mapPointIcon(m, g)}</span>
${rotulo}
        </div>
`;
}

function formOpacidade(d: Dict): SafeHtml {
  const atual = Math.round(Number(d.map_opacity ?? 0.65) * 100);
  const padrao = Math.round(Number(d.map_opacity_padrao ?? 0.65) * 100);
  const min = Math.round(Number(d.map_opacity_min ?? 0.15) * 100);
  const propria = atual !== padrao;
  return html`        <form class="mopac" method="post" action="${url('mapa/opacidade')}" data-map-opacity
              data-padrao="${padrao}">
          ${csrfField()}
          <input type="hidden" name="regiao" value="${String(d.active_region ?? '')}">

          <span class="mopac__ic" aria-hidden="true">${icon('opacity')}</span>

          <label class="mopac__wrap">
            <span class="sr-only">Opacidade da arte do mapa</span>
            <input class="mopac__range" type="range" name="valor_pct"
                   min="${min}" max="100" step="1" value="${atual}"
                   data-map-opacity-range
                   aria-describedby="mopac-valor">
          </label>

          <span class="mopac__v num" id="mopac-valor" data-map-opacity-out>${atual}%</span>

          <input type="hidden" name="valor" value="${String(rnd2(Number(d.map_opacity ?? 0.65)))}" data-map-opacity-payload>

          <button class="mctrl mopac__apply" type="submit" data-map-opacity-apply
                  aria-label="Aplicar opacidade" title="Aplicar opacidade">${icon('check')}</button>

          <button class="mctrl mopac__reset${propria ? '' : ' is-hidden'}" type="submit"
                  name="reset" value="1" data-map-opacity-reset
                  aria-label="Voltar à opacidade padrão" title="Voltar à opacidade padrão">${icon('restart_alt')}</button>
        </form>
`;
}

export function mapStage(s: Scope, vars: { markers?: Dict[]; opacidade_form?: boolean } = {}): SafeHtml {
  const d = s.data;
  const markers = (vars.markers ?? d.markers ?? []) as Dict[];
  const glyphs = dataMock('map_glyphs');
  const risks = markers
    .filter((m) => m.risk && typeof m.risk === 'object')
    .map((m) => ({ ...m.risk, layer: String(m.layer ?? 'veyra') }));

  return html`    <div class="map3d" id="map3d" data-map data-region="${String(d.active_region ?? '')}"
         style="--map-art-opacity:${String(rnd2(Number(d.map_opacity ?? 0.65)))}">

      <img class="map3d__img" id="map3d-img" src="${media(String(d.map_image ?? 'bg/map-island.jpg'))}"
           alt="${String(d.map_alt ?? 'Mapa do mundo')}" width="941" height="1672" decoding="async">
      <canvas class="map3d__canvas" id="map3d-canvas" aria-hidden="true"></canvas>
      <span class="map3d__vignette" aria-hidden="true"></span>

      <div class="map3d__layer" id="map3d-layer">
${join(
  ((d.world_labels ?? []) as Dict[]).map(
    (wl, wi) => html`        <div class="wlabel" aria-hidden="true"
             style="--x:${String(wl.x)}%;--y:${String(wl.y)}%;--i:${wi}">
          <span class="wlabel__name">${String(wl.name)}</span>
          <span class="wlabel__tag">${String(wl.tag)}</span>
        </div>
`,
  ),
)}${join(
  risks.map(
    (rk, i) => html`        <span class="mrisk" aria-hidden="true"
              data-risk="${i}" data-layer="${String(rk.layer ?? 'veyra')}"
              style="--x:${String(rk.x)}%;--y:${String(rk.y)}%;--rx:${String(rk.rx)}%"></span>
`,
  ),
)}${join(
  markers
    .filter((m) => m.radius)
    .map(
      (m) => html`        <span class="mzone mzone--${String(m.kind ?? 'humana')}" aria-hidden="true"
              data-layer="${String(m.layer ?? 'humana')}"
              style="--x:${String(m.x)}%;--y:${String(m.y)}%;--rx:${String(m.radius)}%"></span>
`,
    ),
)}${join(markers.map((m) => marcador(m, glyphs)))}      </div>

      <div class="map3d__controls">
${join(
  ((d.controls ?? []) as Dict[]).map(
    (c) => html`        <button class="mctrl" type="button" data-map-action="${String(c.action)}"
                aria-label="${String(c.label)}" title="${String(c.label)}">${icon(String(c.icon))}</button>
`,
  ),
)}${vars.opacidade_form ? formOpacidade(d) : ''}      </div>

      <div class="map3d__compass" role="img" aria-label="${String(d.compass?.label ?? 'Rosa dos ventos')}">
        <span class="map3d__north">${String(d.compass?.n ?? 'N')}</span>
        ${ROSA}
      </div>

    </div>
`;
}
