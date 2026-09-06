/**
 * EXTRACT HUNTERS — HUD behaviours (ES module, loaded with type="module").
 *
 *  - boot: body.is-booted after fonts ready; stagger --i on .panel/[data-boot]/[data-stagger] children
 *  - tabs: .tabs .tab click -> is-active, shows matching [data-tab-panel]
 *  - steppers: .stepper[data-options="A|B|C"] cycles, updates .stepper__val (+ hidden input)
 *  - toggles: .toggle updates .toggle__label (data-on / data-off, default ATIVADO/DESATIVADO)
 *  - ranges: input.range writes value to .range__val and sets --v for the fill
 *  - selection groups: [data-select-group] toggles .is-active (.row/.btn) or .is-selected (.card/.slot)
 *  - counters: [data-count="12456"] tween 0 -> target, pt-BR formatting (data-dec, data-prefix, data-suffix)
 *  - page transition: same-origin links fade .page-transition in before navigating
 *  - tooltip: [data-tip="texto"]
 *  - carousels: delegated to carousel.js
 */
import { initCarousels } from './carousel.js';
import spa from './spa.js';

const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const fmt = new Map();
const numFmt = (dec = 0) => {
  if (!fmt.has(dec)) fmt.set(dec, new Intl.NumberFormat('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec }));
  return fmt.get(dec);
};
const easeOut = (t) => 1 - Math.pow(1 - t, 3);

/* ---------------------------------------------------------------- boot */
function stagger() {
  let i = 0;
  const seen = new Set();
  $$('.panel, [data-boot]').forEach((el) => {
    if (seen.has(el)) return;
    seen.add(el);
    if (!el.style.getPropertyValue('--i')) el.style.setProperty('--i', String(i));
    i++;
  });
  $$('[data-stagger]').forEach((wrap) => {
    Array.from(wrap.children).forEach((child, k) => child.style.setProperty('--i', String(k)));
  });
}

function marcarBooted() {
  document.body.classList.add('is-booted');
  document.dispatchEvent(new CustomEvent('hud:booted'));
  counters();
}

async function boot() {
  stagger();
  /*
   * Caminho da troca sem recarga: as fontes já carregaram na primeira tela, então marca
   * agora, no MESMO quadro em que o miolo foi trocado. Esperar um requestAnimationFrame
   * aqui deixaria o navegador pintar uma vez sem `is-booted` — o conteúdo apareceria
   * pronto e só então saltaria para o começo da animação de entrada. Um quadro só, mas
   * visível como um piscar.
   */
  if (document.fonts && document.fonts.status === 'loaded') { marcarBooted(); return; }

  const fontsReady = document.fonts?.ready ?? Promise.resolve();
  await Promise.race([fontsReady, new Promise((r) => setTimeout(r, 700))]);
  requestAnimationFrame(marcarBooted);
}

/* ---------------------------------------------------------------- tabs */
function tabs() {
  $$('.tabs').forEach((nav) => {
    const tabsEl = $$('.tab', nav);
    if (!tabsEl.length) return;
    nav.setAttribute('role', 'tablist');
    tabsEl.forEach((t) => { t.setAttribute('role', 'tab'); t.setAttribute('aria-selected', t.classList.contains('is-active') ? 'true' : 'false'); if (!t.hasAttribute('tabindex')) t.tabIndex = 0; });
    const activate = (tab) => {
      tabsEl.forEach((t) => { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
      const target = tab.dataset.tab;
      if (target) {
        const scope = nav.closest('[data-tabs-scope]') || document;
        $$('[data-tab-panel]', scope).forEach((p) => {
          const match = p.dataset.tabPanel === target;
          p.hidden = !match;
          if (match) { p.classList.remove('is-shown'); void p.offsetWidth; p.classList.add('is-shown'); }
        });
      }
      nav.dispatchEvent(new CustomEvent('tab:change', { bubbles: true, detail: { tab, target } }));
    };
    nav.addEventListener('click', (e) => {
      const tab = e.target.closest('.tab');
      if (!tab || !nav.contains(tab)) return;
      const href = tab.getAttribute('href');
      if (tab.dataset.tab || !href || href === '#') { e.preventDefault(); activate(tab); }
    });
    nav.addEventListener('keydown', (e) => {
      if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
      const cur = tabsEl.findIndex((t) => t.classList.contains('is-active'));
      const next = tabsEl[(cur + (e.key === 'ArrowRight' ? 1 : -1) + tabsEl.length) % tabsEl.length];
      next.focus(); if (next.dataset.tab || !next.getAttribute('href')) activate(next);
    });
    const active = tabsEl.find((t) => t.classList.contains('is-active') && t.dataset.tab);
    if (active) {
      const scope = nav.closest('[data-tabs-scope]') || document;
      $$('[data-tab-panel]', scope).forEach((p) => { p.hidden = p.dataset.tabPanel !== active.dataset.tab; });
    }
  });
}

/* ------------------------------------------------------------ steppers */
function parseOptions(raw) {
  if (!raw) return [];
  const s = raw.trim();
  if (s.startsWith('[')) { try { return JSON.parse(s).map(String); } catch { /* fallthrough */ } }
  return s.split('|').map((v) => v.trim()).filter(Boolean);
}
function steppers() {
  $$('.stepper').forEach((st) => {
    const opts = parseOptions(st.dataset.options);
    const val = $('.stepper__val', st);
    const input = $('input[type="hidden"]', st);
    const btns = $$('.stepper__btn', st);
    if (!val || btns.length < 2) return;
    let idx = Math.max(0, opts.indexOf(st.dataset.value ?? val.textContent.trim()));
    if (!opts.length) { opts.push(val.textContent.trim()); idx = 0; }
    const loop = st.dataset.loop !== 'false';
    const render = () => {
      val.textContent = opts[idx];
      if (input) input.value = opts[idx];
      st.dataset.value = opts[idx];
      if (!loop) { btns[0].disabled = idx === 0; btns[btns.length - 1].disabled = idx === opts.length - 1; }
      val.classList.remove('is-changing'); void val.offsetWidth; val.classList.add('is-changing');
      st.dispatchEvent(new CustomEvent('stepper:change', { bubbles: true, detail: { value: opts[idx], index: idx } }));
    };
    const step = (d) => {
      // relê o valor atual do DOM: outra tela (ex.: os ladrilhos de predefinição em
      // configuracoes.js) pode ter escrito dataset.value direto, e um índice preso no
      // closure faria o próximo clique pular para a opção errada.
      const cur = opts.indexOf(st.dataset.value ?? '');
      if (cur >= 0) idx = cur;
      const n = idx + d;
      idx = loop ? (n + opts.length) % opts.length : Math.min(opts.length - 1, Math.max(0, n));
      render();
    };
    btns[0].addEventListener('click', (e) => { e.preventDefault(); step(-1); });
    btns[btns.length - 1].addEventListener('click', (e) => { e.preventDefault(); step(1); });
    btns.forEach((b) => { if (!b.hasAttribute('type')) b.type = 'button'; });
    if (!loop) { btns[0].disabled = idx === 0; btns[btns.length - 1].disabled = idx === opts.length - 1; }
  });
}

/* ------------------------------------------------------------- toggles */
function toggles() {
  $$('.toggle').forEach((t) => {
    const input = $('input[type="checkbox"]', t);
    const label = $('.toggle__label', t);
    if (!input || !label) return;
    const on = t.dataset.on || label.dataset.on || 'ATIVADO';
    const off = t.dataset.off || label.dataset.off || 'DESATIVADO';
    const render = () => { label.textContent = input.checked ? on : off; };
    input.addEventListener('change', () => {
      render();
      t.dispatchEvent(new CustomEvent('toggle:change', { bubbles: true, detail: { checked: input.checked } }));
    });
    if (!label.textContent.trim()) render();
  });
}

/* -------------------------------------------------------------- ranges */
function ranges() {
  $$('input[type="range"].range').forEach((r) => {
    const wrap = r.closest('.range-wrap') || r.parentElement;
    const out = (r.nextElementSibling?.classList.contains('range__val') && r.nextElementSibling)
      || (wrap && $('.range__val', wrap))
      || (r.dataset.out && $(r.dataset.out));
    const suffix = r.dataset.suffix ?? '%';
    const dec = Number(r.dataset.dec || 0);
    const render = () => {
      const min = Number(r.min || 0), max = Number(r.max || 100), v = Number(r.value);
      const pct = max > min ? ((v - min) / (max - min)) * 100 : 0;
      r.style.setProperty('--v', pct.toFixed(2) + '%');
      if (out) out.textContent = numFmt(dec).format(v) + suffix;
    };
    r.addEventListener('input', render);
    render();
  });
}

/* ----------------------------------------------------- select groups */
function selectGroups() {
  $$('[data-select-group]').forEach((group) => {
    const single = group.dataset.selectGroup !== 'multi';
    group.addEventListener('click', (e) => {
      const item = e.target.closest('.row, .card, .slot, .btn, [data-select]');
      if (!item || !group.contains(item) || item.classList.contains('is-locked') || item.hasAttribute('disabled')) return;
      if (e.target.closest('a[href]:not([href="#"]), button:not([data-select]), input, label')) {
        if (!(e.target.closest('a[href]') === item && item.getAttribute('href') === '#')) return;
      }
      const cls = item.classList.contains('card') || item.classList.contains('slot') ? 'is-selected' : 'is-active';
      if (item.tagName === 'A' && (item.getAttribute('href') || '#') === '#') e.preventDefault();
      if (single) {
        $$('.is-active, .is-selected', group).forEach((el) => { if (el !== item) { el.classList.remove('is-active', 'is-selected'); el.setAttribute('aria-pressed', 'false'); } });
        item.classList.add(cls);
      } else {
        item.classList.toggle(cls);
      }
      item.setAttribute('aria-pressed', item.classList.contains(cls) ? 'true' : 'false');
      const input = group.dataset.selectInput ? $(group.dataset.selectInput) : $('input[type="hidden"]', group);
      if (input && item.dataset.value !== undefined) input.value = item.dataset.value;
      group.dispatchEvent(new CustomEvent('select:change', { bubbles: true, detail: { item, value: item.dataset.value } }));
    });
  });
}

/* ------------------------------------------------------------ counters */
function counters() {
  $$('[data-count]').forEach((el) => {
    const target = Number(String(el.dataset.count).replace(',', '.'));
    if (!Number.isFinite(target)) return;
    const dec = Number(el.dataset.dec || 0);
    const prefix = el.dataset.prefix || '', suffix = el.dataset.suffix || '';
    const dur = Number(el.dataset.dur || 900);
    const f = numFmt(dec);
    if (reduced || dur <= 0) { el.textContent = prefix + f.format(target) + suffix; return; }
    const t0 = performance.now();
    const tick = (now) => {
      const p = Math.min(1, (now - t0) / dur);
      el.textContent = prefix + f.format(target * easeOut(p)) + suffix;
      if (p < 1) requestAnimationFrame(tick); else el.textContent = prefix + f.format(target) + suffix;
    };
    requestAnimationFrame(tick);
  });
}

/* ------------------------------------------------------ page transition */
/*
 * Caminho SEM navegação sem recarga: tela de login, criar conta, 404. Aqui o documento
 * troca de verdade, e a espera pelo servidor é justamente a que deixava a tela preta.
 * Então além do fade, sobe o MESMO preloader animado do spa.js e deixa ele lá até o
 * navegador entregar a página nova.
 *
 * O submit dos formulários (entrar, criar conta) também: é a espera mais longa dessas
 * telas, porque envolve verificar senha no banco.
 */
function pageTransition() {
  const overlay = $('.page-transition');
  const sair = () => {
    document.body.classList.add('is-leaving');
    if (overlay) overlay.classList.add('is-active');
    spa.preloaderAteSair();
  };

  window.addEventListener('pageshow', () => {
    // Voltar pelo histórico reaproveita o documento: desfaz o estado de saída.
    if (overlay) overlay.classList.remove('is-active');
    document.body.classList.remove('is-leaving');
    const carga = $('.spa-load');
    if (carga) carga.classList.remove('is-on');
  });

  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a[href]');
    if (!a) return;
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download') || a.hasAttribute('data-no-transition')) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
    let url;
    try { url = new URL(a.href, location.href); } catch { return; }
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
    e.preventDefault();
    sair();
    // Sem animação: sai na hora. Com animação: dá tempo do fade aparecer.
    setTimeout(() => { location.href = url.href; }, reduced ? 0 : 200);
  }, { capture: false });

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented) return;
    if (form.hasAttribute('data-no-transition')) return;
    sair();   // o POST segue normalmente; só marcamos que estamos saindo
  });
}

/* ------------------------------------------------------------- tooltip */
function tooltip() {
  let tip;
  let current = null;
  const ensure = () => { if (!tip) { tip = document.createElement('div'); tip.className = 'tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); } return tip; };
  const show = (el) => {
    const text = el.dataset.tip;
    if (!text) return;
    const t = ensure();
    t.textContent = text;
    current = el;
    t.classList.remove('is-below');
    t.style.left = '0px'; t.style.top = '0px';
    t.classList.add('is-visible');
    const r = el.getBoundingClientRect();
    const tr = t.getBoundingClientRect();
    let x = r.left + r.width / 2 - tr.width / 2;
    let y = r.top - tr.height - 10;
    if (y < 8) { y = r.bottom + 10; t.classList.add('is-below'); }
    x = Math.max(8, Math.min(window.innerWidth - tr.width - 8, x));
    t.style.left = `${Math.round(x)}px`;
    t.style.top = `${Math.round(y)}px`;
  };
  const hide = () => { if (tip) tip.classList.remove('is-visible'); current = null; };
  document.addEventListener('mouseover', (e) => { const el = e.target.closest('[data-tip]'); if (el && el !== current) show(el); });
  document.addEventListener('mouseout', (e) => { const el = e.target.closest('[data-tip]'); if (el && !el.contains(e.relatedTarget)) hide(); });
  document.addEventListener('focusin', (e) => { const el = e.target.closest('[data-tip]'); if (el) show(el); });
  document.addEventListener('focusout', hide);
  window.addEventListener('scroll', hide, { passive: true, capture: true });
}

/* ----------------------------------------------------------- flash auto */
function flashes() {
  $$('.flash[data-autohide]').forEach((f) => {
    const ms = Number(f.dataset.autohide || 4000);
    setTimeout(() => { f.style.transition = 'opacity 300ms'; f.style.opacity = '0'; setTimeout(() => f.remove(), 320); }, ms);
  });
}

/* -------------------------------------------------------------- pressed */
function pressed() {
  document.addEventListener('pointerdown', (e) => {
    const b = e.target.closest('.btn, .menu-btn, .row, .card, .slot, .tab');
    if (!b) return;
    b.classList.add('is-pressed');
    const up = () => { b.classList.remove('is-pressed'); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); };
    window.addEventListener('pointerup', up); window.addEventListener('pointercancel', up);
  });
}

/* ---------------------------------------------------------------- init */
/*
 * Duas metades, por causa da navegação sem recarga (spa.js):
 *
 *   initGlobal() — delegação em document/window. Vale para o site inteiro, roda UMA vez.
 *                  Rodar de novo duplicaria o listener a cada tela.
 *   initPage()   — amarra em elementos que existem dentro do <main>/topbar/footer. Esses
 *                  elementos são substituídos a cada navegação, então precisa rodar de novo.
 *
 * Se o spa não instalar (tela de login, HUD in-game), o pageTransition() antigo assume:
 * o fade continua acontecendo, só que seguido de navegação normal.
 */
function initGlobal() {
  tooltip();
  pressed();
}

function initPage() {
  tabs();
  steppers();
  toggles();
  ranges();
  selectGroups();
  flashes();
  initCarousels();
  boot();
}

function arrancar() {
  initGlobal();
  if (spa.instalar()) {
    spa.aoTrocar(initPage);
  } else {
    pageTransition();
  }
  initPage();
  // Fecha o casco: daqui para a frente, timers e listeners pertencem a uma tela e são
  // varridos quando ela sai. Módulo é `defer`, então readyState nunca é 'loading' aqui e
  // isto acontece antes de qualquer módulo de página ser avaliado.
  spa.abrirEpoca();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar, { once: true });
else arrancar();

export { initGlobal, initPage, counters, stagger, numFmt };
