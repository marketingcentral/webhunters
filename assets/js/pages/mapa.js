/**
 * EXTRACT HUNTERS — tela MAPA DO MUNDO.
 *
 *  - OCULTAR / MOSTRAR LEGENDA (botão + atalho X)
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
