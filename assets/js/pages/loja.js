/**
 * EXTRACT HUNTERS — tela LOJA.
 *
 * hud.js já cobre boot/stagger, contadores da carteira e tooltips ([data-tip]).
 * Aqui fica só o que é da tela:
 *   - relógio ao vivo da oferta em destaque ([data-countdown="ISO"])
 *   - COMPRAR: POST real para /loja/comprar por fetch; a carteira, o selo NO STASH
 *     e o bloqueio por saldo são atualizados sem recarregar a página.
 * Sem JavaScript o mesmo <form> faz o POST normal e a tela recarrega já debitada.
 */

let root = null;
const nf = new Intl.NumberFormat('pt-BR');

/* ------------------------------------------------ fim da oferta (ao vivo) */
function countdown() {
  const nodes = Array.from(document.querySelectorAll('.shop [data-countdown]'))
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
      const s = Math.floor((left % 60000) / 1000);
      el.textContent = left > 0 ? `${d}D ${pad(h)}:${pad(m)}:${pad(s)}` : 'ENCERRADA';
    }
  };
  tick();
  const id = window.setInterval(tick, 1000);
  window.addEventListener('pagehide', () => clearInterval(id), { once: true });
}

/* ------------------------------------------------------------- carteira -- */
function readBalance() {
  try {
    return JSON.parse(root.dataset.balance || '{}');
  } catch {
    return {};
  }
}

/* a topbar mostra as mesmas moedas: sem isso o cabeçalho ficaria com o saldo velho
   até a próxima navegação (a partial da topbar não é tocada, só o texto do nó) */
const TOPBAR = { credits: '.topbar__cur--lime .num', cash: '.topbar__cur--cyan .num', points: '.topbar__cur--amber .num' };

function paintBalance(bal) {
  root.dataset.balance = JSON.stringify(bal);
  for (const [cur, sel] of Object.entries(TOPBAR)) {
    const el = document.querySelector(sel);
    if (el && Number.isFinite(Number(bal[cur]))) el.textContent = nf.format(Number(bal[cur]));
  }
  root.querySelectorAll('[data-wallet]').forEach((el) => {
    const v = Number(bal[el.dataset.wallet]);
    if (!Number.isFinite(v)) return;
    const changed = el.textContent !== nf.format(v);
    el.dataset.count = String(v);
    el.textContent = nf.format(v);
    if (changed) {
      el.classList.remove('is-bumped');
      void el.offsetWidth;
      el.classList.add('is-bumped');
    }
  });
}

/** Reavalia quem o jogador ainda consegue pagar (cartões + vitrine). */
function paintAffordable(bal) {
  const buy = root.dataset.ctaBuy || 'COMPRAR';
  const low = root.dataset.ctaLocked || 'SEM SALDO';
  root.querySelectorAll('[data-offer]').forEach((card) => {
    const price = Number(card.dataset.price || 0);
    const cur = card.dataset.currency || 'credits';
    const form = card.querySelector('form[data-buy]');
    if (!form || form.dataset.busy === '1') return;
    const ok = Number(bal[cur] || 0) >= price;
    card.classList.toggle('is-locked', !ok);
    const btn = form.querySelector('[data-buy-btn]');
    const label = form.querySelector('[data-buy-label]');
    if (btn) btn.disabled = !ok;
    if (label) label.textContent = ok ? buy : low;
    const ico = form.querySelector('.ms');
    if (ico) ico.textContent = ok ? 'shopping_cart' : 'lock';
    form.dataset.tip = ok ? (root.dataset.tipBuy || '') : (root.dataset.tipLow || '');
  });
}

/* ---------------------------------------------------------------- flash -- */
function notify(kind, msg) {
  const main = document.querySelector('.hud-main');
  if (!main) return;
  let stack = main.querySelector('.flash-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'flash-stack';
    stack.setAttribute('role', 'status');
    stack.setAttribute('aria-live', 'polite');
    main.prepend(stack);
  }
  const el = document.createElement('div');
  el.className = `flash flash--${kind === 'error' ? 'error' : 'ok'}`;
  el.dataset.flash = '';
  el.innerHTML = `<span class="ms flash__icon" aria-hidden="true">${kind === 'error' ? 'warning' : 'check_circle'}</span><span class="flash__text"></span>`;
  el.querySelector('.flash__text').textContent = msg;
  stack.append(el);
  window.setTimeout(() => {
    el.style.transition = 'opacity 300ms';
    el.style.opacity = '0';
    window.setTimeout(() => el.remove(), 320);
  }, kind === 'error' ? 6000 : 4000);
}

function deny(btn) {
  if (!btn) return;
  btn.classList.remove('is-denied');
  void btn.offsetWidth;
  btn.classList.add('is-denied');
  window.setTimeout(() => btn.classList.remove('is-denied'), 420);
}

/* ---------------------------------------------------------------- compra - */
async function submitBuy(form) {
  if (form.dataset.busy === '1') return;
  const btn = form.querySelector('[data-buy-btn]');
  const label = form.querySelector('[data-buy-label]');
  const card = form.closest('[data-offer]');
  const before = label ? label.textContent : '';

  form.dataset.busy = '1';
  if (btn) btn.disabled = true;
  if (label) label.textContent = root.dataset.ctaBusy || 'COMPRANDO';
  card?.classList.add('is-busy');

  let json = { ok: false, error: 'Falha de rede.' };
  try {
    const res = await fetch(form.action, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json',
      },
      body: new URLSearchParams(new FormData(form)).toString(),
    });
    json = await res.json();
  } catch {
    /* json fica com o erro padrão */
  }

  form.dataset.busy = '0';
  card?.classList.remove('is-busy');
  if (label) label.textContent = before;
  if (btn) btn.disabled = false;

  if (json.ok !== true) {
    deny(btn);
    notify('error', json.error || 'Não foi possível comprar.');
    paintAffordable(readBalance());
    return;
  }

  paintBalance(json.balance || readBalance());
  paintAffordable(json.balance || readBalance());
  notify('ok', json.message || 'Item adquirido.');

  // marca todos os cartões do MESMO item como "no stash" e pisca o comprado
  root.querySelectorAll(`[data-offer="${json.offer_id}"]`).forEach((el) => {
    el.classList.add('is-owned', 'is-bought');
    el.querySelector('[data-owned-badge]')?.removeAttribute('hidden');
    window.setTimeout(() => el.classList.remove('is-bought'), 700);
  });
}

function wireBuy() {
  root.addEventListener('submit', (ev) => {
    const form = ev.target.closest('form[data-buy]');
    if (!form) return;
    ev.preventDefault();
    submitBuy(form);
  });
  // clique num botão já bloqueado: dá o feedback de recusa (o botão está disabled,
  // então o evento chega pelo formulário em volta)
  root.addEventListener('click', (ev) => {
    const form = ev.target.closest('form[data-buy]');
    if (!form) return;
    const btn = form.querySelector('[data-buy-btn]');
    if (btn && btn.disabled && form.dataset.busy !== '1') deny(btn);
  });
}

function init() {
  countdown();
  root = document.querySelector('[data-shop]');
  if (!root) return;
  wireBuy();
  paintAffordable(readBalance());
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
