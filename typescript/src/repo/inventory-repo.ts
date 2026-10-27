/**
 * Inventário estilo Tarkov — o App\Repo\InventoryRepo do PHP.
 *
 * Diferença de transação: no PHP move() rodava dentro de Database::transaction() com
 * leituras e escritas intercaladas. O D1 não abre transação interativa, então aqui as
 * leituras e a validação acontecem primeiro e as escritas vão juntas num batch() atômico.
 * Para um único jogador mexendo no próprio inventário o efeito é o mesmo; duas abas
 * soltando item no mesmo instante podem, no pior caso, sobrepor uma célula (o PHP também
 * não travava linha com SELECT ... FOR UPDATE).
 */
import { memo } from '../core/context';
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import * as PlayerRepo from './player-repo';

export const STASH_COLS = 8;
export const STASH_ROWS = 11;
export const POCKET_COLS = 4;

type Res = { ok: boolean; error?: string; [k: string]: unknown };

const SELECT_ITEM = `SELECT pi.*, it.slug, it.name, it.short_name, it.category, it.subtype, it.slot_type,
        it.grid_w, it.grid_h, it.weight_kg, it.rarity, it.image, it.durability_max,
        it.grid_cols, it.grid_rows, it.ammo_caliber, it.mag_capacity, it.attach_slots,
        it.fits_calibers, it.stackable, it.max_stack, it.description
   FROM player_items pi JOIN items it ON it.id = pi.item_id`;

export function equipmentSlots(): Promise<Dict[]> {
  return memo('catalog.slots', async () => {
    const rows = await DB.all('SELECT * FROM equipment_slots ORDER BY sort_order');
    for (const s of rows) {
      try {
        s.accepts = JSON.parse(String(s.accepts ?? '[]')) || [];
      } catch {
        s.accepts = [];
      }
    }
    return rows;
  });
}

function int(v: unknown): number | null {
  return v === null || v === undefined ? null : Number(v);
}

function shape(r: Dict): Dict {
  let attach: unknown = null;
  if (typeof r.attach_slots === 'string') {
    try {
      attach = JSON.parse(r.attach_slots);
    } catch {
      attach = null;
    }
  }
  return {
    id: Number(r.id),
    item_id: Number(r.item_id),
    slug: r.slug,
    name: r.name,
    short: r.short_name,
    category: r.category,
    subtype: r.subtype,
    slot_type: r.slot_type,
    container: r.container,
    slot_key: r.slot_key,
    parent_id: int(r.parent_id),
    x: int(r.pos_x),
    y: int(r.pos_y),
    w: Number(r.grid_w),
    h: Number(r.grid_h),
    rotated: Boolean(Number(r.rotated)),
    weight: Number(r.weight_kg),
    rarity: r.rarity,
    image: r.image,
    quantity: Number(r.quantity),
    stackable: Boolean(Number(r.stackable)),
    max_stack: Number(r.max_stack),
    durability: int(r.durability_cur),
    durability_max: int(r.durability_max),
    ammo_cur: int(r.ammo_cur),
    mag_capacity: int(r.mag_capacity),
    ammo_caliber: r.ammo_caliber,
    grid_cols: int(r.grid_cols),
    grid_rows: int(r.grid_rows),
    attach_slots: attach,
    description: r.description,
    contents: [],
    attachments: [],
  };
}

export async function tree(userId: number): Promise<Dict> {
  const [rows, slotDefs, weight, value] = await Promise.all([
    DB.all(SELECT_ITEM + ' WHERE pi.user_id = :id ORDER BY pi.id', { id: userId }),
    equipmentSlots(),
    weightOf(userId),
    totalValue(userId),
  ]);
  const items = new Map<number, Dict>();
  for (const r of rows) items.set(Number(r.id), shape(r));

  // Objeto (e não Map) para o JSON sair igual ao do PHP: { "head": {def, item}, ... }
  const slots: Record<string, Dict> = {};
  for (const s of slotDefs) slots[String(s.slot_key)] = { def: s, item: null };

  const stash: Dict[] = [];
  const pockets: Dict[] = [];
  for (const it of items.values()) {
    if (it.container === 'equip' && slots[String(it.slot_key)]) slots[String(it.slot_key)]!.item = it;
    else if (it.container === 'stash') stash.push(it);
    else if (it.container === 'pockets') pockets.push(it);
  }
  // aninha grid/attach nos pais (as referências são as mesmas: o PHP usava &$items[$id])
  for (const it of items.values()) {
    if ((it.container === 'grid' || it.container === 'attach') && it.parent_id && items.has(it.parent_id)) {
      const key = it.container === 'attach' ? 'attachments' : 'contents';
      (items.get(it.parent_id)![key] as Dict[]).push(it);
    }
  }
  return {
    stash: { cols: STASH_COLS, rows: STASH_ROWS, items: stash },
    pockets: { cols: POCKET_COLS, rows: 1, items: pockets },
    slots,
    weight,
    value,
  };
}

export async function weightOf(userId: number): Promise<number> {
  return Number(
    await DB.value(
      'SELECT COALESCE(SUM(it.weight_kg * pi.quantity),0) FROM player_items pi JOIN items it ON it.id = pi.item_id WHERE pi.user_id = :id',
      { id: userId },
    ),
  ) || 0;
}

export async function totalValue(userId: number): Promise<number> {
  return Math.trunc(
    Number(
      await DB.value(
        'SELECT COALESCE(SUM(it.base_price * pi.quantity),0) FROM player_items pi JOIN items it ON it.id = pi.item_id WHERE pi.user_id = :id',
        { id: userId },
      ),
    ) || 0,
  );
}

export async function instance(userId: number, instanceId: number): Promise<Dict | null> {
  const r = await DB.one(SELECT_ITEM + ' WHERE pi.user_id = :u AND pi.id = :id', { u: userId, id: instanceId });
  return r ? shape(r) : null;
}

export async function gridDims(userId: number, container: string, parentId: number | null): Promise<[number, number] | null> {
  if (container === 'stash') return [STASH_COLS, STASH_ROWS];
  // Bolsos: 4 células fixas do personagem, sem item pai.
  if (container === 'pockets') return [POCKET_COLS, 1];
  if (container === 'grid' && parentId) {
    const p = await instance(userId, parentId);
    if (p && p.grid_cols && p.grid_rows) return [Number(p.grid_cols), Number(p.grid_rows)];
  }
  return null;
}

async function occupancy(userId: number, container: string, parentId: number | null, excludeId: number | null): Promise<Map<string, number>> {
  let sql = `SELECT pi.id, pi.pos_x, pi.pos_y, pi.rotated, it.grid_w, it.grid_h
               FROM player_items pi JOIN items it ON it.id = pi.item_id
              WHERE pi.user_id = :u AND pi.container = :c AND pi.pos_x IS NOT NULL`;
  const args: Dict = { u: userId, c: container };
  if (container === 'grid') {
    sql += ' AND pi.parent_id = :p';
    args.p = parentId;
  }
  const occ = new Map<string, number>();
  for (const r of await DB.all(sql, args)) {
    if (excludeId !== null && Number(r.id) === excludeId) continue;
    const rot = Boolean(Number(r.rotated));
    const w = rot ? Number(r.grid_h) : Number(r.grid_w);
    const h = rot ? Number(r.grid_w) : Number(r.grid_h);
    for (let dy = 0; dy < h; dy++) {
      for (let dx = 0; dx < w; dx++) occ.set(`${Number(r.pos_y) + dy}:${Number(r.pos_x) + dx}`, Number(r.id));
    }
  }
  return occ;
}

export async function canPlace(
  userId: number, inst: Dict, container: string, parentId: number | null,
  x: number, y: number, rotated: boolean, excludeId: number | null = null,
): Promise<boolean> {
  const dims = await gridDims(userId, container, parentId);
  if (dims === null) return false;
  const [cols, rows] = dims;
  const w = rotated ? Number(inst.h) : Number(inst.w);
  const h = rotated ? Number(inst.w) : Number(inst.h);
  if (x < 0 || y < 0 || x + w > cols || y + h > rows) return false;
  if (parentId && Number(inst.id) === parentId) return false; // recipiente não entra em si mesmo
  const occ = await occupancy(userId, container, parentId, excludeId);
  for (let dy = 0; dy < h; dy++) {
    for (let dx = 0; dx < w; dx++) if (occ.has(`${y + dy}:${x + dx}`)) return false;
  }
  return true;
}

/**
 * Primeira posição livre: tenta em pé e depois deitado.
 * `extra` são células já prometidas no mesmo lote (ver sendToStashStmt).
 */
export async function firstFree(
  userId: number, container: string, parentId: number | null, w: number, h: number, extra?: Map<string, number>,
): Promise<[number, number, boolean] | null> {
  const dims = await gridDims(userId, container, parentId);
  if (dims === null) return null;
  const [cols, rows] = dims;
  const occ = await occupancy(userId, container, parentId, null);
  if (extra) for (const [k, v] of extra) occ.set(k, v);
  for (const [rot, ww, hh] of [[false, w, h], [true, h, w]] as const) {
    for (let y = 0; y <= rows - hh; y++) {
      for (let x = 0; x <= cols - ww; x++) {
        let free = true;
        for (let dy = 0; dy < hh && free; dy++) {
          for (let dx = 0; dx < ww; dx++) {
            if (occ.has(`${y + dy}:${x + dx}`)) {
              free = false;
              break;
            }
          }
        }
        if (free) return [x, y, rot];
      }
    }
  }
  return null;
}

export async function slotAccepts(slotKey: string, category: string): Promise<boolean> {
  for (const s of await equipmentSlots()) {
    if (s.slot_key === slotKey) return (s.accepts as string[]).includes(category);
  }
  return false;
}

/** UPDATE que devolve um item ao stash na primeira vaga (sem executar). */
async function sendToStashStmt(userId: number, instanceId: number, reservado?: Map<string, number>): Promise<D1PreparedStatement | null> {
  const inst = await instance(userId, instanceId);
  if (inst === null) return null;
  const ff = (await firstFree(userId, 'stash', null, Number(inst.w), Number(inst.h), reservado)) ?? [0, 0, false];
  return DB.prepare(
    `UPDATE player_items SET container='stash', parent_id=NULL, slot_key=NULL, pos_x=:x, pos_y=:y, rotated=:r
      WHERE id=:id AND user_id=:u`,
    { x: ff[0], y: ff[1], r: ff[2] ? 1 : 0, id: instanceId, u: userId },
  );
}

export async function sendToStash(userId: number, instanceId: number): Promise<void> {
  const s = await sendToStashStmt(userId, instanceId);
  if (s) await s.run();
}

export async function move(userId: number, instanceId: number, dest: Dict): Promise<Res> {
  const inst = await instance(userId, instanceId);
  if (inst === null) return { ok: false, error: 'Item não encontrado.' };
  const container = String(dest.container ?? 'stash');
  // whitelist: o valor vem do cliente e vai para a coluna
  if (!['stash', 'pockets', 'grid', 'equip', 'attach'].includes(container)) return { ok: false, error: 'Destino inválido.' };
  // 0 e "" nunca são pai válido: viram null (a FK só aceita id real ou NULL)
  const parentId = dest.parent_id ? Number(dest.parent_id) : null;
  const slotKey = dest.slot_key !== undefined && dest.slot_key !== null && dest.slot_key !== '' ? String(dest.slot_key) : null;
  let rotated = Boolean(dest.rotated);
  if (container === 'grid' && parentId === null) return { ok: false, error: 'Container de destino inválido.' };

  if (container === 'equip') {
    if (slotKey === null || !(await slotAccepts(slotKey, String(inst.category)))) {
      return { ok: false, error: 'Este item não vai neste slot.' };
    }
    // desequipa o que estiver no slot (manda pro stash) e equipa, no mesmo lote
    const current = await DB.one(
      `SELECT id FROM player_items WHERE user_id=:u AND container='equip' AND slot_key=:s AND id<>:me`,
      { u: userId, s: slotKey, me: instanceId },
    );
    const stmts: D1PreparedStatement[] = [];
    if (current) {
      const s = await sendToStashStmt(userId, Number(current.id));
      if (s) stmts.push(s);
    }
    stmts.push(
      DB.prepare(
        `UPDATE player_items SET container='equip', slot_key=:s, parent_id=NULL, pos_x=NULL, pos_y=NULL, rotated=0
          WHERE id=:id AND user_id=:u`,
        { s: slotKey, id: instanceId, u: userId },
      ),
    );
    await DB.batch(stmts);
    return { ok: true };
  }

  if (container === 'attach') return installAttachment(userId, instanceId, parentId, slotKey);

  // stash, bolsos ou grid: exige posição válida
  let x = dest.x !== undefined && dest.x !== null ? Number(dest.x) : null;
  let y = dest.y !== undefined && dest.y !== null ? Number(dest.y) : null;
  if (x === null || y === null) {
    const ff = await firstFree(userId, container, parentId, Number(inst.w), Number(inst.h));
    if (ff === null) return { ok: false, error: 'Sem espaço no destino.' };
    [x, y, rotated] = ff;
  }
  if (!(await canPlace(userId, inst, container, parentId, x, y, rotated, instanceId))) {
    return { ok: false, error: 'Não cabe aqui.' };
  }
  await DB.run(
    `UPDATE player_items SET container=:c, parent_id=:p, slot_key=NULL, pos_x=:x, pos_y=:y, rotated=:r
      WHERE id=:id AND user_id=:u`,
    { c: container, p: parentId, x, y, r: rotated ? 1 : 0, id: instanceId, u: userId },
  );
  return { ok: true };
}

export async function installAttachment(userId: number, instanceId: number, weaponId: number | null, slotKey: string | null): Promise<Res> {
  const att = await instance(userId, instanceId);
  const weapon = weaponId ? await instance(userId, weaponId) : null;
  if (att === null || weapon === null) return { ok: false, error: 'Alvo inválido.' };
  if (!['weapon', 'pistol'].includes(String(weapon.category))) return { ok: false, error: 'Só armas recebem acessórios.' };
  const bocais = (weapon.attach_slots as Dict[] | null) ?? [];
  const slot = bocais.find((b) => (b.key ?? null) === slotKey) ?? null;
  if (slot === null) return { ok: false, error: 'Bocal inexistente.' };
  const accepts = (slot.accepts as string[]) ?? [];
  if (!accepts.includes(String(att.subtype)) && !accepts.includes(String(att.category))) {
    return { ok: false, error: 'Acessório incompatível com este bocal.' };
  }
  // bocal já ocupado? devolve o antigo ao stash, no mesmo lote
  const busy = await DB.one(
    `SELECT id FROM player_items WHERE user_id=:u AND container='attach' AND parent_id=:p AND slot_key=:s AND id<>:me`,
    { u: userId, p: weaponId, s: slotKey, me: instanceId },
  );
  const stmts: D1PreparedStatement[] = [];
  if (busy) {
    const s = await sendToStashStmt(userId, Number(busy.id));
    if (s) stmts.push(s);
  }
  stmts.push(
    DB.prepare(
      `UPDATE player_items SET container='attach', parent_id=:p, slot_key=:s, pos_x=NULL, pos_y=NULL, rotated=0
        WHERE id=:id AND user_id=:u`,
      { p: weaponId, s: slotKey, id: instanceId, u: userId },
    ),
  );
  await DB.batch(stmts);
  return { ok: true };
}

/** Compra na loja: débito condicionado ao saldo + item no stash + recibo, num lote só. */
export async function buy(userId: number, offerId: number): Promise<Res> {
  const offer = await DB.one(
    `SELECT o.*, i.grid_w, i.grid_h, i.durability_max, i.mag_capacity
       FROM shop_offers o JOIN items i ON i.id = o.item_id WHERE o.id = :id`,
    { id: offerId },
  );
  if (offer === null) return { ok: false, error: 'Oferta indisponível.' };
  let price = Number(offer.price);
  if (Number(offer.discount ?? 0) > 0) price = Math.round((price * (100 - Number(offer.discount))) / 100);
  if (!(await PlayerRepo.adjustCurrency(userId, String(offer.currency), -price))) {
    return { ok: false, error: 'Saldo insuficiente.' };
  }
  const ff = (await firstFree(userId, 'stash', null, Number(offer.grid_w), Number(offer.grid_h))) ?? [0, 0, false];
  const [r] = await DB.batch([
    DB.insertStmt('player_items', {
      user_id: userId,
      item_id: Number(offer.item_id),
      container: 'stash',
      pos_x: ff[0],
      pos_y: ff[1],
      rotated: ff[2] ? 1 : 0,
      quantity: 1,
      durability_cur: offer.durability_max !== null ? Number(offer.durability_max) : null,
      ammo_cur: Number(offer.mag_capacity ?? 0) || null,
      acquired_via: 'loja',
    }),
    DB.insertStmt('player_purchases', {
      user_id: userId, item_id: Number(offer.item_id), quantity: 1, currency: String(offer.currency), price,
    }),
  ]);
  return { ok: true, instance_id: Number(r?.meta.last_row_id ?? 0) };
}

// ------------------------------------------------------------------ munição (HUD in-game)

/**
 * Carregador instalado na arma equipada num slot ('primary', 'pistol', ...).
 * A munição de uma arma vive no CARREGADOR anexado, não na arma — por isso o
 * caminho é slot -> arma -> attachment com mag_capacity.
 */
export async function magazineOf(userId: number, slotKey: string): Promise<Dict | null> {
  return DB.one(
    `SELECT mag.*, it.mag_capacity, it.ammo_caliber, it.name, it.short_name
       FROM player_items arma
       JOIN player_items mag ON mag.parent_id = arma.id AND mag.container = 'attach' AND mag.user_id = arma.user_id
       JOIN items it ON it.id = mag.item_id
      WHERE arma.user_id = :u AND arma.container = 'equip' AND arma.slot_key = :s
        AND it.mag_capacity IS NOT NULL
      ORDER BY mag.id LIMIT 1`,
    { u: userId, s: slotKey },
  );
}

/**
 * Grava a munição do carregador. Trunca no intervalo [0, capacidade] — o jogo é a
 * autoridade sobre quantos tiros saíram, mas não sobre o tamanho do carregador.
 */
export async function setAmmo(userId: number, slotKey: string, current: number): Promise<Res> {
  const mag = await magazineOf(userId, slotKey);
  if (mag === null) return { ok: false, error: 'Nenhum carregador nesta arma.' };
  const cap = Number(mag.mag_capacity) | 0;
  const cur = Math.max(0, Math.min(cap, current));
  await DB.run('UPDATE player_items SET ammo_cur = :a WHERE id = :id AND user_id = :u', {
    a: cur, id: Number(mag.id), u: userId,
  });
  return { ok: true, current: cur, capacity: cap };
}

/**
 * Munição de reserva: soma o que existe em OUTROS carregadores do mesmo calibre,
 * em qualquer lugar do inventário (baú, bolsos, contêineres, outras armas).
 */
export async function reserveFor(userId: number, caliber: string | null | undefined, exceptInstanceId = 0): Promise<number> {
  if (caliber === null || caliber === undefined || caliber === '') return 0;
  return Number(
    (await DB.value(
      `SELECT COALESCE(SUM(pi.ammo_cur), 0)
         FROM player_items pi JOIN items it ON it.id = pi.item_id
        WHERE pi.user_id = :u AND it.ammo_caliber = :c
          AND it.mag_capacity IS NOT NULL AND pi.id <> :ex`,
      { u: userId, c: caliber, ex: exceptInstanceId },
    )) ?? 0,
  ) | 0;
}
