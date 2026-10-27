/** Perfil — relógio da sessão. O retrato permanece estático para facilitar a leitura. */
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
