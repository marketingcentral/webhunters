/**
 * Tela MENSAGENS — troca de conversa (sem recarregar), filtro por pasta/busca,
 * marcação de lida no banco e contagem regressiva da manutenção.
 * A resposta é um POST real (form) tratado pelo MessagesController.
 */

const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const list    = $('[data-conv-list]');
const convs   = $$('.conv');
const threads = $$('.thread');
const empty   = $('[data-conv-empty]');
const counter = $('[data-conv-count]');
const newCnt  = $('[data-conv-new]');
const newLbl  = $('[data-conv-new-label]');
const convIn  = $('[data-reply-conv]');
const form    = $('[data-reply-form]');
const base    = (document.body.dataset.base || '/').replace(/\/?$/, '/');
const token   = ($('meta[name="csrf-token"]') || {}).content || '';

/* --------------------------------------------------------- conversa aberta */
function scrollToEnd(thread) {
  const box = thread && thread.querySelector('[data-thread-scroll]');
  if (box) box.scrollTop = box.scrollHeight;
}

function headUnread(n) {
  const total = $('.msgs-head__unread .num');
  const label = $('[data-unread-label]');
  if (total) total.textContent = String(n);
  if (label) label.textContent = n === 1 ? 'NÃO LIDA' : 'NÃO LIDAS';
}

/** Persiste a leitura no banco e devolve os contadores recalculados. */
function markRead(id) {
  if (!token) return;
  const body = new URLSearchParams({ _csrf: token, conversa: id });
  fetch(base + 'mensagens/lida', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token },
    body,
    credentials: 'same-origin',
  })
    .then((r) => (r.ok ? r.json() : null))
    .then((j) => {
      if (!j || !j.ok) return;
      headUnread(j.unread);
      applyFilters();
    })
    .catch(() => { /* offline: a lista já foi atualizada no cliente */ });
}

/**
 * Abre uma conversa.
 *   read=false  → a abertura NÃO foi um clique do jogador (é consequência de trocar de
 *                 pasta/busca): a conversa continua não lida, nada é gravado no banco e a
 *                 URL não muda — recarregar não pode marcar como lida algo que ninguém abriu.
 */
function openConv(id, { focus = false, read = true } = {}) {
  let wasUnread = false;
  convs.forEach((c) => {
    const on = c.dataset.conv === id;
    c.classList.toggle('is-active', on);
    c.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (on && read && c.classList.contains('is-unread')) {
      wasUnread = true;
      c.classList.remove('is-unread');
    }
  });
  threads.forEach((t) => {
    const on = t.dataset.thread === id;
    t.hidden = !on;
    if (on) {
      t.style.animation = 'none';
      void t.offsetWidth;
      t.style.animation = '';
      scrollToEnd(t);
    }
  });
  if (convIn) convIn.value = id;
  if (read) {
    try { history.replaceState(null, '', base + 'mensagens/' + id); } catch (_) { /* file:// */ }
    if (wasUnread) markRead(id);
  }
  if (focus) {
    const input = $('[data-reply-input]');
    if (input) input.focus({ preventScroll: true });
  }
}

if (list) {
  list.addEventListener('click', (e) => {
    const btn = e.target.closest('.conv');
    if (!btn || !btn.dataset.conv) return;
    openConv(btn.dataset.conv);
    headUnread(convs.filter((c) => c.classList.contains('is-unread')).length);
    applyFilters();
  });
}

/* ------------------------------------------------------- filtros e busca */
const tabs   = $$('[data-conv-tabs] .conv-tab');
const search = $('[data-conv-search]');
let folder = (tabs.find((t) => t.classList.contains('is-active')) || {}).dataset?.folder || 'entrada';

function applyFilters() {
  const q = (search && search.value || '').trim().toLowerCase();
  let shown = 0;
  let fresh = 0;
  convs.forEach((c) => {
    const inFolder = folder === 'sistema'
      ? c.dataset.system === '1'
      : c.dataset.folder === folder;
    const match = !q || (c.dataset.search || '').includes(q);
    const on = inFolder && match;
    c.parentElement.hidden = !on;
    if (on) {
      shown += 1;
      if (c.classList.contains('is-unread')) fresh += 1;
    }
  });
  if (empty) {
    empty.hidden = shown !== 0;
    const txt = $('[data-empty-text]', empty);
    if (txt) {
      txt.textContent = q
        ? (empty.dataset.emptySearch || txt.textContent)
        : (empty.dataset.emptyFolder || txt.textContent);
    }
  }
  // a lista sai do fluxo quando não sobra linha: assim o vazio fica centrado no painel
  if (list) list.hidden = shown === 0;
  if (counter) counter.textContent = String(shown);
  if (newCnt) newCnt.textContent = String(fresh);
  if (newLbl) newLbl.textContent = fresh === 1 ? 'NOVA' : 'NOVAS';
  const active = convs.find((c) => c.classList.contains('is-active'));
  if (active && active.parentElement.hidden) {
    const first = convs.find((c) => !c.parentElement.hidden);
    // troca só o painel: o jogador não clicou nessa conversa, então ela segue não lida
    if (first) openConv(first.dataset.conv, { read: false });
  }
}

tabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    tabs.forEach((t) => {
      t.classList.toggle('is-active', t === tab);
      t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
    });
    folder = tab.dataset.folder;
    applyFilters();
  });
});
if (search) search.addEventListener('input', applyFilters);

/* ------------------------------------------------------------- resposta */
/* O envio é POST normal (CSRF + gravação no banco). Aqui só evitamos mandar
   um campo vazio e garantimos que a conversa aberta viaja junto. */
if (form) {
  const input = $('[data-reply-input]', form);
  const send  = $('[data-reply-send]', form);
  const hint  = $('[data-reply-hint]', form);
  let hintTimer = 0;

  const filled = () => !!(input && input.value.trim());

  /** Aviso curto e visível quando não há o que enviar. */
  function warn() {
    if (!hint) return;
    hint.hidden = false;
    hint.classList.remove('is-on');
    void hint.offsetWidth;
    hint.classList.add('is-on');
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => { hint.hidden = true; }, 3200);
  }

  function sync() {
    if (send) send.disabled = !filled();
    if (filled() && hint && !hint.hidden) { clearTimeout(hintTimer); hint.hidden = true; }
  }

  if (input) input.addEventListener('input', sync);
  form.addEventListener('submit', (e) => {
    const text = (input && input.value || '').trim();
    const open = threads.find((t) => !t.hidden);
    if (convIn && open) convIn.value = open.dataset.thread;
    if (!text) {
      e.preventDefault();
      warn();
      if (input) input.focus();
      return;
    }
    if (input) input.value = text;
  });
  sync();
}

/* -------------------------------------------------- contagem regressiva */
const clock = $('[data-countdown]');
if (clock) {
  let left = Number(clock.dataset.countdown) || 0;
  const two = (n) => String(n).padStart(2, '0');
  const tick = () => {
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    const s = left % 60;
    clock.textContent = two(h) + ':' + two(m) + ':' + two(s);
    if (left > 0) left -= 1;
  };
  tick();
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) setInterval(tick, 1000);
}

/* ------------------------------------------------------------- inicial */
applyFilters();
threads.forEach((t) => { if (!t.hidden) scrollToEnd(t); });
window.addEventListener('load', () => {
  threads.forEach((t) => { if (!t.hidden) scrollToEnd(t); });
});
