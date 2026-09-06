/**
 * HUD IN-GAME — recebe a munição da Unreal e grava no inventário.
 *
 * Fluxo de um tiro:
 *   BP_BaseWeapon dispara AmmoChanged
 *     -> WBP_HuntersWeb::PushAmmo lê CurrentAmmo e chama SendMessageToJS("Hud.Ammo", json)
 *       -> aqui: pinta o número na hora e agenda a gravação em POST /hud/municao
 *
 * A tela é atualizada IMEDIATAMENTE e a gravação vai depois (debounce): numa rajada
 * chegam ~10 mensagens por segundo e não faz sentido um POST por bala — só o último
 * valor importa, e ele é o que fica no banco.
 */

const raiz = document.querySelector('[data-hud]');
if (raiz) {
  const EP_AMMO = raiz.dataset.endpointAmmo;
  const EP_DATA = raiz.dataset.endpointData;
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  const GRAVA_APOS = 400;   // ms de silêncio antes de persistir
  const pendentes = new Map();   // slot -> valor mais recente ainda não gravado
  let timer = null;

  const pad3 = (n) => String(Math.max(0, n | 0)).padStart(3, '0');

  /** Pinta um slot na tela. Não toca no banco. */
  function pintar(slot, current, reserve) {
    const card = raiz.querySelector(`[data-gun="${CSS.escape(slot)}"]`);
    if (!card) return;

    const cap = Number(card.dataset.capacity) || 0;
    const cur = Math.max(0, Math.min(cap || Infinity, current | 0));

    const elCur = card.querySelector('[data-ammo-cur]');
    if (elCur && elCur.textContent !== pad3(cur)) {
      elCur.textContent = pad3(cur);
      // reinicia a animação: sem isso dois tiros seguidos só piscam uma vez
      elCur.classList.remove('is-hit');
      void elCur.offsetWidth;
      elCur.classList.add('is-hit');
    }
    if (reserve !== undefined && reserve !== null) {
      const elRes = card.querySelector('[data-ammo-res]');
      if (elRes) elRes.textContent = pad3(reserve);
    }

    const bar = card.querySelector('.ov-gun__bar i');
    const pct = cap > 0 ? (cur / cap) * 100 : 0;
    if (bar) bar.style.setProperty('--v', `${pct}%`);

    card.classList.toggle('is-low', cap > 0 && pct <= 40 && pct > 15);
    card.classList.toggle('is-crit', cap > 0 && pct <= 15);
  }

  /** Grava o último valor de cada slot que mudou. */
  async function gravar() {
    timer = null;
    const lote = [...pendentes.entries()];
    pendentes.clear();

    for (const [slot, current] of lote) {
      try {
        const r = await fetch(EP_AMMO, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
          body: new URLSearchParams({ slot, current: String(current), _csrf: CSRF }),
        });
        const j = await r.json();
        // o servidor trunca em [0, capacidade]; se ele discordar, ele é a autoridade
        if (j && j.ok && j.current !== current) pintar(slot, j.current);
        if (j && j.ok === false) console.warn('[hud] munição não gravada:', j.error);
      } catch (err) {
        // rede caiu: o número na tela continua certo e o próximo tiro tenta de novo
        console.warn('[hud] falha ao gravar munição', err);
      }
    }
  }

  function agendar(slot, current) {
    pendentes.set(slot, current);
    if (timer) clearTimeout(timer);
    timer = setTimeout(gravar, GRAVA_APOS);
  }

  /** Mensagem da Unreal: {"slot":"primary","current":29,"reserve":120} */
  function aoReceberMunicao(corpo) {
    let m = corpo;
    if (typeof m === 'string') {
      try { m = JSON.parse(m); } catch { console.warn('[hud] Hud.Ammo não é JSON:', corpo); return; }
    }
    if (!m || typeof m.slot !== 'string' || m.current === undefined) return;
    const cur = Number(m.current);
    if (!Number.isFinite(cur)) return;

    pintar(m.slot, cur, m.reserve);
    agendar(m.slot, cur);
  }

  // Assina a ponte. Fora da Unreal nada chega e a página fica só com o estado do banco.
  import('../unreal.js').then(({ receber, pronta }) => {
    receber('Hud.Ammo', aoReceberMunicao);
    pronta().then((ok) => {
      if (!ok) console.info('[hud] fora da Unreal: mostrando só o estado do inventário');
    });
  });

  // Se a Unreal recarregar a página no meio de uma partida, o estado vem do banco.
  // Este refresh cobre o caso de o inventário ter mudado em outra aba/tela.
  window.addEventListener('focus', async () => {
    try {
      const j = await (await fetch(EP_DATA, { headers: { Accept: 'application/json' } })).json();
      for (const g of (j.guns ?? [])) pintar(g.slot, g.current, g.reserve);
    } catch { /* sem rede: mantém o que está na tela */ }
  });
}
