/**
 * Tela RELATÓRIO DE BUG — comportamentos próprios da página.
 *  - stepper de CATEGORIA: hud.js cicla os rótulos; aqui sincronizamos o slug
 *    no input escondido e trocamos o ícone da face.
 *  - contadores de caracteres dos campos de texto.
 *  - relógio ao vivo em DADOS DA SESSÃO.
 */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/* ------------------------------------------------------- categoria */
function category() {
  const st = $('#bug-cat');
  const input = $('#bug-area');
  if (!st || !input) return;

  const slugs = (st.dataset.slugs || '').split('|');
  const icons = (st.dataset.icons || '').split('|');
  const face = $('.bug-stepper__ic', st);
  const idx = $('#bug-cat-idx');

  st.addEventListener('stepper:change', (ev) => {
    const i = ev.detail?.index ?? 0;
    // slugs[0] é o item vazio "SELECIONE A ÁREA": mantém o hidden em branco até o jogador escolher.
    input.value = slugs[i] ?? '';
    st.classList.toggle('is-empty', !input.value);
    if (idx) idx.textContent = `${i === 0 ? '—' : i}/${idx.dataset.total || slugs.length - 1}`;
    if (face && icons[i]) {
      face.textContent = icons[i];
      face.classList.remove('is-changing');
      void face.offsetWidth;
      face.classList.add('is-changing');
    }
  });
}

/* ------------------------------------------------------- contadores */
function counters() {
  $$('[data-count-for]').forEach((out) => {
    const field = document.getElementById(out.dataset.countFor);
    const max = Number(out.dataset.max || 0);
    if (!field || !max) return;
    const fmt = new Intl.NumberFormat('pt-BR');
    const render = () => {
      const n = field.value.length;
      out.textContent = `${fmt.format(n)}/${fmt.format(max)}`;
      out.classList.toggle('is-near', n >= max * 0.85 && n < max);
      out.classList.toggle('is-full', n >= max);
    };
    field.addEventListener('input', render);
    render();
  });
}

/* --------------------------------------------- histórico: meia linha à mostra
   Quando a lista de relatórios não cabe inteira, encurtamos a área de rolagem
   até parar no meio de um item: junto com o fade do .scroll-y e a barra de
   rolagem, fica claro que existe mais conteúdo abaixo. */
function peekList() {
  const list = $('.rep-list');
  if (!list) return;
  const fit = () => {
    list.style.maxHeight = '';
    const items = list.children;
    if (items.length < 2) return;
    const h = list.clientHeight;
    if (list.scrollHeight <= h + 1) return;                    // cabe tudo: nada a fazer
    const step = items[1].offsetTop - items[0].offsetTop;      // altura do item + gap
    if (step <= 0) return;
    const rows = Math.floor(h / step);
    if (rows < 1) return;
    const target = Math.round(rows * step + step * 0.45);
    if (target < h) list.style.maxHeight = `${target}px`;
  };
  fit();
  window.addEventListener('load', fit);        // fontes já carregadas: a altura final pode mudar
  window.addEventListener('resize', fit);
}

/* ----------------------------------------------------------- relógio */
function clock() {
  const el = $('[data-clock]');
  if (!el) return;
  const fmt = new Intl.DateTimeFormat('pt-BR', {
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  });
  const tick = () => { el.textContent = fmt.format(new Date()); };
  tick();
  setInterval(tick, 1000);
}

category();
counters();
clock();
peekList();
