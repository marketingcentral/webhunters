/* ==========================================================================
   CONFIGURAÇÕES — comportamentos da tela.
   1. escalona os grupos no boot (--gi);
   2. troca a descrição do preset ao escolher um ladrilho;
   3. mantém ladrilho <-> stepper equivalente em sincronia (ex.: Dificuldade);
   4. reatribuição de teclas (clique no keycap, pressione a nova tecla) + conflitos;
   5. contador de alterações pendentes no botão APLICAR.
   Steppers/toggles/ranges/seleção já são tratados por hud.js.
   ========================================================================== */

const root = document.querySelector('.set');
if (root) {
  /* ---- 1. stagger dos grupos -------------------------------------------- */
  root.querySelectorAll('.set-group').forEach((g, i) => g.style.setProperty('--gi', String(i)));
  root.querySelectorAll('.set-tiles > .set-tile').forEach((t, i) => t.style.setProperty('--i', String(i)));

  /* ---- 1b. máscara de rolagem só quando há conteúdo cortado -------------- */
  const scroller = root.querySelector('.set-scroll');
  if (scroller) {
    const syncMask = () => scroller.classList.toggle('is-scrollable', scroller.scrollHeight - scroller.clientHeight > 10);
    syncMask();
    window.addEventListener('resize', syncMask, { passive: true });
    document.addEventListener('hud:booted', syncMask);
  }

  const tiles = root.querySelector('.set-tiles');
  const note = root.querySelector('[data-note-target]');
  const norm = (s) => (s || '').trim().toLocaleUpperCase('pt-BR');

  /* ---- 2. descrição do preset ------------------------------------------- */
  const setNote = (text) => {
    if (!note || typeof text !== 'string' || note.textContent.trim() === text.trim()) return;
    note.classList.add('is-swapping');
    setTimeout(() => {
      note.textContent = text;
      note.classList.remove('is-swapping');
    }, 160);
  };

  /* ---- 3. sincronia ladrilho <-> stepper --------------------------------- */
  const twinStepper = () => {
    if (!tiles) return null;
    const labels = Array.from(tiles.querySelectorAll('.set-tile__label')).map((el) => norm(el.textContent));
    return Array.from(root.querySelectorAll('.set-row .stepper')).find((st) => {
      const opts = (st.dataset.options || '').split('|').map(norm).filter(Boolean);
      return opts.length === labels.length && opts.every((o, i) => o === labels[i]);
    }) || null;
  };
  const stepper = twinStepper();

  const tileFor = (value) => {
    if (!tiles) return null;
    return Array.from(tiles.querySelectorAll('.set-tile'))
      .find((t) => norm(t.querySelector('.set-tile__label')?.textContent) === norm(value)) || null;
  };

  if (tiles) {
    tiles.addEventListener('select:change', (e) => {
      const item = e.detail?.item;
      if (!item) return;
      setNote(item.dataset.note || '');
      // hud.js só escreve no hidden: avisa o contador de alterações pendentes.
      tiles.querySelector('input[type="hidden"]')?.dispatchEvent(new Event('change', { bubbles: true }));
      if (!stepper) return;
      const label = item.querySelector('.set-tile__label')?.textContent?.trim() || '';
      const opts = (stepper.dataset.options || '').split('|');
      const match = opts.find((o) => norm(o) === norm(label));
      if (!match || stepper.dataset.value === match) return;
      stepper.dataset.value = match;
      const val = stepper.querySelector('.stepper__val');
      const hidden = stepper.querySelector('input[type="hidden"]');
      if (val) { val.textContent = match; val.classList.remove('is-changing'); void val.offsetWidth; val.classList.add('is-changing'); }
      if (hidden) hidden.value = match;
    });
  }

  if (stepper) {
    stepper.addEventListener('stepper:change', (e) => {
      const tile = tileFor(e.detail?.value);
      if (!tile || tile.classList.contains('is-active')) return;
      tiles.querySelectorAll('.is-active').forEach((el) => {
        el.classList.remove('is-active');
        el.setAttribute('aria-pressed', 'false');
      });
      tile.classList.add('is-active');
      tile.setAttribute('aria-pressed', 'true');
      const input = tiles.querySelector('input[type="hidden"]');
      if (input && tile.dataset.value !== undefined) input.value = tile.dataset.value;
      setNote(tile.dataset.note || '');
      input?.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  /* ---- 4. reatribuição de teclas ---------------------------------------- */
  const NAMED = {
    ' ': 'ESPAÇO', Spacebar: 'ESPAÇO', Shift: 'SHIFT', Control: 'CTRL', Alt: 'ALT', Meta: 'META',
    Tab: 'TAB', Enter: 'ENTER', Backspace: 'BACKSPACE', Delete: 'DEL', Insert: 'INS',
    ArrowUp: 'CIMA', ArrowDown: 'BAIXO', ArrowLeft: 'ESQUERDA', ArrowRight: 'DIREITA',
    CapsLock: 'CAPS', Home: 'HOME', End: 'END', PageUp: 'PGUP', PageDown: 'PGDN',
  };
  const keyLabel = (ev) => {
    if (NAMED[ev.key]) return NAMED[ev.key];
    if (ev.key && ev.key.length === 1) return ev.key.toLocaleUpperCase('pt-BR');
    if (/^F\d{1,2}$/.test(ev.key || '')) return ev.key.toUpperCase();
    return (ev.code || '').replace(/^(Key|Digit|Numpad)/, '').toUpperCase().slice(0, 12) || null;
  };
  const caps = () => Array.from(root.querySelectorAll('.keycap[data-keybind]'));

  const markConflicts = () => {
    const seen = new Map();
    caps().forEach((c) => {
      const v = norm(c.textContent);
      if (c.classList.contains('is-binding')) return;
      seen.set(v, (seen.get(v) || 0) + 1);
    });
    caps().forEach((c) => {
      const dup = !c.classList.contains('is-binding') && (seen.get(norm(c.textContent)) || 0) > 1;
      c.classList.toggle('is-conflict', dup);
      c.closest('.set-row')?.classList.toggle('is-conflict', dup);
      if (dup) c.setAttribute('title', 'Tecla usada em mais de uma ação');
      else c.removeAttribute('title');
    });
  };

  let binding = null;
  const stopBind = (restore) => {
    if (!binding) return;
    const cap = binding;
    binding = null;
    cap.classList.remove('is-binding');
    if (restore) cap.textContent = cap.dataset.prev || '';
    delete cap.dataset.prev;
    document.removeEventListener('keydown', onBindKey, true);
    markConflicts();
  };
  function onBindKey(ev) {
    if (!binding) return;
    ev.preventDefault();
    ev.stopPropagation();
    if (ev.key === 'Escape') { stopBind(true); return; }
    const label = keyLabel(ev);
    if (!label) return;
    const cap = binding;
    cap.textContent = label;
    const hidden = cap.parentElement?.querySelector('input[type="hidden"]');
    if (hidden) {
      hidden.value = label;
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }
    cap.classList.remove('is-changing');
    void cap.offsetWidth;
    cap.classList.add('is-changing');
    stopBind(false);
  }
  root.addEventListener('click', (e) => {
    const cap = e.target.closest('.keycap[data-keybind]');
    if (!cap) return;
    e.preventDefault();
    if (binding === cap) { stopBind(true); return; }
    stopBind(true);
    binding = cap;
    cap.dataset.prev = cap.textContent.trim();
    cap.textContent = 'PRESSIONE…';
    cap.classList.add('is-binding');
    cap.focus();
    document.addEventListener('keydown', onBindKey, true);
  });
  root.addEventListener('focusout', (e) => {
    if (binding && e.target === binding) setTimeout(() => stopBind(true), 0);
  });
  if (caps().length) markConflicts();

  /* ---- 5. alterações pendentes ------------------------------------------ */
  const form = root.closest('form') || (root.tagName === 'FORM' ? root : null);
  const apply = root.querySelector('.set-btn--apply');
  if (form && apply) {
    const fields = () => Array.from(form.elements).filter(
      (el) => el.name && el.name !== '_csrf' && el.name !== 'action' && !el.disabled && el.type !== 'submit',
    );
    const readOf = (el) => (el.type === 'checkbox' ? String(el.checked) : String(el.value));
    const snap = new Map(fields().map((el) => [el.name, readOf(el)]));
    let badge = apply.querySelector('.set-btn__count');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'set-btn__count num';
      badge.hidden = true;
      apply.appendChild(badge);
    }
    const sync = () => {
      const changed = fields().filter((el) => snap.get(el.name) !== readOf(el));
      const rows = new Set(changed.map((el) => el.closest('.set-row')).filter(Boolean));
      root.querySelectorAll('.set-row.is-dirty').forEach((r) => { if (!rows.has(r)) r.classList.remove('is-dirty'); });
      rows.forEach((r) => r.classList.add('is-dirty'));
      badge.textContent = String(changed.length);
      badge.hidden = changed.length === 0;
      form.classList.toggle('is-dirty', changed.length > 0);
      apply.classList.toggle('is-dirty', changed.length > 0);
    };
    ['input', 'change', 'stepper:change', 'toggle:change'].forEach((ev) => form.addEventListener(ev, sync));
    sync();
  }
}
