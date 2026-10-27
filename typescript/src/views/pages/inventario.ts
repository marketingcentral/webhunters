/**
 * INVENTÁRIO — app/Views/pages/inventario.php (layout GEAR estilo Tarkov).
 * A view monta só as ZONAS vazias; assets/js/pages/inventario.js busca /inventario/dados
 * e popula tudo, com drag-drop, rotação (R) e validação espelhada do servidor.
 */
import { asset, icon, media, num, svg, url } from '../../core/helpers';
import { html, join, SafeHtml } from '../../core/html';
import type { Dict, Scope } from '../../core/view';

/** tipos aceitos por slot — o JS revalida e o servidor decide de fato */
const ACCEPTS: Record<string, string> = {
  earpiece: 'earpiece', helmet: 'helmet', mask: 'mask', glasses: 'glasses',
  armor: 'armor,clothing', armband: 'armband', rig: 'rig', backpack: 'backpack',
  primary: 'weapon', secondary: 'weapon', pistol: 'pistol', knife: 'knife',
};

function eqSlot(key: string, label: string, ic: string, mod = ''): SafeHtml {
  return html`  <div class="gslot ${mod}" data-slot="${key}" data-zone="equip" data-accepts="${ACCEPTS[key] ?? ''}">
    <span class="gslot__label">${label}</span>
    <span class="gslot__ph" aria-hidden="true">${svg(ic)}</span>
  </div>
`;
}

function bag(slot: string, label: string, ic: string): SafeHtml {
  return html`  <section class="bag" data-container-panel="${slot}">
    <h2 class="bag__tab">${label}<span class="bag__meta num" data-container-count="${slot}"></span></h2>
    <div class="bag__body">
      <div class="gslot gslot--bag" data-slot="${slot}" data-zone="equip" data-accepts="${ACCEPTS[slot]}">
        <span class="gslot__ph" aria-hidden="true">${svg(ic)}</span>
      </div>
      <div class="bag__grid grid" data-grid data-zone="grid" data-parent-slot="${slot}"></div>
      <p class="bag__empty" data-bag-empty="${slot}">Equipe ${slot === 'rig' ? 'um colete tático' : 'uma mochila'} para abrir os compartimentos.</p>
    </div>
  </section>
`;
}

const FILTROS: Array<[string, string, string]> = [
  ['all', 'TUDO', 'eq-all'], ['weapon', 'ARMAS', 'eq-rifle'], ['ammo', 'MUNIÇÃO', 'eq-ammo'],
  ['armor', 'PROTEÇÃO', 'eq-armor'], ['med', 'MÉDICO', 'eq-med'], ['food', 'CONSUMO', 'eq-food'],
  ['mod', 'ACESSÓRIOS', 'eq-mod'], ['loot', 'ESPÓLIO', 'eq-loot'],
];

export function inventario(s: Scope): SafeHtml {
  const tree: Dict = s.data.tree ?? { stash: { cols: 10, rows: 10, items: [] }, slots: {}, weight: 0, value: 0 };
  const player: Dict = s.data.player ?? s.player ?? {};
  const cur: Dict = player.currencies ?? { credits: 0, cash: 0, points: 0 };
  const wmax = String(s.data.weight_max ?? 60);

  return html`<section class="inv2" data-inv
         data-endpoint-tree="${url('inventario/dados')}"
         data-endpoint-move="${url('inventario/mover')}"
         data-endpoint-equip="${url('inventario/equipar')}"
         data-endpoint-attach="${url('inventario/acessorio')}"
         data-media="${url('').replace(/\/+$/, '')}/media/"
         data-weight-max="${wmax}">

  <!-- ======================= COLUNA 1: PERSONAGEM ======================= -->
  <div class="inv2-col inv2-col--char">
    <h2 class="gear__tab">PERSONAGEM</h2>
    <div class="char" data-character-viewer data-model="${asset('models/soldier.glb')}" aria-busy="true">
      <div class="char__stage">
        <img class="char__body" src="${media('ui/body-silhouette.png')}" alt=""
             width="444" height="1030" decoding="async">
        <canvas class="char__canvas" tabindex="0" role="img" aria-label="Personagem 3D. Use as setas para rotacionar, mais e menos para zoom e Home para restaurar." aria-describedby="character-help"></canvas>
        <span class="char__badge" aria-hidden="true">3D</span>
        <button type="button" class="char__expand" data-character-action="fullscreen" aria-label="Abrir personagem em tela cheia" title="Tela cheia" disabled>${icon('fullscreen')}</button>
        <p class="char__status" role="status" aria-live="polite">Carregando personagem…</p>
      </div>
      <div class="char__controls" role="group" aria-label="Controles do personagem">
        <button type="button" data-character-action="left" aria-label="Rotacionar personagem para a esquerda" title="Girar à esquerda" disabled>${icon('rotate_left')}</button>
        <button type="button" data-character-action="reset" aria-label="Restaurar posição do personagem" title="Restaurar posição" disabled>${icon('restart_alt')}</button>
        <button type="button" data-character-action="right" aria-label="Rotacionar personagem para a direita" title="Girar à direita" disabled>${icon('rotate_right')}</button>
        <button type="button" data-character-action="out" aria-label="Afastar personagem" title="Afastar" disabled>${icon('remove')}</button>
        <button type="button" data-character-action="auto" aria-label="Giro automático do personagem" aria-pressed="false" title="Giro automático" disabled>${icon('360')}</button>
        <button type="button" data-character-action="in" aria-label="Aproximar personagem" title="Aproximar" disabled>${icon('add')}</button>
      </div>
      <p class="char__hint" id="character-help">Arraste para girar · role para zoom</p>
    </div>
  </div>

  <!-- ======================= COLUNA 2: EQUIPAMENTO (paperdoll) ======================= -->
  <div class="inv2-col inv2-col--gear">
    <h1 class="gear__tab">EQUIPAMENTO</h1>
    <div class="gear">
      <div class="gear__slots">
${eqSlot('earpiece', 'FONE', 'eq-earpiece')}${eqSlot('helmet', 'CAPACETE', 'eq-helmet')}${eqSlot('mask', 'MÁSCARA', 'eq-mask')}${eqSlot('armband', 'BRAÇADEIRA', 'eq-armband')}${eqSlot('armor', 'COLETE', 'eq-armor')}${eqSlot('glasses', 'ÓCULOS', 'eq-glasses')}${eqSlot('primary', 'NA BANDOLEIRA', 'eq-rifle', 'gslot--weapon')}${eqSlot('pistol', 'COLDRE', 'eq-pistol')}${eqSlot('secondary', 'NAS COSTAS', 'eq-rifle', 'gslot--weapon')}${eqSlot('knife', 'BAINHA', 'eq-knife', 'gslot--knife')}      </div>
    </div>
    <ul class="vitals">
      <li class="vitals__i">${svg('eq-weight')}<span class="vitals__v num" data-weight>0,0<i>/${wmax} KG</i></span></li>
      <li class="vitals__i vitals__i--hp">${svg('eq-health')}<span class="vitals__v num">435<i>/435</i></span></li>
      <li class="vitals__i vitals__i--hyd">${svg('eq-hydration')}<span class="vitals__v num">100<i>/100</i></span></li>
      <li class="vitals__i vitals__i--nrg">${svg('eq-energy')}<span class="vitals__v num">100<i>/100</i></span></li>
    </ul>
  </div>

  <!-- ======================= COLUNA 3: CONTÊINERES ======================= -->
  <div class="inv2-col inv2-col--bags">
${bag('rig', 'COLETE TÁTICO', 'eq-rig')}    <section class="bag bag--pockets">
      <h2 class="bag__tab">BOLSOS</h2>
      <div class="bag__body">
        <div class="bag__grid grid grid--pockets" data-grid data-zone="pockets" data-cols="4" data-rows="1"></div>
      </div>
    </section>
${bag('backpack', 'MOCHILA', 'eq-backpack')}  </div>

  <!-- ======================= COLUNA 4: STASH ======================= -->
  <div class="inv2-col inv2-col--stash">
    <h2 class="gear__tab gear__tab--stash">STASH
      <span class="inv2-wallet">
        <span class="w-cur w-cur--credits">${svg('coin-credit')}<b class="num">${num(cur.credits ?? 0)}</b></span>
        <span class="w-cur w-cur--cash">${svg('coin-cash')}<b class="num">${num(cur.cash ?? 0)}</b></span>
        <span class="w-cur w-cur--points">${svg('coin-points')}<b class="num">${num(cur.points ?? 0)}</b></span>
      </span>
    </h2>
    <div class="stash">
      <!-- trilha de filtros: destaca as categorias, não remove itens da grade -->
      <nav class="stash__rail" data-filters aria-label="Filtrar por categoria">
${join(FILTROS.map(([k, lbl, ic]) => html`          <button type="button" class="stash__filter${k === 'all' ? ' is-on' : ''}" data-filter="${k}" title="${lbl}" aria-label="${lbl}">${svg(ic)}</button>
`))}      </nav>
      <div class="grid grid--stash" data-grid data-zone="stash"
           data-cols="${String(tree.stash?.cols ?? 10)}" data-rows="${String(tree.stash?.rows ?? 10)}"></div>
    </div>
    <footer class="inv2-foot">
      <span class="inv2-foot__lbl">VALOR TOTAL</span>
      <span class="inv2-foot__val num" data-value>0</span>
      <span class="inv2-hint"><kbd>R</kbd> girar · arraste para mover · <kbd>botão direito</kbd> ações</span>
    </footer>
  </div>

  <!-- modal de acessórios (aberto pelo menu de contexto de uma arma) -->
  <div class="pick" data-pick hidden>
    <div class="pick__box" role="dialog" aria-modal="true" aria-labelledby="pick-title">
      <header class="pick__head">
        <h3 class="pick__title" id="pick-title" data-pick-title>ESCOLHER ITEM</h3>
        <span class="pick__sub" data-pick-sub></span>
        <button type="button" class="pick__close ms" data-pick-close aria-label="Fechar">close</button>
      </header>
      <nav class="pick__slots" data-pick-slots hidden></nav>
      <div class="pick__grid" data-pick-grid></div>
      <p class="pick__empty" data-pick-empty hidden>Nenhum item compatível no seu inventário.</p>
      <footer class="pick__foot">
        <button type="button" class="btn btn--outline pick__unequip" data-pick-unequip hidden>REMOVER ATUAL</button>
        <span class="pick__hint">Clique num item para usar · Esc fecha</span>
      </footer>
    </div>
  </div>

  <div class="drag-ghost" data-drag-ghost hidden></div>
  <div class="inv2-toast" data-toast hidden></div>
</section>
`;
}
