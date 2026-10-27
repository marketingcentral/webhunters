/**
 * Catálogo de conteúdo — partes do App\Repo\CatalogRepo do PHP que as telas portadas usam.
 * (equipmentSlots() está em inventory-repo.ts, junto de quem o consome.)
 */
import { memo } from '../core/context';
import * as DB from '../core/database';
import type { Dict } from '../core/view';

/** Notícias publicadas até hoje (CURDATE() do MySQL -> date('now') do SQLite). */
export function news(): Promise<Dict[]> {
  return memo('catalog.news', async () => {
    const rows = await DB.all(
      `SELECT * FROM news WHERE published IS NOT NULL AND published <= date('now')
        ORDER BY sort_order, id`,
    );
    for (const n of rows) {
      let b: unknown = null;
      try {
        b = JSON.parse(String(n.body ?? 'null'));
      } catch {
        b = null;
      }
      n.body = Array.isArray(b) ? b : (n.body ?? '') !== '' ? [String(n.body)] : [];
    }
    return rows;
  });
}

/* ---- lote jogador-conta: CatalogRepo::items/itemsById/item/achievements/skills ---- */

/** CatalogRepo::decodeItem — colunas JSON do item viram objeto/array. */
export function decodeItem(row: Dict): Dict {
  for (const k of ['attach_slots', 'fits_calibers', 'meta']) {
    if (typeof row[k] === 'string') {
      try {
        row[k] = JSON.parse(row[k] as string);
      } catch {
        row[k] = null;
      }
    }
  }
  return row;
}

/** Todos os itens do catálogo (cacheado por requisição). */
export function items(): Promise<Dict[]> {
  return memo('catalog.items', async () =>
    (await DB.all('SELECT * FROM items ORDER BY category, name')).map((r) => decodeItem(r as Dict)),
  );
}

/** Itens indexados por id. */
export function itemsById(): Promise<Map<number, Dict>> {
  return memo('catalog.itemsById', async () => {
    const out = new Map<number, Dict>();
    for (const it of await items()) out.set(Number(it.id), it);
    return out;
  });
}

export async function item(id: number): Promise<Dict | null> {
  return (await itemsById()).get(id) ?? null;
}

/** Conquistas com as recompensas embutidas (uma consulta só para as recompensas). */
export function achievements(): Promise<Dict[]> {
  return memo('catalog.achievements', async () => {
    const [rows, rewards] = await Promise.all([
      DB.all('SELECT * FROM achievements ORDER BY sort_order, id'),
      DB.all(
        `SELECT r.achievement_id, r.kind, r.amount, r.item_id, i.name AS item_name, i.image AS item_image
           FROM achievement_rewards r LEFT JOIN items i ON i.id = r.item_id ORDER BY r.id`,
      ),
    ]);
    for (const a of rows as Dict[]) {
      a.rewards = rewards
        .filter((r) => Number(r.achievement_id) === Number(a.id))
        .map(({ achievement_id: _x, ...r }) => r);
    }
    return rows as Dict[];
  });
}

/** Definição dos nós da árvore de habilidades. */
export function skills(): Promise<Dict[]> {
  return memo('catalog.skills', () => DB.all('SELECT * FROM skills ORDER BY category, tier, col') as Promise<Dict[]>);
}

/* ---- lote jogador-progresso: CatalogRepo::shopOffers/missions ---- */

/** Ofertas da loja com o item embutido. */
export function shopOffers(): Promise<Dict[]> {
  return memo('catalog.shop', () =>
    DB.all(
      `SELECT o.*, i.name, i.short_name, i.image, i.category AS item_category, i.rarity,
              i.description, i.grid_w, i.grid_h, i.weight_kg
         FROM shop_offers o JOIN items i ON i.id = o.item_id
        ORDER BY o.category, o.sort_order, o.id`,
    ) as Promise<Dict[]>,
  );
}

/** Missões com as recompensas embutidas (uma consulta só para as recompensas). */
export function missions(): Promise<Dict[]> {
  return memo('catalog.missions', async () => {
    const [rows, rewards] = await Promise.all([
      DB.all('SELECT * FROM missions ORDER BY sort_order, id'),
      DB.all(
        `SELECT r.mission_id, r.kind, r.amount, r.item_id, i.name AS item_name, i.image AS item_image
           FROM mission_rewards r LEFT JOIN items i ON i.id = r.item_id ORDER BY r.id`,
      ),
    ]);
    for (const m of rows as Dict[]) {
      m.rewards = rewards
        .filter((r) => Number(r.mission_id) === Number(m.id))
        .map(({ mission_id: _x, ...r }) => r);
    }
    return rows as Dict[];
  });
}
