/**
 * Estado operacional do personagem (PRD v3) — o App\Repo\OperationalRepo do PHP.
 * Portado: leitura, badge, organização, meets e a pré-visualização de dev.
 * Pendente: sign()/release() (contratos de facção, tela /faccoes).
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { num } from '../core/helpers';
import type { Dict } from '../core/view';

export interface Operational {
  state: string;
  rank: string;
  role: string | null;
  faction: string | null;
  reputation: Record<string, number>;
  tiers: Record<string, string>;
  locked: Record<string, string | null>;
  base_pref: string | null;
  survivor_since: string | null;
}

export interface Badge {
  label: string;
  sub: string;
  color: string;
  state: string;
  survivor: boolean;
}

export function defaults(): Operational {
  return {
    state: GR.STATE_HUNTER,
    rank: GR.RANKS[0]!,
    role: null,
    faction: null,
    reputation: { aegis: 0, frontier: 0 },
    tiers: { aegis: 'SEM CONTRATO', frontier: 'SEM CONTRATO' },
    locked: { aegis: null, frontier: null },
    base_pref: null,
    survivor_since: null,
  };
}

export async function fromRow(userId: number, row: Dict): Promise<Operational> {
  const op = defaults();
  const state = String(row.operational_state ?? '').toUpperCase();
  if (GR.STATES.includes(state)) op.state = state;
  const rank = String(row.hunter_rank ?? '').toUpperCase();
  if (GR.rankIndex(rank) >= 0) op.rank = rank;
  op.role = GR.role((row.hunter_role as string) ?? null);
  op.base_pref = row.base_pref != null ? String(row.base_pref) : null;
  op.survivor_since = row.survivor_since != null ? String(row.survivor_since) : null;

  for (const f of await factions(userId)) {
    const slug = String(f.faction).toLowerCase();
    if (!GR.FACTIONS[slug]) continue;
    const rep = Number(f.reputation) || 0;
    op.reputation[slug] = rep;
    op.locked[slug] = (f.locked_until as string) ?? null;
    if (Number(f.contract_active)) {
      op.faction = slug;
      op.tiers[slug] = GR.repTier(rep);
    } else if (rep > 0) {
      op.tiers[slug] = GR.repTier(rep);
    }
  }
  // Coerência: só existe Mercenário com contrato; sem contrato o estado é Hunter.
  if (op.state === GR.STATE_MERCENARY && op.faction === null) op.state = GR.STATE_HUNTER;
  return op;
}

export async function forUser(userId: number): Promise<Operational> {
  if (userId <= 0) return defaults();
  const row = (await DB.one('SELECT * FROM players WHERE user_id = :u', { u: userId })) ?? {};
  return fromRow(userId, row);
}

async function factions(userId: number): Promise<Dict[]> {
  try {
    return await DB.all(
      `SELECT faction, reputation, tier, contract_active, locked_until
         FROM player_factions WHERE user_id = :u`,
      { u: userId },
    );
  } catch {
    return [];
  }
}

export function badge(op: Operational, level = 0): Badge {
  const state = op.state ?? GR.STATE_HUNTER;
  if (state === GR.STATE_SURVIVOR) {
    return { label: 'SOBREVIVENTE', sub: 'SEM EXTRAÇÃO', color: 'white', state, survivor: true };
  }
  const f = GR.faction(op.faction);
  if (state === GR.STATE_MERCENARY && f !== null) {
    const rep = op.reputation[op.faction!] ?? 0;
    return { label: 'MERCENÁRIO · ' + f.label, sub: 'REP ' + num(rep), color: f.color, state, survivor: false };
  }
  return { label: 'HUNTER COMMAND · ' + (op.rank ?? GR.RANKS[0]), sub: 'NÍVEL ' + num(level), color: 'lime', state, survivor: false };
}

export function organization(op: Operational): string {
  if (op.state === GR.STATE_SURVIVOR) return 'NENHUMA · SEM BANDEIRA';
  const f = GR.faction(op.faction);
  if (op.state === GR.STATE_MERCENARY && f !== null) return f.label;
  return 'HUNTER COMMAND';
}

export function meets(op: Operational, fac: string | null, tier: string | null = null): boolean {
  if (fac === null || fac === '') return true;
  const f = fac.toLowerCase();
  if (op.faction !== f) return false;
  if (tier === null || tier === '') return true;
  return GR.tierMeets(String(op.tiers[f] ?? ''), tier);
}

function emHoras(h: number): string {
  return new Date(Date.now() + h * 3600_000).toISOString().slice(0, 19).replace('T', ' ');
}

/** Pré-visualização de estado (?estado=...), só em localhost — ver Controller.player(). */
export function preview(op: Operational, estado: string | null): Operational {
  switch (String(estado ?? '').toLowerCase()) {
    case 'hunter':
      return { ...op, state: GR.STATE_HUNTER, rank: 'CABO', role: 'MEDICAL', faction: null, base_pref: 'alpha' };
    case 'aegis':
      return {
        ...op, state: GR.STATE_MERCENARY, faction: 'aegis',
        reputation: { aegis: 1850, frontier: 0 }, tiers: { aegis: 'CONFIÁVEL', frontier: 'SEM CONTRATO' },
      };
    case 'frontier':
      return {
        ...op, state: GR.STATE_MERCENARY, faction: 'frontier',
        reputation: { aegis: 0, frontier: 2430 }, tiers: { aegis: 'SEM CONTRATO', frontier: 'CONFIÁVEL' },
        locked: { aegis: emHoras(7 + 32 / 60), frontier: null },
      };
    case 'sobrevivente':
      return { ...op, state: GR.STATE_SURVIVOR, faction: null, survivor_since: emHoras(-47 / 60) };
    default:
      return op;
  }
}

// ------------------------------------------------------------------ contratos (lote jogador-mundo)

/** Momento em segundos de um DATETIME do D1 (UTC, sem fuso na string). NaN se inválido. */
function segundos(s: string): number {
  const t = Date.parse(s.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z'));
  return t / 1000;
}

export interface SignResult {
  ok: boolean;
  error?: string;
  broke?: string;
}

/**
 * Assina contrato com uma facção (OperationalRepo::sign do PHP). Romper um contrato ativo para
 * assinar outro zera a reputação da facção abandonada. A transação do PHP vira um batch().
 */
export async function sign(userId: number, faction: string, confirm: boolean): Promise<SignResult> {
  const f = faction.toLowerCase();
  if (!GR.FACTIONS[f]) return { ok: false, error: 'Facção desconhecida.' };
  const op = await forUser(userId);

  if (op.state === GR.STATE_SURVIVOR) {
    return { ok: false, error: 'Sobreviventes não têm bandeira. Nenhuma facção assina com quem está fora das cadeias de comando.' };
  }
  if (op.faction === f) return { ok: false, error: 'Este contrato já está ativo.' };
  const lock = op.locked[f] ?? null;
  if (lock !== null && segundos(String(lock)) > Date.now() / 1000) {
    return { ok: false, error: GR.FACTIONS[f]!.label.toUpperCase() + ' — ACESSO TEMPORARIAMENTE BLOQUEADO' };
  }
  if (!confirm) return { ok: false, error: 'Confirmação obrigatória.' };

  const broke = op.faction;
  try {
    const stmts: D1PreparedStatement[] = [];
    if (broke !== null) {
      // Romper zera a reputação da facção abandonada. É a regra que dá peso à troca.
      stmts.push(DB.prepare(
        'UPDATE player_factions SET contract_active = 0, reputation = 0, tier = NULL WHERE user_id = :u AND faction = :f',
        { u: userId, f: broke },
      ));
    }
    stmts.push(DB.prepare(
      `INSERT INTO player_factions (user_id, faction, reputation, tier, contract_active)
       VALUES (:u, :f, 0, :t, 1)
       ON CONFLICT(user_id, faction) DO UPDATE SET contract_active = 1, tier = excluded.tier`,
      { u: userId, f, t: GR.repTier(0) },
    ));
    stmts.push(DB.prepare('UPDATE players SET operational_state = :s WHERE user_id = :u', { s: GR.STATE_MERCENARY, u: userId }));
    await DB.batch(stmts);
  } catch (e) {
    console.error('[extracthunters] sign:', e);
    return { ok: false, error: 'Sistema de facções indisponível neste servidor. Tente novamente mais tarde.' };
  }
  return { ok: true, broke: broke ?? '' };
}

/** Encerra o contrato atual e devolve ao Hunter Command (sem zerar nada: só a troca zera). */
export async function release(userId: number): Promise<boolean> {
  try {
    await DB.batch([
      DB.prepare('UPDATE player_factions SET contract_active = 0 WHERE user_id = :u', { u: userId }),
      DB.prepare('UPDATE players SET operational_state = :s WHERE user_id = :u AND operational_state = :m', {
        s: GR.STATE_HUNTER, u: userId, m: GR.STATE_MERCENARY,
      }),
    ]);
    return true;
  } catch (e) {
    console.error('[extracthunters] release:', e);
    return false;
  }
}
