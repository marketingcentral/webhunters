/**
 * Tela SUPORTE — acordeão do FAQ, sincronia do stepper de assunto,
 * contador da descrição e telemetria viva do painel de status.
 * Carregado como type="module" depois de hud.js.
 */
const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
const pad = (n) => String(n).padStart(2, '0');

/* ------------------------------------------------------------- acordeão */
function accordion() {
  const wrap = $('[data-accordion]');
  if (!wrap) return;
  const items = $$('.faq-item', wrap);
  items.forEach((item) => {
    const btn = $('.faq-item__q', item);
    if (!btn) return;
    btn.addEventListener('click', () => {
      const open = item.classList.contains('is-open');
      items.forEach((other) => {
        const b = $('.faq-item__q', other);
        other.classList.toggle('is-open', other === item && !open);
        if (b) b.setAttribute('aria-expanded', other === item && !open ? 'true' : 'false');
      });
    });
  });
}

/* -------------------------------------- stepper de assunto -> categoria */
function subject() {
  const st = $('.sup-stepper[data-slugs]');
  if (!st) return;
  const slugs = (st.dataset.slugs || '').split('|');
  const slas  = (st.dataset.slas || '').split('|');
  const field = $('[data-category]');
  const sla   = $('[data-sla]');
  st.addEventListener('stepper:change', (ev) => {
    const i = ev.detail?.index ?? 0;
    if (field && slugs[i]) field.value = slugs[i];
    if (sla && slas[i]) {
      sla.textContent = slas[i];
      sla.classList.remove('is-changing');
      void sla.offsetWidth;
      sla.classList.add('is-changing');
    }
  });
}

/* ------------------------------------------ medidor de prioridade */
function priority() {
  const steppers = $$('.sup-stepper');
  const st = steppers.find((el) => !el.dataset.slugs);
  const gauge = $('.sup-prio');
  if (!st || !gauge) return;
  st.addEventListener('stepper:change', (ev) => {
    gauge.dataset.prioLevel = String((ev.detail?.index ?? 0) + 1);
  });
}

/* ------------------------------------------------ contador da descrição */
function counter() {
  const area = $('[data-counter]');
  const out  = $('[data-count-out]');
  if (!area || !out) return;
  const fmt = new Intl.NumberFormat('pt-BR');
  const sync = () => { out.textContent = fmt.format(area.value.length); };
  area.addEventListener('input', sync);
  sync();
}

/* ------------------------------------------------ ping / relógio / eta */
function telemetry() {
  const pings = $$('.srv__ping[data-ping]').map((el) => ({
    out: $('[data-ping-out]', el),
    base: Number(el.dataset.ping) || 0,
  })).filter((p) => p.out);

  const elapsed = $('[data-elapsed]');
  const timer = $('[data-countdown]');
  let left = timer ? Number(timer.dataset.countdown) || 0 : 0;
  let secs = 0;

  const renderTimer = () => {
    if (!timer) return;
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    timer.textContent = `${pad(h)}:${pad(m)}:${pad(left % 60)}`;
  };
  renderTimer();

  const tick = () => {
    secs += 1;
    if (left > 0) { left -= 1; renderTimer(); }
    if (elapsed) elapsed.textContent = `${pad(Math.floor(secs / 60))}:${pad(secs % 60)}`;
    if (secs % 3 === 0) {
      pings.forEach((p) => {
        const jitter = Math.round((Math.random() - 0.5) * Math.max(4, p.base * 0.12));
        p.out.textContent = String(Math.max(8, p.base + jitter));
      });
    }
  };

  if (reduced) { if (elapsed) elapsed.textContent = '00:00'; return; }
  setInterval(tick, 1000);
}

accordion();
subject();
priority();
counter();
telemetry();
