/**
 * Tela ADMIN · EDITAR MAPA (/admin/mapas/editor) — app/Views/pages/admin-mapas-editor.php.
 * Layout 'admin'.
 *
 * TRÊS DECISÕES DE DESENHO (iguais ao PHP)
 * 1. TRÊS COLUNAS, e a do meio é a arte: paleta (o que se pode pôr), mapa (onde se põe),
 *    detalhes (o que aquele ponto é).
 * 2. O MAPA É UMA CAIXA ROLÁVEL, e o zoom muda a LARGURA do palco, não uma transformação.
 * 3. O ESTADO VIVE NO JAVASCRIPT (assets/js/admin/admin-mapas-editor.js), e o servidor recebe
 *    o conjunto inteiro num campo só (`marcadores`, JSON) no submit do formulário.
 *
 * SEM JAVASCRIPT esta tela não arrasta — e diz isso no <noscript>.
 */
import { BASE_URL } from '../../core/config';
import { csrfField, icon, media, url } from '../../core/helpers';
import { html, join, type SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';
import { mapPointIconCmp } from '../components/map-point-icon';
import { jsonHexTag, phpOu } from './admin-mapas-pontos';

const str = (v: unknown, def = ''): string => (v === null || v === undefined ? def : String(v));
const int = (v: unknown): number => {
  const n = Number.parseInt(String(v ?? ''), 10);
  return Number.isNaN(n) ? 0 : n;
};
const obj = (v: unknown): Dict => (v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {});
const lista = (v: unknown): Dict[] => (Array.isArray(v) ? (v as Dict[]) : []);

/** round($v, 2) do PHP para a opacidade (sempre positiva). */
const rnd2 = (v: number): number => Number(Math.round(Number(v + 'e2')) + 'e-2');

export function adminMapasEditor(s: Scope): SafeHtml {
  const data = s.data;
  const cromo = obj(data.cromo);
  const ajuda = obj(cromo.ajuda);
  const grupos = obj(cromo.grupos);
  const rotulos = obj(cromo.rotulos);
  const vazios = obj(cromo.vazios);
  const notas = obj(cromo.notas);

  const mapa = obj(data.mapa);
  const mapas = lista(data.mapas);
  const arte = str(data.arte);
  const paleta = lista(data.paleta);
  const missoes = lista(data.missoes);
  const marcs = lista(data.marcadores);
  const cores = Array.isArray(data.cores) ? (data.cores as unknown[]) : [];
  const kinds = obj(data.kinds);
  const mapaId = int(mapa.id);
  const opacidade = Number(data.opacidade ?? 0.65);
  const opPct = Math.round(opacidade * 100);

  /* Catálogo achatado e indexado pelo slug: é assim que o editor procura o desenho de um
     ponto quando ele é arrastado. */
  const tiposPorSlug: Dict = {};
  for (const g of paleta) for (const t of lista(g.items)) tiposPorSlug[str(t.slug)] = t;

  /* O ponto "objetivo de missão" é o molde de tudo que for arrastado da gaveta de missões. */
  let tipoMissao = 'mission-objective';
  procura: for (const g of paleta) {
    for (const t of lista(g.items)) {
      if (str(t.kind) === 'mission') {
        tipoMissao = str(t.slug);
        break procura;
      }
    }
  }

  const dados = {
    mapaId,
    marcadores: marcs,
    glifos: obj(data.glifos),
    tipos: tiposPorSlug,
    tipoMissao,
    rotulos,
    max: int(data.max ?? 400),
    opacidade,
    media: BASE_URL + 'media/',
  };

  return html`
<script type="application/json" data-mape-dados>${jsonHexTag(dados)}</script>

<noscript>
  <p class="adm-hint adm-hint--err mape-nojs">${str(notas.semjs)}</p>
</noscript>

<section class="adm-card mape" data-mape>

  <!-- ============================== BARRA DE FERRAMENTAS ============================== -->
  <div class="adm-card__head mape__bar">
    <form class="mape__pick" method="get" action="${url('admin/mapas/editor')}">
      <label class="adm-field adm-field--inline">
        <span class="adm-label">Mapa</span>
        <select class="adm-select" name="mapa" data-mape-mapa>
          ${join(mapas.map((m) => html`
            <option value="${int(m.id)}"${int(m.id) === mapaId ? ' selected' : ''}>
              ${str(m.name)} · ${int(m.marcadores)} ponto(s)
            </option>`))}
        </select>
      </label>
      <button class="adm-btn adm-btn--sm" type="submit">Abrir</button>
    </form>

    <div class="mape__tools">
      <div class="mape__zoom" role="group" aria-label="Zoom do mapa">
        <button class="adm-btn adm-btn--icon adm-btn--sm" type="button" data-mape-zoom="-" aria-label="Diminuir zoom">${icon('remove')}</button>
        <span class="mape__zoom-v num" data-mape-zoom-v>100%</span>
        <button class="adm-btn adm-btn--icon adm-btn--sm" type="button" data-mape-zoom="+" aria-label="Aumentar zoom">${icon('add')}</button>
        <button class="adm-btn adm-btn--icon adm-btn--sm" type="button" data-mape-zoom="fit" aria-label="Ajustar à tela">${icon('fit_screen')}</button>
      </div>

      <label class="mape__op" title="Opacidade da arte no mapa do jogador">
        ${icon('opacity', 'mape__op-ic')}
        <input type="range" min="15" max="100" step="1"
               value="${opPct}" data-mape-opacidade>
        <span class="mape__op-v num" data-mape-opacidade-v>${opPct}%</span>
      </label>

      <label class="adm-check mape__check">
        <input type="checkbox" data-mape-nomes>
        <span>Nomes</span>
      </label>

      <button class="adm-btn adm-btn--ghost adm-btn--sm" type="button" data-mape-desfazer disabled>
        ${icon('undo')}<span>Desfazer</span>
      </button>

      <span class="mape__estado" data-mape-estado role="status" aria-live="polite"></span>

      <form method="post" action="${url('admin/mapas/editor/salvar')}" data-mape-form>
        ${csrfField()}
        <input type="hidden" name="mapa_id" value="${mapaId}">
        <input type="hidden" name="marcadores" value="[]" data-mape-payload>
        <input type="hidden" name="opacidade" value="${String(rnd2(opacidade))}" data-mape-op-payload>
        <button class="adm-btn adm-btn--primary adm-btn--sm" type="submit" data-mape-salvar>
          ${icon('save')}<span>Salvar mapa</span>
        </button>
      </form>
    </div>
  </div>

  <div class="mape__body">

    <!-- ================================== PALETA ================================== -->
    <aside class="mape__palette" aria-label="Paleta de pontos">
      <label class="adm-search mape__search">
        ${icon('search')}
        <input class="adm-input" type="search" placeholder="Buscar na paleta" data-mape-busca>
      </label>

      <details class="mape__group" open>
        <summary class="mape__group-t">
          ${str(grupos.missoes, 'Missões')}
          <span class="mape__group-n num">${missoes.length}</span>
        </summary>
        <div class="mape__items">
          ${missoes.length === 0 ? html`<p class="adm-hint mape__empty">${str(vazios.missoes)}</p>` : ''}
          ${join(missoes.map((ms) => html`
          <button class="mape__chip" type="button" draggable="true"
                  data-mape-chip data-tipo="${tipoMissao}" data-missao="${int(ms.id)}"
                  data-nome="${str(ms.name)}"
                  data-busca="${(str(ms.name) + ' ' + str(ms.region) + ' ' + str(ms.giver)).toLowerCase()}"
                  title="${str(cromo.missao_dica)}">
            ${mapPointIconCmp({ icon: 'glyph:mission', color: 'gold', size: 'sm' })}
            <span class="mape__chip-txt">
              <strong>${str(ms.name)}</strong>
              <small>${phpOu(phpOu(str(ms.region), str(ms.giver)), str(ms.type))}</small>
            </span>
          </button>`))}
        </div>
      </details>

      ${join(paleta.map((g) => {
        const itens = lista(g.items);
        return html`
      <details class="mape__group"${str(g.key) === 'operacional' ? ' open' : ''}>
        <summary class="mape__group-t">
          ${str(g.label)}
          <span class="mape__group-n num">${itens.length}</span>
        </summary>
        <div class="mape__items">
          ${join(itens.map((t) => html`
          <button class="mape__chip" type="button" draggable="true"
                  data-mape-chip data-tipo="${str(t.slug)}"
                  data-nome="${str(t.label)}"
                  data-busca="${(str(t.label) + ' ' + str(t.slug) + ' ' + str(t.kind)).toLowerCase()}"
                  title="${str(t.description)}">
            ${mapPointIconCmp({ icon: str(t.icon), color: str(t.color), size: 'sm' })}
            <span class="mape__chip-txt">
              <strong>${str(t.label)}</strong>
              <small>${str(t.kind)}</small>
            </span>
          </button>`))}
        </div>
      </details>`;
      }))}

      <p class="mape__empty mape__empty--busca" data-mape-vazio hidden>${str(vazios.busca)}</p>
    </aside>

    <!-- =================================== PALCO =================================== -->
    <div class="mape__stagewrap">
      <div class="mape__stage scroll-y" data-mape-stage>
        <div class="mape__canvas" data-mape-canvas style="--mape-art-opacity:${String(rnd2(opacidade))}">
          <img class="mape__art" src="${media(arte)}" alt="${str(mapa.name)}" draggable="false" decoding="async">
          <div class="mape__markers" data-mape-layer></div>
        </div>
      </div>
      <p class="mape__help">
        ${icon('drag_pan', 'mape__help-ic')}
        ${str(ajuda.arrastar)}
        ${str(ajuda.mover)}
        ${str(ajuda.desfazer)}
      </p>
    </div>

    <!-- ================================= DETALHES ================================= -->
    <aside class="mape__side" aria-label="Detalhes do marcador">

      <div class="mape__none" data-mape-vazio-sel>
        <span class="mape__none-ic">${icon('ads_click')}</span>
        <p class="adm-hint">${marcs.length === 0 ? str(vazios.mapa) : str(ajuda.mover)}</p>
      </div>

      <div class="mape__props" data-mape-props hidden>
        <label class="adm-field">
          <span class="adm-label">Nome</span>
          <input class="adm-input" type="text" maxlength="80" data-mape-p="name">
        </label>

        <div class="adm-form__row adm-form__row--2">
          <label class="adm-field">
            <span class="adm-label">Cor</span>
            <select class="adm-select" data-mape-p="color">
              ${join(cores.map((c) => html`<option value="${str(c)}">${str(c).toUpperCase()}</option>`))}
            </select>
          </label>
          <label class="adm-field">
            <span class="adm-label">Nome no mapa</span>
            <select class="adm-select" data-mape-p="label">
              ${join(Object.entries(rotulos).map(([k, rot]) => html`<option value="${k}">${str(rot)}</option>`))}
            </select>
          </label>
        </div>

        <label class="adm-field">
          <span class="adm-label">Ícone</span>
          <select class="adm-select" data-mape-p="icon">
            <option value="">Herdar do ponto da paleta</option>
            ${join(paleta.map((g) => html`
              <optgroup label="${str(g.label)}">
                ${join(lista(g.items).map((t) => html`
                  <option value="${str(t.icon)}">${str(t.label)}</option>`))}
              </optgroup>`))}
          </select>
          <span class="adm-hint">
            Para desenhar um ícone que ainda não existe, crie o ponto em
            <a href="${url('admin/mapas/pontos', { novo: '1' })}">Criar pontos</a> — lá se envia SVG, PNG ou WebP.
          </span>
        </label>

        <label class="adm-field">
          <span class="adm-label">Comportamento no jogo</span>
          <select class="adm-select" data-mape-p="kind">
            ${join(Object.entries(kinds).map(([k, rot]) => html`<option value="${k}">${str(rot)}</option>`))}
          </select>
          <span class="adm-hint">Vem do ponto da paleta. Mudar aqui vale só para este marcador.</span>
        </label>

        <div class="adm-form__row adm-form__row--3">
          <label class="adm-field">
            <span class="adm-label">X</span>
            <input class="adm-input num" type="number" step="0.0001" min="0" max="1" data-mape-p="x">
          </label>
          <label class="adm-field">
            <span class="adm-label">Y</span>
            <input class="adm-input num" type="number" step="0.0001" min="0" max="1" data-mape-p="y">
          </label>
          <label class="adm-field">
            <span class="adm-label">Raio %</span>
            <input class="adm-input num" type="number" step="0.1" min="0" max="40" data-mape-p="radius">
          </label>
        </div>
        <p class="adm-hint">${str(notas.raio)}</p>

        <p class="adm-hint mape__extras" data-mape-extras hidden></p>

        <div class="mape__props-foot">
          <button class="adm-btn adm-btn--ghost adm-btn--sm" type="button" data-mape-duplicar>${icon('content_copy')}<span>Duplicar</span></button>
          <button class="adm-btn adm-btn--danger adm-btn--sm" type="button" data-mape-remover>${icon('delete')}<span>Remover</span></button>
        </div>
      </div>
    </aside>

  </div>
</section>
`;
}
