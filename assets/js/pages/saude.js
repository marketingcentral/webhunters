/**
 * Tela SAÚDE — interações locais (mock, sem rede).
 *  - USAR: consome o item, registra o tratamento em ÚLTIMOS TRATAMENTOS e pulsa a saúde.
 *  - REALIZAR: roda o exame (varredura) e mostra o resultado no cartão.
 *  - Teclas X / H acionam os botões de detalhe do corpo e histórico.
 *  - Efeitos ativos (aba STATUS) contam para trás ao vivo.
 */
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

const root = document.querySelector('.saude');
if (root) {
  const pad = (n) => String(n).padStart(2, '0');
  const clock = () => { const d = new Date(); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };

  /* ------------------------------------------------ itens de cura */
  const hpEl = $('#sd-hp');
  const vital = $('[data-vital="health"]');
  const hist = $('#sd-hist');

  const pulseHealth = () => {
    [hpEl, vital && $('[data-vital-val]', vital)].forEach((el) => {
      if (!el || reduced) return;
      el.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.09)' }, { transform: 'scale(1)' }],
        { duration: 460, easing: 'cubic-bezier(.16,1,.3,1)' },
      );
    });
    const bar = vital && $('.progress__bar', vital);
    if (bar && !reduced) {
      bar.animate(
        [{ filter: 'brightness(1)' }, { filter: 'brightness(1.9)' }, { filter: 'brightness(1)' }],
        { duration: 620, easing: 'ease-out' },
      );
    }
  };

  const logTreatment = (name, imgSrc, heal) => {
    if (!hist) return;
    const li = document.createElement('li');
    li.className = 'hist__row is-new';
    const img = document.createElement('img');
    img.className = 'hist__img'; img.src = imgSrc; img.alt = ''; img.decoding = 'async';
    const text = document.createElement('span');
    text.className = 'hist__text';
    const strong = document.createElement('strong');
    strong.className = 'hist__name'; strong.textContent = name;
    const small = document.createElement('small');
    small.className = 'hist__time num'; small.textContent = clock();
    text.append(strong, small);
    const eff = document.createElement('span');
    eff.className = 'hist__eff num'; eff.textContent = `+${heal} SAÚDE`;
    li.append(img, text, eff);
    hist.prepend(li);
    while (hist.children.length > 3) hist.lastElementChild.remove();
    li.addEventListener('animationend', () => li.classList.remove('is-new'), { once: true });
  };

  $$('.mcard--heal').forEach((card) => {
    const btn = $('[data-use]', card);
    const cur = $('[data-count-cur]', card);
    if (!btn || !cur) return;
    btn.addEventListener('click', () => {
      let left = parseInt(cur.textContent, 10) || 0;
      if (left <= 0) return;
      left -= 1;
      cur.textContent = String(left);
      if (left === 0) {
        card.classList.add('is-empty');
        btn.disabled = true;
      }
      logTreatment(card.dataset.name || '', card.dataset.img || '', card.dataset.heal || '0');
      pulseHealth();
    });
  });

  /* ------------------------------------------------ exames médicos */
  $$('.mcard--exam').forEach((card) => {
    const btn = $('[data-exam]', card);
    const lbl = $('.mcard__btn-lbl', card);
    if (!btn || !lbl) return;
    let note = null;
    const idle = lbl.textContent;
    btn.addEventListener('click', () => {
      if (card.classList.contains('is-running')) return;
      card.classList.add('is-running');
      btn.disabled = true;
      lbl.textContent = card.dataset.running || 'ANALISANDO';
      if (note) { note.remove(); note = null; }
      setTimeout(() => {
        card.classList.remove('is-running');
        btn.disabled = false;
        btn.classList.add('is-done');
        lbl.textContent = idle;
        note = document.createElement('p');
        note.className = 'mcard__note';
        note.textContent = card.dataset.result || '';
        card.append(note);
      }, reduced ? 240 : 1500);
    });
  });

  /* ------------------------------------------------ teclas de atalho */
  const keys = { x: '#sd-body-detail', h: '#sd-history' };
  document.addEventListener('keydown', (ev) => {
    if (ev.ctrlKey || ev.altKey || ev.metaKey) return;
    const tag = (ev.target && ev.target.tagName) || '';
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
    const sel = keys[ev.key.toLowerCase()];
    if (!sel) return;
    const el = $(sel);
    if (!el) return;
    ev.preventDefault();
    el.classList.add('is-hit');
    setTimeout(() => { el.classList.remove('is-hit'); el.click(); }, 140);
  });

  /* ------------------------------------------------ efeitos ativos (contagem) */
  const timers = $$('[data-countdown]');
  if (timers.length) {
    const tick = () => {
      timers.forEach((el) => {
        let s = parseInt(el.dataset.countdown, 10);
        if (!Number.isFinite(s)) return;
        s = s > 0 ? s - 1 : 0;
        el.dataset.countdown = String(s);
        el.textContent = `${pad(Math.floor(s / 60))}:${pad(s % 60)}`;
      });
    };
    setInterval(tick, 1000);
  }
}
