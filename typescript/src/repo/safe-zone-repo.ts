/**
 * SAFE ZONE — perímetro protegido e papéis civis (o App\Repo\SafeZoneRepo do PHP).
 *
 * O estado operacional continua mandando:
 *   HUNTER     entra em qualquer zona;
 *   MERCENARY  entra nas governamentais como visitante (papéis do Hunter Command ficam fora);
 *   SURVIVOR   nenhuma base do governo o aceita — só a ZONA FRANCA, tolerada pela Frontier.
 *
 * Um papel ativo por vez, com turno de GR.SAFE_SHIFT_MIN minutos. Datas gravadas em UTC
 * (datetime('now') do SQLite); o PHP gravava no fuso do servidor.
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import type { Dict } from '../core/view';
import type { Operational } from './operational-repo';

/** DATETIME do D1 (UTC) -> segundos Unix. NaN se inválido. */
function segundos(s: string): number {
  return Date.parse(s.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z')) / 1000;
}

function meta(m: unknown): Dict {
  if (m && typeof m === 'object' && !Array.isArray(m)) return m as Dict;
  if (typeof m === 'string' && m !== '') {
    try {
      const d = JSON.parse(m);
      if (d && typeof d === 'object') return d as Dict;
    } catch {
      /* meta inválido = vazio, como no PHP */
    }
  }
  return {};
}

const lista = (v: unknown): unknown[] => (Array.isArray(v) ? v : v && typeof v === 'object' ? Object.values(v) : []);

/** As zonas conhecidas, já com o que o jogador pode ou não fazer em cada uma. */
export async function zones(op: Operational): Promise<Dict[]> {
  let rows: Dict[];
  try {
    rows = await DB.all('SELECT * FROM safe_zones ORDER BY sort_order, id');
  } catch {
    return [];
  }

  const survivor = (op.state ?? '') === GR.STATE_SURVIVOR;
  return rows.map((r) => {
    const m = meta(r.meta);
    const kind = String(r.kind ?? GR.SAFE_ZONE_GOV);
    const gov = kind === GR.SAFE_ZONE_GOV;

    // A regra que dói: sem bandeira, nenhuma base do governo abre o portão.
    const blocked = survivor && gov;
    const status = String(r.status ?? 'ABERTA').toUpperCase();
    const closed = status === 'FECHADA';

    return {
      slug: String(r.slug),
      name: String(r.name),
      kind,
      gov,
      base_slug: r.base_slug !== null && r.base_slug !== undefined ? String(r.base_slug) : null,
      map_id: r.map_id !== null && r.map_id !== undefined ? Number(r.map_id) | 0 : null,
      region: String(r.region ?? ''),
      status,
      status_color: GR.SAFE_ZONE_STATUS[status] ?? 'muted',
      occupancy: String(r.occupancy ?? 'MODERADA').toUpperCase(),
      occ_color: GR.occupancyColor(String(r.occupancy ?? '')),
      capacity: Number(r.capacity ?? 0) | 0,
      protection: String(r.protection ?? ''),
      description: String(r.description ?? ''),
      services: lista(m.services),
      rules: lista(m.rules),
      blocked: blocked || closed,
      block_reason: blocked
        ? 'SEM BANDEIRA: NENHUMA BASE DO GOVERNO RESPONDE POR ESTE OPERADOR.'
        : closed ? 'ZONA FECHADA NESTE CICLO.' : '',
      access: blocked ? 'BLOQUEADA' : closed ? 'FECHADA' : gov ? (survivor ? 'BLOQUEADA' : 'LIBERADA') : 'TOLERADA',
    };
  });
}

/** O papel civil ativo do jogador, ou null. `remaining` = segundos que sobram do turno. */
export async function current(userId: number): Promise<Dict | null> {
  if (userId <= 0) return null;
  let row: Dict | null;
  try {
    row = await DB.one('SELECT * FROM player_safe_roles WHERE user_id = :u AND status = :s', { u: userId, s: 'ATIVO' });
  } catch {
    return null;
  }
  if (row === null) return null;
  const def = GR.safeRole(String(row.role));
  if (def === null) return null;
  const fim = row.shift_ends_at !== null && row.shift_ends_at !== undefined ? segundos(String(row.shift_ends_at)) : null;
  return {
    role: def.key,
    label: def.label,
    color: def.color,
    ms: def.ms,
    summary: def.summary,
    zone: String(row.zone_slug),
    started_at: String(row.started_at),
    ends_at: fim !== null ? String(row.shift_ends_at) : null,
    remaining: fim !== null && !Number.isNaN(fim) ? Math.max(0, Math.floor(fim - Date.now() / 1000)) : null,
    served: Number(row.served ?? 0) | 0,
  };
}

/** Requisito em uma linha, para o card não precisar de tabela. */
function requisito(req: GR.SafeRoleReq): string {
  const partes: string[] = [];
  if ((req.level ?? 0) > 0) partes.push('NÍVEL ' + (req.level | 0));
  if (req.rank !== null) partes.push('PATENTE ' + req.rank);
  if (req.role !== null) partes.push('FUNÇÃO ' + req.role);
  if (req.faction) partes.push('CONTRATO MERCENÁRIO');
  if (req.state === GR.STATE_HUNTER) partes.push('HUNTER COMMAND');
  return partes.length === 0 ? 'SEM REQUISITO' : partes.join(' · ');
}

/** Os seis papéis com o veredito para ESTE jogador nesta zona: pode assumir e, se não, por quê. */
export function roles(op: Operational, zone: Dict | null, level: number, cur: Dict | null = null): Dict[] {
  const out: Dict[] = [];
  for (const [key, r] of Object.entries(GR.SAFE_ROLES)) {
    const req = r.req;
    let motivo: string | null = null;

    if (zone === null) {
      motivo = 'ESCOLHA UMA ZONA';
    } else if (zone.blocked) {
      motivo = 'ZONA INDISPONÍVEL';
    } else if (req.state !== null && (op.state ?? '') !== req.state) {
      motivo = req.state === GR.STATE_HUNTER ? 'EXCLUSIVO DO HUNTER COMMAND' : 'ESTADO OPERACIONAL INCOMPATÍVEL';
    } else if (req.state === null && (op.state ?? '') === GR.STATE_SURVIVOR && zone.gov) {
      motivo = 'INDISPONÍVEL NO ESTADO SOBREVIVENTE';
    } else if (req.faction && (op.faction ?? null) === null) {
      motivo = 'EXIGE CONTRATO MERCENÁRIO ATIVO';
    } else if (req.rank !== null && GR.rankIndex(String(op.rank ?? '')) < GR.rankIndex(req.rank)) {
      motivo = 'PATENTE MÍNIMA: ' + req.rank;
    } else if (req.role !== null && (op.role ?? null) !== req.role) {
      motivo = 'FUNÇÃO HUNTER: ' + req.role;
    } else if ((req.level | 0) > level) {
      motivo = 'NÍVEL ' + (req.level | 0);
    }

    const ativo = cur !== null && cur.role === key;
    out.push({
      key,
      label: r.label,
      ms: r.ms,
      color: r.color,
      summary: r.summary,
      desc: r.desc,
      gain: r.gain,
      req: requisito(req),
      can: motivo === null && !ativo,
      active: ativo,
      reason: motivo,
    });
  }
  return out;
}

export interface AssumeResult {
  ok: boolean;
  error?: string;
  role?: string;
  zone?: string;
  ends_at?: string;
}

/** Assume um papel civil. Trocar de papel encerra o turno anterior (um por vez). */
export async function assume(userId: number, zoneSlug: string, roleKey: string, op: Operational, level: number): Promise<AssumeResult> {
  if (userId <= 0) return { ok: false, error: 'Entre na sua conta para assumir um papel.' };
  const key = GR.safeRoleKey(roleKey);
  if (key === null) return { ok: false, error: 'Papel desconhecido.' };
  const zone = (await zones(op)).find((z) => z.slug === zoneSlug) ?? null;
  if (zone === null) return { ok: false, error: 'Zona desconhecida.' };
  if (zone.blocked) return { ok: false, error: String(zone.block_reason) };

  // Mesma checagem que a tela mostrou: o servidor não confia no botão.
  for (const r of roles(op, zone, level, null)) {
    if (r.key === key && !r.can) return { ok: false, error: 'REQUISITO NÃO CUMPRIDO — ' + String(r.reason) };
  }

  const ends = new Date(Date.now() + GR.SAFE_SHIFT_MIN * 60_000).toISOString().slice(0, 19).replace('T', ' ');
  try {
    await DB.run(
      `INSERT INTO player_safe_roles (user_id, zone_slug, role, status, started_at, shift_ends_at, served)
       VALUES (:u, :z, :r, :s, datetime('now'), :e, 0)
       ON CONFLICT(user_id) DO UPDATE SET zone_slug = excluded.zone_slug, role = excluded.role,
                                          status = excluded.status, started_at = datetime('now'),
                                          shift_ends_at = excluded.shift_ends_at, served = 0`,
      { u: userId, z: zone.slug, r: key, s: 'ATIVO', e: ends },
    );
  } catch (e) {
    console.error('[extracthunters] safe-zone assume:', e);
    return { ok: false, error: 'A Safe Zone ainda não está disponível neste servidor.' };
  }
  return { ok: true, role: GR.SAFE_ROLES[key]!.label, zone: String(zone.name), ends_at: ends };
}

/** Encerra o turno atual. Reversível: o jogador pode assumir outro papel em seguida. */
export async function leave(userId: number): Promise<boolean> {
  if (userId <= 0) return false;
  try {
    await DB.run('DELETE FROM player_safe_roles WHERE user_id = :u', { u: userId });
    return true;
  } catch {
    return false;
  }
}
