/**
 * Tela CONQUISTAS — seleção de card (troca o painel de detalhe), seletor ORDENAR,
 * filtro rápido (tecla R) e botão ACOMPANHAR.
 */
const root = document.querySelector('.page-conquistas');
if (root) {
  const grid    = root.querySelector('.cq-grid');
  const cards   = Array.from(root.querySelectorAll('.ach'));
  const details = Array.from(root.querySelectorAll('.det'));
  const reduce  = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- seleção do card -> painel de detalhe ---------------------------- */
  const replay = (panel) => {
    if (reduce) return;
    panel.querySelectorAll('.progress--anim .progress__bar').forEach((bar) => {
      bar.style.animation = 'none';
      void bar.offsetWidth;
      bar.style.animation = '';
    });
  };

  const select = (slug) => {
    let hit = false;
    cards.forEach((c) => {
      const on = c.dataset.ach === slug;
      c.classList.toggle('is-selected', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    details.forEach((d) => {
      const on = d.dataset.detail === slug;
      d.classList.toggle('is-on', on);
      d.hidden = !on;
      if (on) { hit = true; replay(d); }
    });
    return hit;
  };

  cards.forEach((card) => {
    card.addEventListener('click', () => select(card.dataset.ach));
  });

  /* setas navegam a grade (4 colunas) */
  const COLS = 4;
  grid?.addEventListener('keydown', (ev) => {
    const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: COLS, ArrowUp: -COLS }[ev.key];
    if (step === undefined) return;
    const visible = cards.filter((c) => c.offsetParent !== null);
    const i = visible.indexOf(document.activeElement);
    if (i < 0) return;
    const next = visible[i + step];
    if (!next) return;
    ev.preventDefault();
    next.focus();
    select(next.dataset.ach);
  });

  /* ---- seletor ORDENAR -------------------------------------------------- */
  const sort = root.querySelector('[data-sort]');
  if (sort) {
    const btn  = sort.querySelector('.cq-sort__btn');
    const menu = sort.querySelector('.cq-sort__menu');
    const open = (on) => {
      menu.hidden = !on;
      btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    };
    btn.addEventListener('click', (ev) => {
      ev.stopPropagation();
      open(menu.hidden);
    });
    document.addEventListener('click', (ev) => {
      if (!menu.hidden && !sort.contains(ev.target)) open(false);
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); }
    });
  }

  /* ---- filtro rápido (esconde as concluídas) ---------------------------- */
  const filter = root.querySelector('[data-filter]');
  if (filter && grid) {
    const label = filter.querySelector('.cq-filter__label');
    const toggle = () => {
      const on = filter.getAttribute('aria-pressed') !== 'true';
      filter.setAttribute('aria-pressed', on ? 'true' : 'false');
      grid.classList.toggle('is-filtered', on);
      label.textContent = on ? filter.dataset.labelOn : filter.dataset.labelOff;
      const first = cards.find((c) => c.offsetParent !== null);
      if (first) select(first.dataset.ach);
    };
    filter.addEventListener('click', toggle);
    document.addEventListener('keydown', (ev) => {
      if (ev.key !== 'r' && ev.key !== 'R') return;
      const t = ev.target;
      if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
      if (t instanceof HTMLElement && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
      ev.preventDefault();
      toggle();
    });
  }

  /* ---- botão ACOMPANHAR -------------------------------------------------- */
  root.querySelectorAll('[data-track]').forEach((cta) => {
    cta.addEventListener('click', () => {
      const on = cta.getAttribute('aria-pressed') !== 'true';
      cta.setAttribute('aria-pressed', on ? 'true' : 'false');
      const label = cta.querySelector('.det__cta-label');
      if (label) label.textContent = on ? cta.dataset.labelOn : cta.dataset.labelOff;
    });
  });
}
