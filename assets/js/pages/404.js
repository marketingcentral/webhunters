/**
 * Tela de ERRO (404 / 405 / 419 / 500).
 *   1) relógio "TEMPO SEM SINAL" contando ao vivo desde a chegada na página;
 *   2) rajada de glitch ocasional no código gigante (a tremulação base é CSS).
 * Tudo desligado em prefers-reduced-motion; nenhuma dependência externa.
 */

const panel = document.querySelector('.page-404 .err');
if (panel) {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- relógio: mm:ss desde o carregamento ------------------------------ */
  const clock = panel.querySelector('[data-signal-clock]');
  if (clock) {
    const t0 = performance.now();
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
      const s = Math.floor((performance.now() - t0) / 1000);
      clock.textContent = pad(Math.floor(s / 60) % 60) + ':' + pad(s % 60);
    };
    tick();
    const id = window.setInterval(tick, 1000);
    window.addEventListener('pagehide', () => window.clearInterval(id), { once: true });
  }

  /* ---- rajada de glitch a cada 4-9 s ------------------------------------ */
  if (!reduce && panel.querySelector('[data-glitch]')) {
    let timer = 0;
    const burst = () => {
      panel.classList.add('is-glitching');
      window.setTimeout(() => panel.classList.remove('is-glitching'), 340);
      timer = window.setTimeout(burst, 4000 + Math.random() * 5000);
    };
    timer = window.setTimeout(burst, 2200);
    window.addEventListener('pagehide', () => window.clearTimeout(timer), { once: true });
  }
}
