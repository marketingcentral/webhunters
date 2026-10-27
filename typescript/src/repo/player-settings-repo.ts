/**
 * Preferências por conta (`player_settings`) — o App\Repo\SettingsRepo do PHP.
 */
import { memo } from '../core/context';
import * as DB from '../core/database';

export function forUser(uid: number): Promise<Record<string, string>> {
  return memo('player_settings:' + uid, async () => {
    if (uid <= 0) return {};
    try {
      const rows = await DB.all('SELECT setting_key, value FROM player_settings WHERE user_id = :u', { u: uid });
      return Object.fromEntries(rows.map((r) => [String(r.setting_key), String(r.value)]));
    } catch {
      return {};
    }
  });
}

/** Grava (upsert) as chaves; ON DUPLICATE KEY UPDATE do MySQL vira ON CONFLICT no SQLite. */
export async function save(uid: number, values: Record<string, string | boolean | number>): Promise<number> {
  const pares = Object.entries(values);
  if (uid <= 0 || !pares.length) return 0;
  try {
    await DB.batch(
      pares.map(([k, v]) =>
        DB.prepare(
          `INSERT INTO player_settings (user_id, setting_key, value) VALUES (:u, :k, :v)
           ON CONFLICT(user_id, setting_key) DO UPDATE SET value = excluded.value`,
          { u: uid, k: k.slice(0, 64), v: (typeof v === 'boolean' ? (v ? '1' : '0') : String(v)).slice(0, 160) },
        ),
      ),
    );
    return pares.length;
  } catch (e) {
    console.error('[extracthunters] player_settings.save:', e);
    return 0;
  }
}

export async function reset(uid: number, keys: string[]): Promise<number> {
  const ks = [...new Set(keys.filter((k) => k !== ''))];
  if (uid <= 0 || !ks.length) return 0;
  try {
    const r = await DB.run(
      `DELETE FROM player_settings WHERE user_id = :u AND setting_key IN (${ks.map((_, i) => ':k' + i).join(',')})`,
      { u: uid, ...Object.fromEntries(ks.map((k, i) => ['k' + i, k])) },
    );
    return DB.changes(r);
  } catch {
    return 0;
  }
}
