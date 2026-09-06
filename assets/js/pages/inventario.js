/* ==========================================================================
   Inventário estilo Tarkov — motor de drag-drop, rotação e acessórios.
   Fonte da verdade = o banco (InventoryRepo). O cliente busca a árvore, renderiza,
   e a cada ação envia ao servidor e re-renderiza com a árvore devolvida.
   ========================================================================== */
const root = document.querySelector('[data-inv]');
if (root) {
  const EP = {
    tree:   root.dataset.endpointTree,
    move:   root.dataset.endpointMove,
    equip:  root.dataset.endpointEquip,
    attach: root.dataset.endpointAttach,
  };
  const MEDIA = root.dataset.media;
  const WEIGHT_MAX = parseFloat(root.dataset.weightMax) || 60;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const RAR = { comum: 'var(--rar-comum)', incomum: 'var(--rar-incomum)', raro: 'var(--rar-raro)', epico: 'var(--rar-epico)', lendario: 'var(--rar-lendario)' };

  // célula/gap em px (lidos do computed style)
  const px = (v) => parseFloat(v) || 0;
  const cs = getComputedStyle(root);
  let CELL = px(cs.getPropertyValue('--cell')) * (px(getComputedStyle(document.documentElement).fontSize) / 16 || 1);
  // --cell está em rem; converte para px multiplicando pela fonte raiz
  const remPx = px(getComputedStyle(document.documentElement).fontSize) || 16;
  CELL = px(cs.getPropertyValue('--cell')) * remPx;
  const GAP = px(cs.getPropertyValue('--gap')) * remPx;
  const STEP = CELL + GAP;

  const ghost = root.querySelector('[data-drag-ghost]');
  const toastEl = root.querySelector('[data-toast]');
  let tree = null;
  let drag = null;   // {item, from, rotated, grab:{dx,dy}, w,h}

  // ---------------------------------------------------------------- utilidades
  const toast = (msg) => {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { toastEl.hidden = true; }, 2200);
  };

  async function api(url, body) {
    const fd = new URLSearchParams({ _csrf: csrf, ...body });
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: fd.toString(),
    });
    return res.json().catch(() => ({ ok: false, error: 'Resposta inválida.' }));
  }

  function itemDims(it, rotated) {
    return rotated ? { w: it.h, h: it.w } : { w: it.w, h: it.h };
  }

  // ---------------------------------------------------------------- render
  function makeTile(it) {
    const el = document.createElement('div');
    el.className = 'item';
    el.dataset.id = it.id;
    el.dataset.cat = it.category;
    const { w, h } = itemDims(it, it.rotated);
    el.style.setProperty('--w', w);
    el.style.setProperty('--h', h);
    el.style.setProperty('--rar', RAR[it.rarity] || RAR.comum);
    // A ARTE gira junto com a peça: a imagem é dimensionada pela caixa NÃO girada
    // (--iw/--ih) e recebe rotate(90deg). Antes só a célula girava e o desenho
    // continuava deitado dentro de um slot em pé.
    el.style.setProperty('--iw', it.w);
    el.style.setProperty('--ih', it.h);
    el.style.setProperty('--rot', it.rotated ? '90deg' : '0deg');
    if (it.rotated) el.classList.add('is-rotated');
    const attachable = it.category === 'weapon' || it.category === 'pistol';
    if (attachable) el.classList.add('item--attachable');
    if (it.w !== it.h) el.classList.add('is-rotatable');
    let dur = '';
    if (it.durability != null && it.durability_max) {
      const pct = Math.max(0, Math.min(100, (it.durability / it.durability_max) * 100));
      const cls = pct <= 20 ? ' is-crit' : pct <= 50 ? ' is-low' : '';
      dur = `<span class="item__dur${cls}"><i style="--d:${pct}%"></i></span>`;
    }
    // contador, na ordem de relevância do Tarkov:
    // carregador/arma = munição carregada; consumível empilhado = xN; item com usos = usos restantes
    let count = '';
    if (it.ammo_cur != null && it.mag_capacity) {
      count = `${it.ammo_cur}/${it.mag_capacity}`;
    } else if (attachable) {
      const mag = (it.attachments || []).find((a) => a.mag_capacity);
      count = mag ? `${mag.ammo_cur ?? 0}/${mag.mag_capacity}` : '';   // sem carregador, sem número
    } else if (it.quantity > 1) {
      count = `x${it.quantity}`;
    } else if (it.durability != null && it.durability_max && it.durability_max > 1) {
      // proteção (capacete/colete/máscara) e consumíveis com usos mostram a vida útil,
      // como no Tarkov — antes eu excluía tudo que tinha slot_type e sumia com o número
      count = `${it.durability}/${it.durability_max}`;
    }
    el.innerHTML =
      `<span class="item__name">${it.short || it.name}</span>` +
      `<img class="item__img" src="${MEDIA}${it.image}" alt="" draggable="false">` +
      (count ? `<span class="item__count num">${count}</span>` : '') +
      `<span class="item__rot ms" aria-hidden="true">rotate_right</span>` +
      dur;
    el.addEventListener('pointerdown', (e) => startDrag(e, it, el));
    el.addEventListener('dblclick', () => quickEquip(it));
    // acessórios de arma: botão direito -> ANEXAR À ARMA (não mais clique simples)
    return el;
  }

  /**
   * Slots LARGOS (2 colunas) que renderizam o painel completo da arma.
   * Coldre e bainha são células de 1 coluna no GEAR: mostram só a arte, como no Tarkov.
   */
  const WEAPON_SLOTS = new Set(['primary', 'secondary']);
  const SLOT_BADGE = { primary: '02', secondary: '03', pistol: '94', knife: '85' };

  /**
   * Painel da arma equipada, no formato da referência:
   *   nome + calibre (topo esq.) · badge (topo dir.) · arte grande ·
   *   mini-slots de acessório (base esq.) · munição carregada (base dir.)
   */
  function makeWeaponPanel(it, slotKey) {
    const el = document.createElement('div');
    el.className = 'wslot';
    el.dataset.id = it.id;

    // Os mini-grids de acessório saíram do painel: agora os acessórios são geridos
    // pelo modal "ANEXAR À ARMA" (botão direito), que dá muito mais espaço e contexto.
    const bocais = it.attach_slots || [];
    const nAtt = (it.attachments || []).length;

    // só arma de fogo fala de munição: uma faca não tem bocal de carregador
    const aceitaMag = bocais.some((b) => (b.accepts || []).includes('mag'));
    const mag = (it.attachments || []).find((a) => a.mag_capacity);
    const reserva = it.ammo_caliber ? countReserve(it.ammo_caliber) : 0;
    const ammo = !aceitaMag ? ''
      : mag ? `${mag.ammo_cur ?? 0}<small>/${reserva}</small>`
      : `<small>SEM CARREGADOR</small>`;

    el.innerHTML =
      `<span class="wslot__id"><span class="wslot__name">${it.name}</span>` +
      `<span class="wslot__cal">${it.ammo_caliber || ''}</span></span>` +
      `<span class="wslot__key num">${SLOT_BADGE[slotKey] || ''}</span>` +
      `<img class="wslot__img" src="${MEDIA}${it.image}" alt="${it.name}" draggable="false">` +
      (bocais.length ? `<span class="wslot__mods num" title="Acessórios instalados">${nAtt}/${bocais.length}</span>` : '') +
      (ammo ? `<span class="wslot__ammo num">${ammo}</span>` : '');

    el.addEventListener('pointerdown', (e) => {
      if (e.target.closest('.att')) return;   // arrastar de dentro do bocal não move a arma
      startDrag(e, it, el);
    });
    return el;
  }

  /** Munição do mesmo calibre guardada em carregadores do jogador (reserva). */
  function countReserve(caliber) {
    let total = 0;
    const walk = (it) => {
      if (it.mag_capacity && it.ammo_caliber === caliber) total += it.ammo_cur ?? 0;
      (it.contents || []).forEach(walk);
      (it.attachments || []).forEach(walk);
    };
    (tree?.stash?.items || []).forEach(walk);
    (tree?.pockets?.items || []).forEach(walk);
    Object.values(tree?.slots || {}).forEach((s) => { if (s.item) walk(s.item); });
    return total;
  }

  function render() {
    if (!tree) return;
    // limpa: o painel de arma (.wslot) NÃO tem a classe .item — sem removê-lo aqui,
    // cada re-render empilhava um painel novo sobre o antigo e o jogador continuava
    // vendo o estado velho (acessório instalado não aparecia).
    root.querySelectorAll('.item, .wslot').forEach((n) => n.remove());
    root.querySelectorAll('.gslot').forEach((s) => s.classList.remove('is-filled'));

    // equipamento
    for (const [key, slot] of Object.entries(tree.slots || {})) {
      const shell = root.querySelector(`.gslot[data-slot="${key}"]`);
      if (!shell) continue;
      // .gslot--weapon vem do PHP (define o span de 2 colunas); aqui só a altura do painel
      shell.classList.toggle('gslot--panel', WEAPON_SLOTS.has(key));
      if (slot.item) {
        shell.classList.add('is-filled');
        if (WEAPON_SLOTS.has(key)) {
          // arma equipada = painel completo (nome, calibre, badge, acessórios, munição),
          // não um tile pequeno: é o que a referência mostra e o que informa o jogador
          shell.appendChild(makeWeaponPanel(slot.item, key));
        } else {
          // a arte preenche a célula inteira do slot (object-fit:contain preserva a forma)
          const t = makeTile(slot.item);
          t.classList.add('item--equipped');
          shell.appendChild(t);
        }
      }
    }
    // contêineres (colete tático / mochila): a seção fica sempre no lugar — como no
    // GEAR do Tarkov — e só o miolo alterna entre a grade e o aviso de "equipe um…".
    for (const key of ['rig', 'backpack']) {
      const grid  = root.querySelector(`[data-grid][data-parent-slot="${key}"]`);
      const aviso = root.querySelector(`[data-bag-empty="${key}"]`);
      const count = root.querySelector(`[data-container-count="${key}"]`);
      const item  = tree.slots?.[key]?.item;
      const aberto = !!(grid && item && item.grid_cols && item.grid_rows);
      if (grid)  grid.hidden = !aberto;
      if (aviso) aviso.hidden = aberto;
      if (count) count.textContent = aberto ? `${(item.contents || []).length} ITENS` : '';
      if (!aberto) continue;
      grid.style.setProperty('--cols', item.grid_cols);
      grid.style.setProperty('--rows', item.grid_rows);
      grid.dataset.cols = item.grid_cols; grid.dataset.rows = item.grid_rows;
      grid.dataset.parentId = item.id;
      buildCells(grid);
      (item.contents || []).forEach((c) => placeInGrid(grid, c));
    }

    // bolsos do personagem: grade fixa, independente do que está equipado
    const pockets = root.querySelector('[data-zone="pockets"]');
    if (pockets && tree.pockets) {
      pockets.style.setProperty('--cols', tree.pockets.cols);
      pockets.style.setProperty('--rows', tree.pockets.rows);
      pockets.dataset.cols = tree.pockets.cols; pockets.dataset.rows = tree.pockets.rows;
      buildCells(pockets);
      (tree.pockets.items || []).forEach((it) => placeInGrid(pockets, it));
    }

    // stash
    const stash = root.querySelector('[data-zone="stash"]');
    stash.style.setProperty('--cols', tree.stash.cols);
    stash.style.setProperty('--rows', tree.stash.rows);
    stash.dataset.cols = tree.stash.cols; stash.dataset.rows = tree.stash.rows;
    buildCells(stash);
    (tree.stash.items || []).forEach((it) => placeInGrid(stash, it));

    // cabeçalho: peso / valor
    const wEl = root.querySelector('[data-weight]');
    if (wEl) wEl.innerHTML = `${(tree.weight || 0).toFixed(1).replace('.', ',')}<i>/${WEIGHT_MAX} KG</i>`;
    const vEl = root.querySelector('[data-value]');
    if (vEl) vEl.textContent = (tree.value || 0).toLocaleString('pt-BR');

    applyFilter();   // os tiles são recriados a cada render; o realce tem de voltar
  }

  function buildCells(grid) {
    grid.querySelectorAll('.grid__cell').forEach((n) => n.remove());
    const cols = +grid.dataset.cols, rows = +grid.dataset.rows;
    const frag = document.createDocumentFragment();
    for (let i = 0; i < cols * rows; i++) {
      const c = document.createElement('div');
      c.className = 'grid__cell';
      frag.appendChild(c);
    }
    grid.appendChild(frag);
  }

  function placeInGrid(grid, it) {
    const t = makeTile(it);
    t.style.setProperty('--x', it.x ?? 0);
    t.style.setProperty('--y', it.y ?? 0);
    grid.appendChild(t);
  }

  // ---------------------------------------------------------------- drag
  function startDrag(e, it, el) {
    if (e.button !== 0) return;
    e.preventDefault();
    const r = el.getBoundingClientRect();
    drag = {
      item: it, el, rotated: !!it.rotated,
      grab: { dx: e.clientX - r.left, dy: e.clientY - r.top },
    };
    el.classList.add('is-dragging');
    el.setPointerCapture?.(e.pointerId);
    // ghost
    const { w, h } = itemDims(it, drag.rotated);
    ghost.style.width = `${w * CELL + (w - 1) * GAP}px`;
    ghost.style.height = `${h * CELL + (h - 1) * GAP}px`;
    ghost.style.borderColor = RAR[it.rarity] || RAR.comum;
    ghost.innerHTML = `<img src="${MEDIA}${it.image}" alt="">`;
    ghost.hidden = false;
    moveGhost(e);
    document.addEventListener('pointermove', onDrag);
    document.addEventListener('pointerup', endDrag, { once: true });
    document.addEventListener('keydown', onRotateKey);
  }

  function moveGhost(e) {
    ghost.style.left = `${e.clientX - drag.grab.dx}px`;
    ghost.style.top = `${e.clientY - drag.grab.dy}px`;
  }

  function onRotateKey(e) {
    if (!drag || e.key.toLowerCase() !== 'r') return;
    if (drag.item.w === drag.item.h) return;
    drag.rotated = !drag.rotated;
    const { w, h } = itemDims(drag.item, drag.rotated);
    ghost.style.width = `${w * CELL + (w - 1) * GAP}px`;
    ghost.style.height = `${h * CELL + (h - 1) * GAP}px`;
  }

  let lastTarget = null;
  function onDrag(e) {
    if (!drag) return;
    moveGhost(e);
    ghost.hidden = true;
    const under = document.elementFromPoint(e.clientX, e.clientY);
    ghost.hidden = false;
    clearHighlights();
    lastTarget = resolveTarget(under, e);
    if (lastTarget) {
      lastTarget.el.classList.add(lastTarget.ok ? 'is-drop-ok' : 'is-drop-no');
      ghost.classList.toggle('is-no', !lastTarget.ok);
    } else {
      ghost.classList.remove('is-no');
    }
  }

  function clearHighlights() {
    root.querySelectorAll('.is-drop-ok,.is-drop-no').forEach((n) => n.classList.remove('is-drop-ok', 'is-drop-no'));
  }

  /** Descobre a zona de destino sob o cursor e a posição/validade aproximada. */
  function resolveTarget(under, e) {
    if (!under) return null;
    const attSlot = under.closest('[data-att-slot]');
    if (attSlot) {
      // o bocal pode declarar o SUBTIPO ('sight', 'suppressor') ou a CATEGORIA ('mag').
      // Checar só o subtipo barrava o carregador (subtype 'magazine', accepts 'mag'),
      // que o servidor aceitava — cliente e servidor precisam concordar.
      const aceita = (attSlot.dataset.accepts || '').split(',').filter(Boolean);
      const ok = aceita.includes(drag.item.subtype) || aceita.includes(drag.item.category);
      return { kind: 'attach', el: attSlot, ok, slotKey: attSlot.dataset.attSlot, weaponId: attSlot.dataset.weaponId };
    }
    const eq = under.closest('.gslot');
    if (eq) {
      const accepts = (eq.dataset.accepts || '').split(',');
      const ok = accepts.length === 0 || accepts.includes(drag.item.category) ||
        eqAccepts(eq.dataset.slot, drag.item.category);
      return { kind: 'equip', el: eq, ok, slotKey: eq.dataset.slot };
    }
    const grid = under.closest('[data-grid]');
    if (grid) {
      // origem das células = borda interna da grade (o padding do container conta)
      const rect = grid.getBoundingClientRect();
      const pad = parseFloat(getComputedStyle(grid).paddingLeft) || 0;
      const gx = Math.round((e.clientX - drag.grab.dx - rect.left - pad) / STEP);
      const gy = Math.round((e.clientY - drag.grab.dy - rect.top - pad) / STEP);
      const { w, h } = itemDims(drag.item, drag.rotated);
      const cols = +grid.dataset.cols, rows = +grid.dataset.rows;
      const cx = Math.max(0, Math.min(cols - w, gx));
      const cy = Math.max(0, Math.min(rows - h, gy));
      const parentId = grid.dataset.parentId ? +grid.dataset.parentId : null;
      // valida sobreposição igual ao servidor: sem isso o realce dizia "pode"
      // e o POST voltava 422 ("Não cabe aqui")
      const ok = cx + w <= cols && cy + h <= rows &&
        fits(grid.dataset.zone, parentId, cx, cy, w, h, drag.item.id);
      return { kind: 'grid', el: grid, ok, zone: grid.dataset.zone, parentId, x: cx, y: cy };
    }
    return null;
  }

  /** Células ocupadas do destino ("y:x"), ignorando a peça arrastada. */
  function occupancy(zone, parentId, excludeId) {
    const list = zone === 'stash' ? (tree?.stash?.items || [])
      : zone === 'pockets' ? (tree?.pockets?.items || [])
      : (findInstance(parentId)?.contents || []);
    const occ = new Set();
    for (const it of list) {
      if (it.id === excludeId || it.x == null) continue;
      const { w, h } = itemDims(it, it.rotated);
      for (let dy = 0; dy < h; dy++) for (let dx = 0; dx < w; dx++) occ.add(`${it.y + dy}:${it.x + dx}`);
    }
    return occ;
  }

  function fits(zone, parentId, x, y, w, h, excludeId) {
    const occ = occupancy(zone, parentId, excludeId);
    for (let dy = 0; dy < h; dy++) for (let dx = 0; dx < w; dx++) {
      if (occ.has(`${y + dy}:${x + dx}`)) return false;
    }
    return true;
  }

  /** Procura uma instância pelo id em qualquer lugar da árvore. */
  function findInstance(id) {
    if (!id) return null;
    let found = null;
    const walk = (it) => {
      if (found) return;
      if (it.id === id) { found = it; return; }
      (it.contents || []).forEach(walk);
      (it.attachments || []).forEach(walk);
    };
    (tree?.stash?.items || []).forEach(walk);
    (tree?.pockets?.items || []).forEach(walk);
    Object.values(tree?.slots || {}).forEach((s) => { if (s.item) walk(s.item); });
    return found;
  }

  // aceitação de slot (espelho leve do servidor; servidor é a autoridade)
  const SLOT_ACCEPTS = {
    earpiece: ['earpiece'],
    helmet: ['helmet'], mask: ['mask'], glasses: ['glasses'], armor: ['armor', 'clothing'],
    armband: ['armband'], rig: ['rig'], backpack: ['backpack'],
    primary: ['weapon'], secondary: ['weapon'], pistol: ['pistol'], knife: ['knife'],
  };
  function eqAccepts(slot, cat) { return (SLOT_ACCEPTS[slot] || []).includes(cat); }

  async function endDrag(e) {
    document.removeEventListener('pointermove', onDrag);
    document.removeEventListener('keydown', onRotateKey);
    ghost.hidden = true;
    ghost.classList.remove('is-no');
    const el = drag?.el; el?.classList.remove('is-dragging'); if (el) el.dataset.dragged = '1';
    const target = lastTarget;
    clearHighlights();
    lastTarget = null;
    const d = drag; drag = null;
    if (!d || !target || !target.ok) { render(); return; }

    let res;
    if (target.kind === 'equip') {
      res = await api(EP.equip, { instance_id: d.item.id, slot_key: target.slotKey });
    } else if (target.kind === 'attach') {
      res = await api(EP.attach, { instance_id: d.item.id, weapon_id: target.weaponId, slot_key: target.slotKey });
    } else {
      res = await api(EP.move, {
        instance_id: d.item.id,
        container: target.zone === 'stash' || target.zone === 'pockets' ? target.zone : 'grid',
        parent_id: target.parentId ?? '', x: target.x, y: target.y, rotated: d.rotated ? 1 : 0,
      });
    }
    if (res && res.tree) { tree = res.tree; render(); }
    if (res && res.ok === false) toast(res.error || 'Ação inválida.');
    else refreshWallet();
  }

  // ---------------------------------------------------------------- atalhos
  async function quickEquip(it) {
    if (!it.slot_type) return;
    const slot = it.slot_type;
    const res = await api(EP.equip, { instance_id: it.id, slot_key: slot });
    if (res && res.tree) { tree = res.tree; render(); }
    if (res && res.ok === false) toast(res.error || 'Não foi possível equipar.');
  }

  // ---------------------------------------------------------------- acessórios
  /** Todas as instâncias de arma do jogador (stash, equipadas ou em containers). */
  function allWeapons() {
    const out = [];
    const walk = (it) => {
      if (it.category === 'weapon' || it.category === 'pistol') out.push(it);
      (it.contents || []).forEach(walk);
    };
    (tree?.stash?.items || []).forEach(walk);
    (tree?.pockets?.items || []).forEach(walk);
    Object.values(tree?.slots || {}).forEach((s) => { if (s.item) walk(s.item); });
    return out;
  }

  // ================================================================ MODAL DE ESCOLHA
  // Um só modal serve a dois fluxos: escolher item para um SLOT de equipamento
  // (clique no slot) e escolher acessório para um BOCAL da arma (menu do botão direito).
  const pick = {
    el:      root.querySelector('[data-pick]'),
    title:   root.querySelector('[data-pick-title]'),
    sub:     root.querySelector('[data-pick-sub]'),
    slots:   root.querySelector('[data-pick-slots]'),
    grid:    root.querySelector('[data-pick-grid]'),
    empty:   root.querySelector('[data-pick-empty]'),
    unequip: root.querySelector('[data-pick-unequip]'),
  };
  let pickState = null;   // {mode:'equip'|'attach', slotKey?, weaponId?, bocal?}

  const closePick = () => { if (pick.el) pick.el.hidden = true; pickState = null; };

  /** Toda instância do jogador, achatada, com a origem legível ("STASH", "MOCHILA"...). */
  function flatItems() {
    const out = [];
    const walk = (it, onde) => {
      out.push({ it, onde });
      (it.contents || []).forEach((c) => walk(c, (it.short || it.name).toUpperCase()));
      (it.attachments || []).forEach((a) => walk(a, `EM ${(it.short || it.name).toUpperCase()}`));
    };
    (tree?.stash?.items || []).forEach((i) => walk(i, 'STASH'));
    (tree?.pockets?.items || []).forEach((i) => walk(i, 'BOLSOS'));
    for (const [key, s] of Object.entries(tree?.slots || {})) {
      if (s.item) walk(s.item, `EQUIPADO (${(s.def?.label || key).toUpperCase()})`);
    }
    return out;
  }

  function cardFor({ it, onde }, atual) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'pick-card' + (atual ? ' is-current' : '');
    b.style.setProperty('--rar', RAR[it.rarity] || RAR.comum);
    b.dataset.id = it.id;
    let extra = '';
    if (it.ammo_cur != null && it.mag_capacity) extra = `${it.ammo_cur}/${it.mag_capacity}`;
    else if (it.quantity > 1) extra = `x${it.quantity}`;
    else if (it.durability != null && it.durability_max) extra = `${it.durability}/${it.durability_max}`;
    b.innerHTML =
      `<img class="pick-card__img" src="${MEDIA}${it.image}" alt="">` +
      `<span class="pick-card__name">${it.name}</span>` +
      `<span class="pick-card__meta"><span>${extra}</span><span>${it.weight.toFixed(1).replace('.', ',')} kg</span></span>` +
      `<span class="pick-card__where">${onde}</span>`;
    return b;
  }

  /** Fluxo 1 — clique num slot de equipamento: lista o que serve ali. */
  function openEquipPicker(slotKey) {
    if (!pick.el || !tree) return;
    const slot = tree.slots?.[slotKey];
    const aceita = SLOT_ACCEPTS[slotKey] || [];
    const atualId = slot?.item?.id ?? null;

    pickState = { mode: 'equip', slotKey };
    pick.title.textContent = (slot?.def?.label || slotKey).toUpperCase();
    pick.sub.textContent = aceita.join(' · ').toUpperCase();
    pick.slots.hidden = true;
    pick.unequip.hidden = !atualId;

    const candidatos = flatItems().filter(({ it }) => aceita.includes(it.category));
    renderPickGrid(candidatos, atualId, async (id) => {
      const res = await api(EP.equip, { instance_id: id, slot_key: slotKey });
      afterPick(res, () => openEquipPicker(slotKey));
    });
    pick.el.hidden = false;
  }

  /** Fluxo 2 — "ANEXAR À ARMA": bocais da arma + acessórios compatíveis. */
  function openAttachPicker(weaponId, bocalKey = null) {
    if (!pick.el || !tree) return;
    const arma = findInstance(weaponId);
    if (!arma || !arma.attach_slots?.length) { toast('Esta arma não aceita acessórios.'); return; }

    const bocais = arma.attach_slots;
    const bocal = bocais.find((b) => b.key === bocalKey) || bocais[0];
    pickState = { mode: 'attach', weaponId, bocal: bocal.key };

    pick.title.textContent = `ANEXAR À ARMA · ${arma.name}`;
    pick.sub.textContent = (bocal.label || bocal.key).toUpperCase();

    // abas dos bocais, marcando os já preenchidos
    pick.slots.hidden = false;
    pick.slots.innerHTML = '';
    for (const b of bocais) {
      const inst = (arma.attachments || []).find((a) => a.slot_key === b.key);
      const t = document.createElement('button');
      t.type = 'button';
      t.className = 'pick__slot' + (b.key === bocal.key ? ' is-active' : '') + (inst ? ' is-filled' : '');
      t.innerHTML = `${b.label || b.key}<small>${inst ? inst.short || inst.name : 'vazio'}</small>`;
      t.addEventListener('click', () => openAttachPicker(weaponId, b.key));
      pick.slots.appendChild(t);
    }

    const instalado = (arma.attachments || []).find((a) => a.slot_key === bocal.key) || null;
    pick.unequip.hidden = !instalado;

    const aceita = bocal.accepts || [];
    const candidatos = flatItems().filter(({ it }) =>
      it.id !== weaponId && (aceita.includes(it.subtype) || aceita.includes(it.category)));
    renderPickGrid(candidatos, instalado?.id ?? null, async (id) => {
      const res = await api(EP.attach, { instance_id: id, weapon_id: weaponId, slot_key: bocal.key });
      afterPick(res, () => openAttachPicker(weaponId, bocal.key));
    });
    pick.el.hidden = false;
  }

  function renderPickGrid(lista, atualId, onPick) {
    pick.grid.innerHTML = '';
    pick.empty.hidden = lista.length > 0;
    for (const entry of lista) {
      const card = cardFor(entry, entry.it.id === atualId);
      card.addEventListener('click', () => onPick(entry.it.id));
      pick.grid.appendChild(card);
    }
  }

  /** Aplica o resultado de uma ação do modal: atualiza a árvore e reabre com dados frescos. */
  function afterPick(res, reopen) {
    if (res?.tree) { tree = res.tree; render(); }
    if (res?.ok === false) { toast(res.error || 'Ação inválida.'); return; }
    refreshWallet();
    reopen();
  }

  pick.unequip?.addEventListener('click', async () => {
    if (!pickState) return;
    if (pickState.mode === 'equip') {
      const atual = tree.slots?.[pickState.slotKey]?.item;
      if (!atual) return;
      const res = await api(EP.equip, { instance_id: atual.id, slot_key: 'stash' });
      afterPick(res, () => openEquipPicker(pickState.slotKey));
    } else {
      const arma = findInstance(pickState.weaponId);
      const inst = (arma?.attachments || []).find((a) => a.slot_key === pickState.bocal);
      if (!inst) return;
      const res = await api(EP.attach, { instance_id: inst.id });   // sem weapon_id = volta ao stash
      afterPick(res, () => openAttachPicker(pickState.weaponId, pickState.bocal));
    }
  });
  pick.el?.querySelector('[data-pick-close]')?.addEventListener('click', closePick);
  pick.el?.addEventListener('click', (e) => { if (e.target === pick.el) closePick(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePick(); });

  /** O painel central de acessórios foi substituído pelo modal ANEXAR À ARMA. */
  function renderWeaponMods() { /* no-op */ }

  // ---------------------------------------------------------------- carteira
  async function refreshWallet() {
    try {
      const res = await fetch(EP.tree, { headers: { Accept: 'application/json' } });
      const j = await res.json();
      const cur = j?.player?.currencies;
      if (cur) {
        root.querySelector('.w-cur--credits b')?.replaceChildren(cur.credits.toLocaleString('pt-BR'));
        root.querySelector('.w-cur--cash b')?.replaceChildren(cur.cash.toLocaleString('pt-BR'));
        root.querySelector('.w-cur--points b')?.replaceChildren(cur.points.toLocaleString('pt-BR'));
      }
    } catch { /* silencioso */ }
  }

  // ---------------------------------------------------------------- menu de contexto
  /**
   * Menu do HUD no botão direito, no lugar do menu do navegador.
   * As ações ainda não fazem nada (pedido explícito): ficam visíveis e desabilitadas.
   */
  let ctxEl = null;
  const closeCtx = () => { ctxEl?.remove(); ctxEl = null; };

  function openCtx(x, y, item) {
    closeCtx();
    const ehArma = item.category === 'weapon' || item.category === 'pistol';
    const temBocal = ehArma && (item.attach_slots || []).length > 0;
    const acoes = [
      { icon: 'search',        label: 'Inspecionar', on: null },
      { icon: 'shield_person', label: 'Equipar',     on: null },
      ...(temBocal ? [{ icon: 'build', label: 'Anexar à Arma', on: () => openAttachPicker(item.id) }] : []),
      { icon: 'sell',          label: 'Vender',      on: null },
    ];
    const el = document.createElement('div');
    el.className = 'ctxmenu';
    el.setAttribute('role', 'menu');
    el.innerHTML =
      `<p class="ctxmenu__head">${item.name}</p>` +
      acoes.map((a, i) =>
        `<button type="button" class="ctxmenu__item" role="menuitem" data-i="${i}"${a.on ? '' : ' disabled'}>
           <span class="ms" aria-hidden="true">${a.icon}</span>${a.label}
         </button>`).join('');
    el.querySelectorAll('.ctxmenu__item').forEach((btn) => {
      const acao = acoes[+btn.dataset.i];
      if (!acao?.on) return;
      btn.addEventListener('click', () => { closeCtx(); acao.on(); });
    });
    document.body.appendChild(el);

    // mantém o menu dentro da janela
    const r = el.getBoundingClientRect();
    el.style.left = `${Math.min(x, window.innerWidth - r.width - 8)}px`;
    el.style.top = `${Math.min(y, window.innerHeight - r.height - 8)}px`;
    ctxEl = el;
  }

  // um único listener na raiz cobre stash, containers, equipamento e bocais
  root.addEventListener('contextmenu', (e) => {
    const alvo = e.target.closest('.item, .wslot');
    if (!alvo) { closeCtx(); return; }
    e.preventDefault();          // troca o menu do navegador pelo do HUD
    const item = findInstance(+alvo.dataset.id);
    if (item) openCtx(e.clientX, e.clientY, item);
  });
  document.addEventListener('pointerdown', (e) => { if (!e.target.closest('.ctxmenu')) closeCtx(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeCtx(); });
  window.addEventListener('blur', closeCtx);

  // ------------------------------------------------------------- filtros do stash
  // Categorias do catálogo agrupadas nos oito botões da trilha.
  const FILTER_MAP = {
    weapon: ['weapon', 'pistol', 'knife'],
    ammo:   ['ammo', 'mag'],
    armor:  ['helmet', 'mask', 'glasses', 'armor', 'clothing', 'armband', 'rig', 'backpack'],
    med:    ['med'],
    food:   ['food', 'drink'],
    mod:    ['sight', 'suppressor', 'grip', 'attachment', 'mod'],
    loot:   ['loot', 'valuable', 'barter', 'key'],
  };
  let filtroAtual = 'all';

  function applyFilter() {
    const cats = FILTER_MAP[filtroAtual];
    root.querySelectorAll('.grid--stash .item').forEach((el) => {
      el.classList.toggle('is-dimmed', !!cats && !cats.includes(el.dataset.cat));
    });
  }

  root.querySelectorAll('[data-filter]').forEach((btn) => {
    btn.addEventListener('click', () => {
      filtroAtual = btn.dataset.filter;
      root.querySelectorAll('[data-filter]').forEach((b) => b.classList.toggle('is-on', b === btn));
      applyFilter();
    });
  });

  // ---------------------------------------------------------------- boot
  async function boot() {
    try {
      const res = await fetch(EP.tree, { headers: { Accept: 'application/json' } });
      const j = await res.json();
      tree = j.tree;
      render();
    } catch (err) {
      toast('Não foi possível carregar o inventário.');
      console.error(err);
    }
  }
  boot();
}
