/* ==========================================================================
   EXTRACT HUNTERS — tela LOGIN
   1) alternador de visibilidade da senha (visibility_off <-> visibility)
   2) rotação suave das DICAS DE SOBREVIVÊNCIA
   3) botão Steam: aviso pelo tooltip do hud.js ([data-tip] abre no focus)
   ========================================================================== */

const card = document.querySelector('.page-login .login-card');

if (card) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- 1. senha visível --------------------------------------------------- */
  card.querySelectorAll('[data-pass-toggle]').forEach((btn) => {
    const input = document.getElementById(btn.getAttribute('data-pass-toggle') || '');
    if (!input) return;
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'visibility' : 'visibility_off';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      const label = show ? btn.dataset.labelHide : btn.dataset.labelShow;
      if (label) btn.setAttribute('aria-label', label);
      const at = input.value.length;
      input.focus({ preventScroll: true });
      try { input.setSelectionRange(at, at); } catch { /* type=password nem sempre aceita */ }
    });
  });

  /* ---- 2. dicas rotativas -------------------------------------------------- */
  const rotator = card.querySelector('[data-tip-rotator]');
  const tips = rotator ? Array.from(rotator.querySelectorAll('.login-tips__text')) : [];

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

  /* ---- 3. Steam: indisponível nesta demonstração --------------------------- */
  const steam = card.querySelector('[data-steam]');
  if (steam) {
    steam.addEventListener('click', () => {
      steam.classList.remove('is-nudged');
      void steam.offsetWidth;                    // reinicia a animação
      steam.classList.add('is-nudged');
      steam.focus({ preventScroll: true });      // hud.js abre o [data-tip] no focusin
    });
    steam.addEventListener('animationend', () => steam.classList.remove('is-nudged'));
  }
}
