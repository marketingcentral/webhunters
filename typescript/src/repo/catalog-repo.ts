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
