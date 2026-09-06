/**
 * Tela HABILIDADES — seleção de nó da árvore, "segurar para aprender" e atalhos.
 * Carregado como módulo depois de hud.js. Sem dependências.
 *
 * A fonte da verdade continua sendo o servidor: os botões são <button type="submit">
 * de formulários POST reais. O JS apenas troca o painel de detalhe sem recarregar a
 * página e mantém o estado do botão APRENDER coerente com os pontos do jogador.
 */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

const grid = $('.tree-grid');
const name = $('#sk-name');
const level = $('#sk-level');
const cost = $('#sk-cost');
const desc = $('#sk-desc');
const next = $('#sk-next');
const shot = $('#sk-img');
const slugInput = $('#sk-slug');
const learnBtn = $('#sk-learn');
const holdBtn = $('#sk-hold');
const whyEl = $('#sk-why');
const pointsBox = $('.points');
const POINTS = Number(pointsBox?.dataset.points || 0);

/* Escreve um texto realçando o primeiro valor numérico (ex.: "12%") em lime. */
function write(el, text) {
  if (!el) return;
  el.textContent = '';
  const m = /\d+(?:[.,]\d+)?%?/.exec(text || '');
  if (!m) { el.textContent = text || ''; return; }
  el.append(document.createTextNode(text.slice(0, m.index)));
  const hi = document.createElement('span');
  hi.className = 'lime';
  hi.textContent = m[0];
  el.append(hi, document.createTextNode(text.slice(m.index + m[0].length)));
}

function swap(el) {
  if (!el) return;
  el.classList.remove('sk-swap');
  void el.offsetWidth;
  el.classList.add('sk-swap');
}

/* Mesma regra do SkillsController::detail() — servidor e cliente não podem divergir. */
function nextText(cur, max) {
  if (cur >= max) return 'Habilidade já está no nível máximo.';
  return cur === 0
    ? `Desbloqueia o nível 1 de ${max} desta habilidade.`
    : `Evolui para o nível ${cur + 1} de ${max} desta habilidade.`;
}

function reason(cur, max, available, price) {
  if (cur >= max) return 'Nível máximo atingido.';
  if (!available) return 'Requer a habilidade do tier anterior nesta coluna.';
  if (POINTS < price) return 'Pontos de habilidade insuficientes.';
  return '';
}

function setActions(slug, cur, max, price, available) {
  if (slugInput) slugInput.value = slug;
  const why = reason(cur, max, available, price);
  const can = why === '';
  [learnBtn, holdBtn].forEach((b) => {
    if (!b) return;
    b.disabled = !can;
    b.setAttribute('aria-disabled', can ? 'false' : 'true');
    b.title = can ? (b === learnBtn ? 'Aprender habilidade' : 'Segure para confirmar') : why;
  });
  if (whyEl) {
    whyEl.textContent = why;
    whyEl.hidden = can;
  }
}

function select(node, push = true) {
  if (!node) return;
  $$('.sk-node.is-selected', grid).forEach((n) => {
    n.classList.remove('is-selected');
    n.setAttribute('aria-pressed', 'false');
  });
  node.classList.add('is-selected');
  node.setAttribute('aria-pressed', 'true');

  const cur = Number(node.dataset.cur || 0);
  const max = Number(node.dataset.max || 1);
  const price = Number(node.dataset.cost || 0);
  const available = node.dataset.available !== '0';

  if (name) name.textContent = node.dataset.name || '';
  if (level) {
    level.textContent = '';
    level.append(String(cur));
    const sep = document.createElement('i');
    sep.className = 'num-sep';
    sep.textContent = ' / ';
    level.append(sep, String(max));
  }
  if (cost) cost.textContent = String(price);
  write(desc, node.dataset.desc || '');
  write(next, nextText(cur, max));
  setActions(node.dataset.node || '', cur, max, price, available);

  [name, level, desc, next, shot].forEach(swap);

  /* mantém o nó na URL: recarregar (ou voltar de um POST) devolve o mesmo detalhe */
  if (push && node.dataset.node) {
    const u = new URL(window.location.href);
    u.searchParams.set('hab', node.dataset.node);
    window.history.replaceState(null, '', u);
  }
}

if (grid) {
  grid.addEventListener('click', (ev) => {
    const node = ev.target.closest('.sk-node');
    if (node) select(node);
  });
}

/* "SEGURAR PARA APRENDER": só envia o formulário depois de 1,1s pressionado. */
if (holdBtn) {
  const HOLD = 1100;
  let timer = 0;
  let sent = false;

  const start = (ev) => {
    if (holdBtn.disabled || sent) return;
    if (ev && ev.type === 'pointerdown' && ev.button !== 0) return;
    holdBtn.classList.add('is-holding');
    clearTimeout(timer);
    timer = window.setTimeout(() => {
      if (sent || holdBtn.disabled) return;
      sent = true;                                  // um único POST por gesto
      holdBtn.classList.remove('is-holding');
      holdBtn.form?.requestSubmit(holdBtn);
    }, HOLD);
  };
  const stop = () => { clearTimeout(timer); holdBtn.classList.remove('is-holding'); };

  holdBtn.addEventListener('pointerdown', start);
  holdBtn.addEventListener('pointerup', stop);
  holdBtn.addEventListener('pointerleave', stop);
  holdBtn.addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); start(e); } });
  holdBtn.addEventListener('keyup', stop);
  /* o clique nativo NUNCA envia: só o temporizador do "hold" chama requestSubmit,
     senão soltar o botão depois do envio dispararia um segundo POST. */
  holdBtn.addEventListener('click', (e) => e.preventDefault());
}

/* Atalhos mostrados nos chips: F aprende, R redefine a categoria. */
const press = (el) => {
  if (!el || el.disabled) return;
  el.classList.add('is-pressed');
  el.focus({ preventScroll: true });
  setTimeout(() => {
    el.classList.remove('is-pressed');
    el.form?.requestSubmit(el);
  }, 180);
};
document.addEventListener('keydown', (ev) => {
  if (ev.altKey || ev.ctrlKey || ev.metaKey) return;
  const tag = (ev.target && ev.target.tagName) || '';
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  const k = ev.key.toLowerCase();
  if (k === 'f') press(learnBtn);
  else if (k === 'r') press($('.key-btn--rev'));
});
