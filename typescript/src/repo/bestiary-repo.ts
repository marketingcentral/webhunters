/**
 * Registro do bestiário por jogador (`player_bestiary`, patch v11) — o App\Repo\BestiaryRepo.
 */
import * as DB from '../core/database';

export const ESTUDO_ABATES = 5;
export const EVENTOS = ['avistado', 'abate', 'amostra'];

export interface Registro {
  sightings: number;
  kills: number;
  studied: boolean;
  first_seen_at: string | null;
}

export async function registros(userId: number): Promise<Record<string, Registro>> {
  if (userId <= 0) return {};
  try {
    const out: Record<string, Registro> = {};
    const rows = await DB.all(
      `SELECT creature_slug, sightings, kills, studied, first_seen_at
         FROM player_bestiary WHERE user_id = :u`,
      { u: userId },
    );
    for (const r of rows) {
      out[String(r.creature_slug)] = {
        sightings: Number(r.sightings),
        kills: Number(r.kills),
        studied: Boolean(Number(r.studied)),
        first_seen_at: r.first_seen_at !== null ? String(r.first_seen_at) : null,
      };
    }
    return out;
  } catch {
    return {};
  }
}

/** desconhecido -> avistado -> estudado (estudo = amostra Icarus ou ESTUDO_ABATES abates). */
export function nivel(r: Registro | null, liberarTudo: boolean): string {
  if (liberarTudo) return 'estudado';
  if (r === null || (r.sightings + r.kills === 0 && !r.studied)) return 'desconhecido';
  return r.studied || r.kills >= ESTUDO_ABATES ? 'estudado' : 'avistado';
}

export async function registrar(userId: number, slug: string, evento: string, validos: string[]): Promise<boolean> {
  if (userId <= 0 || !validos.includes(slug) || !EVENTOS.includes(evento)) return false;
  const col = ({ avistado: 'sightings', abate: 'kills', amostra: 'studied' } as Record<string, string>)[evento]!;
  const inc = col === 'studied' ? 'studied = 1' : `${col} = ${col} + 1`;
  await DB.run(
    `INSERT INTO player_bestiary (user_id, creature_slug, sightings, kills, studied, first_seen_at)
     VALUES (:u, :s, :a, :k, :st, datetime('now'))
     ON CONFLICT(user_id, creature_slug) DO UPDATE SET ${inc}, first_seen_at = COALESCE(first_seen_at, datetime('now'))`,
    { u: userId, s: slug, a: col === 'sightings' ? 1 : 0, k: col === 'kills' ? 1 : 0, st: col === 'studied' ? 1 : 0 },
  );
  return true;
}
