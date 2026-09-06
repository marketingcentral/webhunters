/**
 * EXTRACT HUNTERS — tela MISSÕES.
 * Depende de hud.js: os grupos [data-select-group] já alternam .is-active nos
 * filtros, nos chips de tipo e nos cards. Aqui só reagimos a `select:change`.
 *
 *  - filtros de estado + chips de tipo filtram o quadro de contratos
 *  - selecionar um card troca o painel de detalhe da direita
 *  - relógios [data-countdown="segundos"] correm ao vivo
 */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

/* ------------------------------------------------------------- filtragem */
const list = $('[data-list]');
const cards = $$('.mcard', list || document);
const emptyEl = $('[data-empty]');
const countEl = $('[data-list-count]');
const wordEl = $('[data-list-word]');

/** slug do filtro -> teste sobre o card */
const MATCH = {
  todas: () => true,
  ativas: (c) => c.dataset.status === 'active',
  disponiveis: (c) => c.dataset.status === 'available',
  concluidas: (c) => c.dataset.status === 'done',
  diarias: (c) => c.dataset.type === 'diaria',
};

let filter = 'todas';

function activeTypes() {
  const chips = $$('[data-types] .type-chip.is-active');
  return chips.length ? chips.map((c) => c.dataset.value) : null; // null = todos
}

function applyFilters() {
  const test = MATCH[filter] || MATCH.todas;
  const types = activeTypes();
  let visible = 0;
  cards.forEach((c) => {
    const ok = test(c) && (types === null || types.includes(c.dataset.type));
    c.classList.toggle('is-hidden', !ok);
    if (ok) visible++;
  });
  if (emptyEl) emptyEl.hidden = visible > 0;
  if (countEl) countEl.textContent = String(visible);
  if (wordEl) wordEl.textContent = visible === 1 ? 'CONTRATO' : 'CONTRATOS';
}

const filters = $('[data-filters]');
if (filters) {
  filters.addEventListener('select:change', (e) => {
    filter = e.detail?.value || 'todas';
    applyFilters();
  });
}

const types = $('[data-types]');
if (types) types.addEventListener('select:change', applyFilters);

/* --------------------------------------------------- detalhe da missão */
const details = $$('[data-detail]');

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function showDetail(slug) {
  const target = details.find((d) => d.dataset.detail === slug);
  if (!target) return;
  details.forEach((d) => {
    const on = d === target;
    d.hidden = !on;
    d.classList.toggle('is-shown', on);
  });
  if (reduced) return;
  // reinicia a entrada do painel a cada troca
  target.classList.remove('is-swapped');
  void target.offsetWidth;
  target.classList.add('is-swapped');
}

if (list) {
  list.addEventListener('select:change', (e) => {
    const slug = e.detail?.value;
    if (slug) showDetail(slug);
  });
}

/* -------------------------------------------------------- contagens ao vivo */
const clocks = $$('[data-countdown]');
if (clocks.length) {
  const pad = (n) => String(n).padStart(2, '0');
  const state = clocks.map((el) => ({ el, s: Math.max(0, parseInt(el.dataset.countdown, 10) || 0) }));
  const tick = () => {
    state.forEach((c) => {
      if (c.s > 0) c.s -= 1;
      const h = Math.floor(c.s / 3600);
      const m = Math.floor((c.s % 3600) / 60);
      c.el.textContent = `${pad(h)}:${pad(m)}:${pad(c.s % 60)}`;
    });
  };
  setInterval(tick, 1000);
}

applyFilters();
