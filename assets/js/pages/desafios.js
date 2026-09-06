/**
 * Tela DESAFIOS — comportamentos locais.
 *
 *  - [data-countdown="45296"] : segundos restantes vindos do servidor (prazos reais do
 *    banco). O relógio desconta 1s por segundo e reformata, então a contagem é VIVA:
 *    abaixo de 24h aparecem os segundos ("7h 12m 09s"), acima aparecem os minutos.
 *  - o relógio do cabeçalho ([data-clock]) segue a aba ativa: cada aba tem um prazo
 *    ABSOLUTO calculado uma única vez no boot (BOOT + data-reset), então trocar de aba e
 *    voltar nunca faz o contador andar para trás.
 *  - a URL acompanha a aba (history.replaceState -> /desafios/{aba}), então recarregar ou
 *    copiar o link cai na mesma aba, agora servida pelo servidor.
 *
 * Sem JS a tela continua correta: a view já imprime o texto inicial de cada prazo e cada
 * aba tem rota própria.
 */

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Instante único de referência do boot. TODO prazo desta tela vira um instante ABSOLUTO
 * (BOOT + segundos do servidor) calculado uma só vez. Nada nesta tela pode reconverter
 * "segundos restantes" do HTML em prazo no momento do clique: o HTML é um retrato do
 * servidor no render, e reancorá-lo em Date.now() empurraria o prazo para o futuro
 * exatamente o tanto de tempo que o jogador passou na página (o relógio andaria para trás).
 */
const BOOT = Date.now();

/* ---- parsing / formatação ----------------------------------------------- */
function parseSpan(text) {
  const t = String(text || '').trim();
  if (/^\d+$/.test(t)) return +t;                       // segundos crus (servidor)
  const d = /(\d+)\s*d/i.exec(t);
  const h = /(\d+)\s*h/i.exec(t);
  const m = /(\d+)\s*m(?!s)/i.exec(t);
  const s = /(\d+)\s*s(?!\w)/i.exec(t);
  if (!d && !h && !m && !s) return null;
  return (d ? +d[1] * 86400 : 0) + (h ? +h[1] * 3600 : 0) + (m ? +m[1] * 60 : 0) + (s ? +s[1] : 0);
}

const pad = (n) => String(n).padStart(2, '0');

/** Mesmo formato do PHP em pages/desafios.php — servidor e cliente não podem divergir. */
function formatSpan(total) {
  const t = Math.max(0, Math.floor(total));
  if (t >= 86400) {
    return `${Math.floor(t / 86400)}d ${pad(Math.floor((t % 86400) / 3600))}h ${pad(Math.floor((t % 3600) / 60))}m`;
  }
  if (t >= 3600) {
    return `${Math.floor(t / 3600)}h ${pad(Math.floor((t % 3600) / 60))}m ${pad(t % 60)}s`;
  }
  return `${Math.floor(t / 60)}m ${pad(t % 60)}s`;
}

/* ---- relógios ------------------------------------------------------------
 * Ancorados no relógio do navegador (deadline absoluto), não em decrementos: uma aba em
 * segundo plano ou um tick perdido não faz o contador atrasar.
 */
const clocks = [];

function register(el) {
  const left = parseSpan(el.dataset.countdown || el.textContent);
  if (left === null || left <= 0) return null;
  const entry = { el, deadline: BOOT + left * 1000 };
  clocks.push(entry);
  el.textContent = formatSpan(left);
  return entry;
}

function tick() {
  const now = Date.now();
  for (const c of clocks) {
    const left = Math.max(0, Math.round((c.deadline - now) / 1000));
    const next = formatSpan(left);
    if (c.el.textContent !== next) c.el.textContent = next;
  }
}

function startClocks() {
  document.querySelectorAll('[data-countdown]').forEach(register);
  if (!clocks.length) return;
  window.setInterval(tick, 1000);
}

/* ---- o relógio do cabeçalho acompanha a aba ativa ------------------------ */
function headClock() {
  const value = document.querySelector('[data-clock]');
  const label = document.querySelector('[data-clock-label]');
  const tabs = document.querySelector('.des-tabs');
  if (!value || !tabs) return;

  const entry = clocks.find((c) => c.el === value);

  // Um prazo absoluto por aba, FIXADO no boot a partir do retrato do servidor
  // (data-reset). Trocar de aba só aponta o relógio para um prazo já calculado — nunca
  // recalcula a partir de Date.now(), senão o contador andaria para trás.
  const deadlines = new WeakMap();
  tabs.querySelectorAll('.tab').forEach((t) => {
    const secs = parseSpan(t.dataset.reset || '');
    if (secs !== null) deadlines.set(t, BOOT + secs * 1000);
  });

  tabs.addEventListener('tab:change', (e) => {
    const tab = e.detail && e.detail.tab;
    if (!tab) return;

    const deadline = deadlines.get(tab);
    if (label && tab.dataset.resetLabel) label.textContent = tab.dataset.resetLabel;
    if (deadline !== undefined) {
      if (entry) entry.deadline = deadline;
      value.textContent = formatSpan(Math.max(0, Math.round((deadline - Date.now()) / 1000)));
    }

    // a rota segue a aba: /desafios/{aba} é servida pelo servidor com a aba já ativa
    const url = tab.dataset.tabUrl;
    if (url && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', url);
    }

    if (reduced) return;
    value.animate(
      [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'translateY(0)' }],
      { duration: 260, easing: 'cubic-bezier(.16,1,.3,1)' },
    );
  });
}

/* ---- barras: reanimam quando a aba aparece ------------------------------- */
function replayBars() {
  const scope = document.querySelector('[data-tabs-scope]');
  if (!scope || reduced) return;
  scope.addEventListener('tab:change', () => {
    const pane = scope.querySelector('[data-tab-panel]:not([hidden])');
    if (!pane) return;
    pane.querySelectorAll('.progress--anim .progress__bar').forEach((bar) => {
      bar.style.animation = 'none';
      void bar.offsetWidth;
      bar.style.animation = '';
    });
  });
}

/* ---- resgate do bônus: evita clique duplo no envio ----------------------- */
function bonusForms() {
  document.querySelectorAll('form[data-bonus]').forEach((form) => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
    });
  });
}

startClocks();
headClock();
replayBars();
bonusForms();
