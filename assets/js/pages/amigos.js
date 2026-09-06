/**
 * Tela AMIGOS — filtro de busca ao vivo sobre as três listas (online/offline/pedidos).
 * A linha de vazio troca de texto: mensagem da aba quando a lista está vazia de verdade,
 * mensagem de busca quando foi o filtro que zerou a lista.
 * Sem dependências: o resto (abas, boot com stagger, tooltips) vem do hud.js.
 */
const input = document.getElementById('friends-search');
const lists = Array.from(document.querySelectorAll('.page-amigos .flist'));

if (input && lists.length) {
  const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

  const apply = () => {
    const q = norm(input.value);
    lists.forEach((list) => {
      const rows = Array.from(list.querySelectorAll('.frow'));
      let visible = 0;
      rows.forEach((row) => {
        const hit = q === '' || norm(row.dataset.name || '').includes(q);
        row.hidden = !hit;
        if (hit) visible += 1;
      });
      const empty = list.querySelector('.flist__empty');
      if (empty) {
        const searching = q !== '' && rows.length > 0;
        empty.textContent = searching ? (empty.dataset.emptySearch || '') : (empty.dataset.emptyList || '');
        empty.hidden = visible !== 0;
      }
    });
  };

  let t = 0;
  input.addEventListener('input', () => {
    window.clearTimeout(t);
    t = window.setTimeout(apply, 90);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && input.value !== '') {
      e.preventDefault();
      input.value = '';
      apply();
    }
  });
}
