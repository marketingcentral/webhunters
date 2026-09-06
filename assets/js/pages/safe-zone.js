/**
 * Tela SAFE ZONE — só o que precisa de vida própria.
 *
 *  - contagem regressiva do turno (mm:ss). Ao chegar a zero o painel avisa que o turno venceu;
 *    quem encerra de verdade é o servidor, no próximo POST — aqui é só leitura.
 *  - Enter/Espaço nos cards de papel dispara o botão do próprio card, para navegação por teclado.
 *
 * O intervalo é varrido pela época do spa.js ao sair da tela, como nas outras páginas.
 */

const relogio = document.querySelector('[data-shift-countdown]');
if (relogio) {
  const pad = (n) => String(n).padStart(2, '0');
  let s = Math.max(0, parseInt(relogio.dataset.shiftCountdown, 10) || 0);
  const painel = relogio.closest('.sz-shift');

  const pinta = () => {
    const m = Math.floor(s / 60);
    relogio.textContent = `${pad(m)}:${pad(s % 60)}`;
    relogio.classList.toggle('amber', s > 0 && s <= 300);
    if (s === 0) {
      relogio.textContent = 'TURNO VENCIDO';
      painel?.classList.add('is-over');
    }
  };

  pinta();
  if (s > 0) {
    const t = setInterval(() => {
      s = Math.max(0, s - 1);
      pinta();
      if (s === 0) clearInterval(t);
    }, 1000);
  }
}

/* teclado: o card inteiro responde, mas quem envia continua sendo o botão do formulário */
document.querySelectorAll('.sz-role').forEach((card) => {
  card.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    if (ev.target !== card) return;
    const btn = card.querySelector('.sz-role__form button, .sz-role__form [type="submit"]');
    if (!btn) return;
    ev.preventDefault();
    btn.click();
  });
});
