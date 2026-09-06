/**
 * EXTRACT HUNTERS — tela MAPA DO MUNDO.
 *
 *  - OCULTAR / MOSTRAR LEGENDA (botão + atalho X)
 *  - CAMADAS: chips TODOS · HUNTER · FACÇÕES · VEYRA · BASES · EXTRAÇÕES · EVENTOS (PRD v3 §23)
 *  - sinais de atividade humana expiram (data-expires)
 *  - contagem regressiva ao vivo do evento ativo (mm:ss)
 *  - MARCAR PONTO (estado on/off)
 *  - atalho F = DEFINIR ROTA
 *
 * O mapa em si vive em assets/js/three/map3d.js.
 */

const page = document.body;
if (page && page.dataset.page === 'mapa') {
  const $ = (s, r = document) => r.querySelector(s);

  /* ---- legenda ---------------------------------------------------------- */
  const toggle = $('[data-legend-toggle]');
  const legend = document.getElementById('mapa-legend');
  const label = toggle ? toggle.querySelector('.mp__toggle-label') : null;

  function setLegend(open) {
    if (!legend || !toggle) return;
    legend.classList.toggle('is-hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (label) label.textContent = open ? (toggle.dataset.labelOn || '') : (toggle.dataset.labelOff || '');
  }
  if (toggle) toggle.addEventListener('click', () => setLegend(legend.classList.contains('is-hidden')));

  /* ---- camadas (PRD v3 §23.9) --------------------------------------------
   * hud.js já alterna .is-active nos chips de [data-select-group] e dispara
   * `select:change`; aqui só escondemos os marcadores/zonas fora da camada. */
  const layers = $('[data-map-filters]');
  if (layers) {
    const nodes = Array.from(document.querySelectorAll('#map3d-layer [data-layer]'));
    const apply = (slug) => {
      const all = !slug || slug === 'todos';
      nodes.forEach((n) => n.classList.toggle('is-filtered', !all && n.dataset.layer !== slug));
      document.getElementById('map3d')?.setAttribute('data-layer-filter', all ? '' : slug);
    };
    layers.addEventListener('select:change', (e) => apply(e.detail?.value));
  }

  /* ---- sinais de atividade humana expiram (§23.8) --------------------------
   * Posição aproximada e temporária: o rótulo mostra o tempo restante e o
   * marcador se apaga ao chegar em zero — o backend real substituirá isto. */
  const temp = Array.from(document.querySelectorAll('#map3d-layer .mk[data-expires]'));
  if (temp.length) {
    const pad = (n) => String(n).padStart(2, '0');
    const st = temp.map((el) => ({ el, s: Math.max(0, parseInt(el.dataset.expires, 10) || 0), sub: el.querySelector('.mk__sub') }));
    const tick = () => {
      st.forEach((t) => {
        if (t.s > 0) t.s -= 1;
        if (t.sub) t.sub.textContent = 'EXPIRA EM ' + pad(Math.floor(t.s / 60)) + ':' + pad(t.s % 60);
        t.el.classList.toggle('is-expired', t.s === 0);
      });
    };
    tick();
    setInterval(tick, 1000);
  }

  /* ---- contagem regressiva ---------------------------------------------- */
  const clock = $('[data-countdown]');
  if (clock) {
    const parts = String(clock.dataset.countdown || '').split(':');
    let left = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    const start = left;
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
      left = left > 0 ? left - 1 : start;
      clock.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
      clock.classList.toggle('amber', left <= 60);
    };
    setInterval(tick, 1000);
  }

  /* ---- MARCAR PONTO ----------------------------------------------------- */
  const mark = $('[data-mark-cta]');
  if (mark) {
    const txt = mark.querySelector('.btn__label');
    mark.addEventListener('click', () => {
      const on = mark.getAttribute('aria-pressed') !== 'true';
      mark.setAttribute('aria-pressed', on ? 'true' : 'false');
      mark.classList.toggle('is-on', on);
      if (txt) txt.textContent = on ? (mark.dataset.labelOn || '') : (mark.dataset.label || '');
    });
  }

  /* ---- atalhos ----------------------------------------------------------- */
  const route = $('[data-route-cta]');
  document.addEventListener('keydown', (ev) => {
    if (ev.ctrlKey || ev.altKey || ev.metaKey) return;
    const t = ev.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || ''))) return;
    const k = ev.key.toLowerCase();
    if (k === 'x' && toggle) { ev.preventDefault(); toggle.click(); }
    else if (k === 'f' && route) { ev.preventDefault(); route.click(); }
  });
}
