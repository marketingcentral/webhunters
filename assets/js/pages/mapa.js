/**
 * EXTRACT HUNTERS — tela MAPA DO MUNDO.
 *
 *  - OCULTAR / MOSTRAR LEGENDA (botão + atalho X)
 *  - CAMADAS: chips TODOS · HUNTER · FACÇÕES · VEYRA · BASES · EXTRAÇÕES · EVENTOS (PRD v3 §23)
 *  - sinais de atividade humana expiram (data-expires)
 *  - contagem regressiva ao vivo do evento ativo (mm:ss)
 *  - MARCAR PONTO (estado on/off)
 *  - atalho F = DEFINIR ROTA
 *
 * O mapa em si vive em assets/js/three/map3d.js.
 */

const page = document.body;
if (page && page.dataset.page === 'mapa') {
  const $ = (s, r = document) => r.querySelector(s);

  /* ---- legenda ---------------------------------------------------------- */
  const toggle = $('[data-legend-toggle]');
  const legend = document.getElementById('mapa-legend');
  const label = toggle ? toggle.querySelector('.mp__toggle-label') : null;

  function setLegend(open) {
    if (!legend || !toggle) return;
    legend.classList.toggle('is-hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (label) label.textContent = open ? (toggle.dataset.labelOn || '') : (toggle.dataset.labelOff || '');
  }
  if (toggle) toggle.addEventListener('click', () => setLegend(legend.classList.contains('is-hidden')));

  /* ---- camadas (PRD v3 §23.9) --------------------------------------------
   * hud.js já alterna .is-active nos chips de [data-select-group] e dispara
   * `select:change`; aqui só escondemos os marcadores/zonas fora da camada. */
  const layers = $('[data-map-filters]');
  if (layers) {
    const nodes = Array.from(document.querySelectorAll('#map3d-layer [data-layer]'));
    const chips = Array.from(layers.querySelectorAll('.mlayer'));
    const chipTodos = chips.find((c) => c.dataset.value === 'todos');

    /* Multi-seleção: cada chip liga/desliga a sua camada, então dá para pedir
       "só Veyra e extrações". TODOS é o atalho de voltar a ver o mapa inteiro;
       nenhum chip marcado também mostra tudo, para nunca sobrar um mapa vazio. */
    const marcar = (chip, on) => {
      chip.classList.toggle('is-active', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    };
    const aplicar = () => {
      const on = chips.filter((c) => c !== chipTodos && c.classList.contains('is-active'))
                      .map((c) => c.dataset.value);
      const tudo = on.length === 0;
      if (chipTodos) marcar(chipTodos, tudo);
      nodes.forEach((n) => n.classList.toggle('is-filtered', !tudo && !on.includes(n.dataset.layer)));
      document.getElementById('map3d')?.setAttribute('data-layer-filter', tudo ? '' : on.join(' '));
    };

    layers.addEventListener('click', (ev) => {
      const chip = ev.target.closest('.mlayer');
      if (!chip) return;
      if (chip === chipTodos) chips.forEach((c) => marcar(c, c === chipTodos));
      else marcar(chip, !chip.classList.contains('is-active'));
      aplicar();
    });
    aplicar();
  }

  /* ---- sinais de atividade humana expiram (§23.8) --------------------------
   * Posição aproximada e temporária: o rótulo mostra o tempo restante e o
   * marcador se apaga ao chegar em zero — o backend real substituirá isto. */
  const temp = Array.from(document.querySelectorAll('#map3d-layer .mk[data-expires]'));
  if (temp.length) {
    const pad = (n) => String(n).padStart(2, '0');
    const st = temp.map((el) => ({ el, s: Math.max(0, parseInt(el.dataset.expires, 10) || 0), sub: el.querySelector('.mk__sub') }));
    const tick = () => {
      st.forEach((t) => {
        if (t.s > 0) t.s -= 1;
        if (t.sub) t.sub.textContent = 'EXPIRA EM ' + pad(Math.floor(t.s / 60)) + ':' + pad(t.s % 60);
        t.el.classList.toggle('is-expired', t.s === 0);
      });
    };
    tick();
    setInterval(tick, 1000);
  }

  /* ---- contagem regressiva ---------------------------------------------- */
  const clock = $('[data-countdown]');
  if (clock) {
    const parts = String(clock.dataset.countdown || '').split(':');
    let left = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
    const start = left;
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
      left = left > 0 ? left - 1 : start;
      clock.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
      clock.classList.toggle('amber', left <= 60);
    };
    setInterval(tick, 1000);
  }

  /* ---- MARCAR PONTO ----------------------------------------------------- */
  const mark = $('[data-mark-cta]');
  if (mark) {
    const txt = mark.querySelector('.btn__label');
    mark.addEventListener('click', () => {
      const on = mark.getAttribute('aria-pressed') !== 'true';
      mark.setAttribute('aria-pressed', on ? 'true' : 'false');
      mark.classList.toggle('is-on', on);
      if (txt) txt.textContent = on ? (mark.dataset.labelOn || '') : (mark.dataset.label || '');
    });
  }

  /* ---- opacidade da arte (conforto de leitura, gravado na conta) -----------
   * O <form> funciona sem este bloco: arrasta, clica em aplicar, a página volta.
   * O que o script acrescenta é a reação imediata — a arte responde ao arrasto —
   * e o envio automático, que torna o botão de aplicar desnecessário.
   *
   * Por que só grava ao SOLTAR: arrastar de 80% a 40% dispara dezenas de eventos
   * `input`. Gravar em cada um seria dezenas de escritas no banco para uma decisão
   * só, e a última a chegar nem sempre é a última arrastada. */
  const opForm = $('[data-map-opacity]');
  if (opForm) {
    const range   = opForm.querySelector('[data-map-opacity-range]');
    const saida   = opForm.querySelector('[data-map-opacity-out]');
    const oculto  = opForm.querySelector('[data-map-opacity-payload]');
    const aplicar = opForm.querySelector('[data-map-opacity-apply]');
    const voltar  = opForm.querySelector('[data-map-opacity-reset]');
    const palco   = document.getElementById('map3d');
    const padrao  = Number(opForm.dataset.padrao) || 65;

    // com script, aplicar é automático — o botão existe para quem está sem ele
    aplicar?.remove();

    const pintar = (pct) => {
      if (palco) palco.style.setProperty('--map-art-opacity', String(pct / 100));
      if (saida) saida.textContent = `${pct}%`;
      if (oculto) oculto.value = (pct / 100).toFixed(2);
      voltar?.classList.toggle('is-hidden', pct === padrao);
    };

    async function gravar(corpo) {
      try {
        await fetch(opForm.action, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          body: corpo,
        });
      } catch {
        /* Falhar aqui não desfaz o ajuste na tela: o jogador continua vendo o mapa
           como pediu nesta sessão, e a próxima mudança tenta gravar de novo. */
      }
    }

    range?.addEventListener('input', () => pintar(Number(range.value)));
    range?.addEventListener('change', () => {
      const corpo = new FormData(opForm);
      corpo.delete('reset');
      gravar(corpo);
    });

    voltar?.addEventListener('click', (ev) => {
      ev.preventDefault();
      if (range) { range.value = String(padrao); }
      pintar(padrao);
      const corpo = new FormData(opForm);
      corpo.set('reset', '1');
      gravar(corpo);
    });
  }

  /* ---- atalhos ----------------------------------------------------------- */
  const route = $('[data-route-cta]');
  document.addEventListener('keydown', (ev) => {
    if (ev.ctrlKey || ev.altKey || ev.metaKey) return;
    const t = ev.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || ''))) return;
    const k = ev.key.toLowerCase();
    if (k === 'x' && toggle) { ev.preventDefault(); toggle.click(); }
    else if (k === 'f' && route) { ev.preventDefault(); route.click(); }
  });
}
