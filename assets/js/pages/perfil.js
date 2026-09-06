/**
 * Tela PERFIL — movimento discreto que o hud.js não cobre.
 *  1. relógio ao vivo da sessão atual (data-session="segundos");
 *  2. parallax sutil do retrato do operador ao mover o mouse sobre o cartão.
 * Tudo desligado em prefers-reduced-motion (o relógio continua, só sem parallax).
 */
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ------------------------------------------------- relógio da sessão */
const clock = document.querySelector('.page-perfil [data-session]');
if (clock) {
  const start = Date.now() - (Number(clock.dataset.session) || 0) * 1000;
  const pad = (n) => String(n).padStart(2, '0');
  const tick = () => {
    const s = Math.max(0, Math.floor((Date.now() - start) / 1000));
    clock.textContent = `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s / 60) % 60)}:${pad(s % 60)}`;
  };
  tick();
  setInterval(tick, 1000);
}

/* ------------------------------------------- parallax do retrato */
const card = document.querySelector('.page-perfil .op');
const img = card && card.querySelector('.op__img');
if (card && img && !reduced && matchMedia('(hover: hover)').matches) {
  let raf = 0;
  const move = (e) => {
    if (raf) return;
    raf = requestAnimationFrame(() => {
      raf = 0;
      const r = card.getBoundingClientRect();
      const x = ((e.clientX - r.left) / r.width - 0.5) * 2;
      const y = ((e.clientY - r.top) / r.height - 0.5) * 2;
      img.style.transform = `scale(1.06) translate(${(-x * 0.9).toFixed(2)}%, ${(-y * 0.9).toFixed(2)}%)`;
    });
  };
  card.addEventListener('mousemove', move);
  card.addEventListener('mouseleave', () => { img.style.transform = ''; });
}
