/* ==========================================================================
   EXTRACT HUNTERS — tela CRIAR CONTA
   1) alternador de visibilidade das duas senhas (visibility_off <-> visibility)
   2) medidor de força da senha (4 níveis) + conferência da confirmação
   3) foco automático no primeiro campo reprovado quando o servidor devolve erros
   4) rotação suave das DICAS DE SOBREVIVÊNCIA (igual ao login)
   Sem dependências: o cartão é o mesmo do login, mas a tela tem JS próprio.
   ========================================================================== */

const card = document.querySelector('.page-criar-conta .register-card');

if (card) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- 1. senhas visíveis --------------------------------------------------- */
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

  /* ---- 2. força da senha ---------------------------------------------------- */
  const meter = card.querySelector('[data-strength-meter]');
  const pass = card.querySelector('[data-strength-input]');
  const confirm = card.querySelector('[data-strength-match]');
  const value = meter ? meter.querySelector('.register__meter-value') : null;
  const levels = value && value.dataset.levels ? value.dataset.levels.split('|') : [];

  /** 0 (vazia) a 4 (forte): comprimento + variedade de classes de caracteres. */
  const score = (s) => {
    if (!s) return 0;
    let n = 0;
    if (s.length >= 8) n += 1;
    if (s.length >= 12) n += 1;
    const kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((re) => re.test(s)).length;
    if (kinds >= 2) n += 1;
    if (kinds >= 3) n += 1;
    return Math.max(1, Math.min(4, n));
  };

  if (meter && pass) {
    const render = () => {
      const level = score(pass.value);
      meter.dataset.level = String(level);
      meter.classList.toggle('is-live', pass.value.length > 0);
      if (value && levels[level] !== undefined) value.textContent = levels[level];
    };
    pass.addEventListener('input', render);
    render();
  }

  if (pass && confirm) {
    const match = () => {
      const wrap = confirm.closest('.field');
      if (!wrap) return;
      const bad = confirm.value !== '' && confirm.value !== pass.value;
      wrap.classList.toggle('is-invalid', bad);
      confirm.setAttribute('aria-invalid', bad ? 'true' : 'false');
    };
    confirm.addEventListener('input', match);
    confirm.addEventListener('blur', match);
    pass.addEventListener('input', () => { if (confirm.value !== '') match(); });
  }

  /* ---- 3. volta de um POST com erro: foco no primeiro campo reprovado ---------
     O servidor recarrega a tela com `.is-invalid`; sem isto a pessoa precisa caçar
     o campo errado no meio de quatro. Só roda na carga (o `match()` acima marca
     depois, e aí o foco já é da pessoa). */
  const firstBad = card.querySelector('.register__field.is-invalid .input, .register__terms.is-invalid input');
  if (firstBad) firstBad.focus({ preventScroll: true });

  /* ---- 4. dicas rotativas ---------------------------------------------------- */
  const rotator = card.querySelector('[data-tip-rotator]');
  const tips = rotator ? Array.from(rotator.querySelectorAll('.login-tips__text')) : [];

  if (rotator && tips.length > 1 && !reduced) {
    const wait = Number(rotator.dataset.interval) || 8000;
    let i = 0;
    let timer = 0;

    const stop = () => { if (timer) { clearInterval(timer); timer = 0; } };
    const next = () => {
      tips[i].classList.remove('is-current');
      tips[i].setAttribute('aria-hidden', 'true');
      i = (i + 1) % tips.length;
      tips[i].classList.add('is-current');
      tips[i].removeAttribute('aria-hidden');
    };
    const play = () => { stop(); timer = setInterval(next, wait); };

    rotator.addEventListener('mouseenter', stop);
    rotator.addEventListener('mouseleave', play);
    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : play()));
    play();
  }
}
