/**
 * EXTRACT HUNTERS — tela INÍCIO.
 *
 * O grosso do comportamento já vem de hud.js (boot/stagger, contadores,
 * transição de página) e carousel.js (passe de temporada + notícias).
 * Aqui só fica o que é da tela:
 *   - contador ao vivo do fim da temporada ([data-countdown="ISO"])
 *   - troca do slide do passe pelas setas quando o painel tem foco
 */

const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ------------------------------------------------ fim da temporada (ao vivo) */
function countdowns() {
  const nodes = Array.from(document.querySelectorAll('[data-countdown]'))
    .map((el) => ({ el, at: Date.parse(el.dataset.countdown || '') }))
    .filter((n) => Number.isFinite(n.at));
  if (nodes.length === 0) return;

  const pad = (n) => String(n).padStart(2, '0');
  const tick = () => {
    const now = Date.now();
    for (const { el, at } of nodes) {
      const left = Math.max(0, at - now);
      const d = Math.floor(left / 86400000);
      const h = Math.floor((left % 86400000) / 3600000);
      const m = Math.floor((left % 3600000) / 60000);
      el.textContent = left > 0 ? `TERMINA EM ${d}D ${pad(h)}H ${pad(m)}M` : 'TEMPORADA ENCERRADA';
    }
  };
  tick();
  const id = window.setInterval(tick, 30000);
  window.addEventListener('pagehide', () => clearInterval(id), { once: true });
}

/* ------------------------------------- setas do teclado no painel do passe */
function seasonKeys() {
  const season = document.querySelector('.season');
  const car = season?.querySelector('[data-carousel]');
  if (!season || !car) return;
  season.addEventListener('keydown', (e) => {
    if (!car.carousel) return;
    if (e.key === 'ArrowRight') { car.carousel.next(); car.carousel.restart(); }
    else if (e.key === 'ArrowLeft') { car.carousel.prev(); car.carousel.restart(); }
  });
}

/* ------------------- brilho de entrada nos botões de menu (uma vez, no boot) */
function menuSweep() {
  if (reduced) return;
  const btns = Array.from(document.querySelectorAll('.inicio__menu .menu-btn'));
  btns.forEach((b, i) => {
    window.setTimeout(() => {
      b.classList.add('is-sweeping');
      window.setTimeout(() => b.classList.remove('is-sweeping'), 700);
    }, 420 + i * 90);
  });
}

/* Os carrosséis começam a contar no DOMContentLoaded; com o .glb do soldado
   ainda baixando isso faz a Home abrir já no 2º slide. Zera e reinicia os
   timers quando tudo terminou de carregar. */
function syncCarousels() {
  const reset = () => {
    document.querySelectorAll('.inicio [data-carousel]').forEach((el) => {
      if (!el.carousel) return;
      el.carousel.go(0, false);
      el.carousel.restart();
    });
  };
  if (document.readyState === 'complete') reset();
  else window.addEventListener('load', reset, { once: true });
}

function init() {
  syncCarousels();
  countdowns();
  seasonKeys();
  menuSweep();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
