/**
 * Tela BESTIÁRIO — escolher uma carta troca a ficha da direita.
 *
 * As fichas já vêm todas no HTML; aqui só se alterna qual está visível e se grava ?c=<slug>
 * na URL (replaceState, sem navegação) para o link da criatura aberta continuar valendo.
 * Setas ← → percorrem as cartas na ordem da grade.
 * Reavaliado a cada visita pelo spa.js: o topo só procura elementos e liga eventos.
 */
const root = document.querySelector('.page-bestiario');
if (root) {
  const cards  = Array.from(root.querySelectorAll('.bst-card'));
  const sheets = Array.from(root.querySelectorAll('.bst-sheet'));

  const select = (slug, focar) => {
    cards.forEach((c) => {
      const on = c.dataset.criatura === slug;
      c.classList.toggle('is-selected', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (on && focar) c.focus();
    });
    sheets.forEach((s) => {
      const on = s.dataset.ficha === slug;
      s.classList.toggle('is-on', on);
      s.hidden = !on;
      if (on) s.scrollTop = 0;
    });
    try {
      const u = new URL(window.location.href);
      u.searchParams.set('c', slug);
      window.history.replaceState(window.history.state, '', u.toString());
    } catch (e) { /* URL indisponível: a seleção funciona igual, só não vira link */ }
  };

  cards.forEach((card) => {
    card.addEventListener('click', () => select(card.dataset.criatura, false));
  });

  root.addEventListener('keydown', (ev) => {
    if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
    if (!ev.target.closest || !ev.target.closest('.bst-card')) return;
    const i = cards.indexOf(ev.target.closest('.bst-card'));
    const n = cards[(i + (ev.key === 'ArrowRight' ? 1 : cards.length - 1)) % cards.length];
    if (n) { ev.preventDefault(); select(n.dataset.criatura, true); }
  });
}
