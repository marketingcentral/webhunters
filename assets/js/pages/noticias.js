/**
 * EXTRACT HUNTERS — tela NOTÍCIAS.
 *
 * Único comportamento próprio: as contagens regressivas ao vivo da coluna
 * direita ([data-countdown="<ISO 8601>"]). Tudo o mais (stagger de boot,
 * transição de página, tooltips) vem de hud.js.
 */

const clocks = Array.from(document.querySelectorAll('[data-countdown]'))
  .map((el) => ({ el, at: Date.parse(el.dataset.countdown || '') }))
  .filter((c) => Number.isFinite(c.at));

if (clocks.length) {
  const pad = (n) => String(n).padStart(2, '0');

  const label = (ms) => {
    if (ms <= 0) return 'AGORA';
    const total = Math.floor(ms / 1000);
    const d = Math.floor(total / 86400);
    const h = Math.floor((total % 86400) / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return (d > 0 ? `${d}D ` : '') + `${pad(h)}:${pad(m)}:${pad(s)}`;
  };

  let timer = 0;

  const tick = () => {
    const now = Date.now();
    for (const c of clocks) {
      const text = label(c.at - now);
      if (c.el.textContent !== text) c.el.textContent = text;
      c.el.classList.toggle('is-over', c.at - now <= 0);
    }
  };

  const start = () => {
    tick();
    if (!timer) timer = window.setInterval(tick, 1000);
  };
  const stop = () => {
    if (timer) { window.clearInterval(timer); timer = 0; }
  };

  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
  window.addEventListener('pagehide', stop);
  start();
}
