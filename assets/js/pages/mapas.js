/**
 * Tela MAPAS — seleção de mapa, filtro por modo e contagem regressiva da rotação.
 * A troca de estado (is-selected / is-active) já é feita pelo hud.js via
 * [data-select-group]; aqui só reagimos ao evento `select:change`.
 */

const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

const grid    = $('[data-map-grid]');
const detail  = $('[data-detail]');
const filters = $('[data-filters]');

/* ------------------------------------------------------------ detalhe */
const pad2 = (n) => String(n).padStart(2, '0');

function setText(sel, value) {
  const el = $(sel, detail);
  if (el) el.textContent = value || '';
}

function applyDetail(card) {
  if (!card || !detail) return;
  const d = card.dataset;

  const img = $('[data-detail-img]', detail);
  if (img && d.img && img.getAttribute('src') !== d.img) {
    img.setAttribute('src', d.img);
    img.setAttribute('alt', d.name || '');
  }
  const use = $('[data-detail-use]', detail);
  if (use && d.sym) use.setAttribute('href', '#' + d.sym);

  setText('[data-detail-name]', d.name);
  setText('[data-detail-mode]', d.mode);
  setText('[data-detail-players]', d.players);
  setText('[data-detail-desc]', d.desc);
  setText('[data-detail-size]', d.size);
  setText('[data-detail-biome]', d.biome);
  setText('[data-detail-weather]', d.weather);
  setText('[data-detail-best]', d.best);
  setText('[data-detail-best-by]', d.bestBy);
  setText('[data-detail-your]', d.yourBest);

  const total = $$('.map-card', grid).length;
  setText('[data-detail-index]', pad2(d.index || '1') + ' / ' + pad2(total));

  detail.classList.remove('is-swapping');
  void detail.offsetWidth;
  detail.classList.add('is-swapping');
}

if (grid && detail) {
  grid.addEventListener('select:change', (e) => applyDetail(e.detail && e.detail.item));

  /* teclado: Enter/Espaço selecionam o card focado */
  grid.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    const card = e.target.closest('.map-card');
    if (!card) return;
    e.preventDefault();
    card.click();
  });
}

/* ------------------------------------------------------------- filtro */
if (grid && filters) {
  const counter = $('[data-map-count]');
  const empty   = $('[data-map-empty]');
  const cards   = $$('.map-card', grid);
  const total   = cards.length;

  filters.addEventListener('select:change', (e) => {
    const chip = e.detail && e.detail.item;
    if (!chip) return;
    const key = chip.dataset.filter || 'all';
    let visible = 0;

    cards.forEach((card, i) => {
      const show = key === 'all' || card.dataset.modeKey === key;
      card.classList.toggle('is-hidden', !show);
      card.classList.remove('is-refiltered');
      if (show) {
        visible += 1;
        void card.offsetWidth;
        card.style.animationDelay = (visible * 40) + 'ms';
        card.classList.add('is-refiltered');
      } else {
        card.style.animationDelay = '';
      }
    });

    if (counter) counter.textContent = visible + ' / ' + total;
    if (empty) empty.hidden = visible > 0;

    /* se o mapa aberto saiu do filtro, abre o primeiro visível */
    const current = cards.find((c) => c.classList.contains('is-selected'));
    if (!current || current.classList.contains('is-hidden')) {
      const next = cards.find((c) => !c.classList.contains('is-hidden'));
      if (next) next.click();
    }
  });
}

/* --------------------------------------------------------- contagem */
const clock = $('[data-countdown]');
if (clock) {
  let left = Math.max(0, parseInt(clock.dataset.countdown, 10) || 0);
  const render = () => {
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    const s = left % 60;
    clock.textContent = pad2(h) + ':' + pad2(m) + ':' + pad2(s);
  };
  render();
  setInterval(() => {
    left = left > 0 ? left - 1 : 43200;
    render();
  }, 1000);
}
