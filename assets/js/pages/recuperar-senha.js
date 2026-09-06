/* ==========================================================================
   EXTRACT HUNTERS — tela RECUPERAR SENHA
   1) rotação suave das DICAS DE SOBREVIVÊNCIA (igual ao login)
   2) validação leve do e-mail: acende o passo 1 e libera o botão verde
   3) contagem regressiva ao vivo do REENVIAR LINK (estado enviado)
   ========================================================================== */

const card = document.querySelector('.page-recuperar-senha .recover-card');

if (card) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- 1. dicas rotativas -------------------------------------------------- */
  const rotator = card.querySelector('[data-tip-rotator]');
  const tips = rotator ? Array.from(rotator.querySelectorAll('.recover-tips__text')) : [];

  if (rotator && tips.length > 1 && !reduced) {
    const wait = Number(rotator.dataset.interval) || 8000;
    let i = 0;
    let timer = 0;

    const next = () => {
      tips[i].classList.remove('is-current');
      tips[i].setAttribute('aria-hidden', 'true');
      i = (i + 1) % tips.length;
      tips[i].classList.add('is-current');
      tips[i].removeAttribute('aria-hidden');
    };
    const play = () => { stop(); timer = setInterval(next, wait); };
    const stop = () => { if (timer) { clearInterval(timer); timer = 0; } };

    rotator.addEventListener('mouseenter', stop);
    rotator.addEventListener('mouseleave', play);
    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : play()));
    play();
  }

  /* ---- 2. e-mail plausível acende o passo 1 -------------------------------- */
  const email = card.querySelector('#recover-email');
  const steps = Array.from(card.querySelectorAll('.recover-step'));

  if (email && steps.length) {
    const ok = (v) => /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(v.trim());
    const sync = () => {
      const valid = ok(email.value);
      steps[0].classList.toggle('is-done', valid);
      if (steps[1]) steps[1].classList.toggle('is-current', valid);
      steps[0].classList.toggle('is-current', !valid);
    };
    email.addEventListener('input', sync);
    email.addEventListener('blur', () => {
      const field = email.closest('.field');
      if (field) field.classList.toggle('is-invalid', email.value.trim() !== '' && !ok(email.value));
    });
    email.addEventListener('focus', () => email.closest('.field')?.classList.remove('is-invalid'));
    sync();
  }

  /* ---- 3. contagem regressiva do reenvio ----------------------------------- */
  const resend = card.querySelector('[data-resend]');
  const clock = resend ? resend.querySelector('.recover__clock') : null;

  if (resend && clock) {
    let left = Math.max(0, Number(resend.dataset.resend) || 0);
    const label = resend.querySelector('.btn__label');
    const glyph = resend.querySelector('.ms');

    const mmss = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

    const ready = () => {
      resend.disabled = false;
      if (glyph) glyph.textContent = 'send';
      if (label) label.textContent = resend.dataset.labelReady || 'REENVIAR LINK';
    };

    const tick = () => {
      left -= 1;
      if (left <= 0) { clearInterval(timer); ready(); return; }
      clock.textContent = mmss(left);
    };

    clock.textContent = mmss(left);
    const timer = left > 0 ? setInterval(tick, 1000) : 0;
    if (left <= 0) ready();
  }
}
