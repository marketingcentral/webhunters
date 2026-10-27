/**
 * Lista social do jogador (tela AMIGOS), conciliada por user_id — o App\Repo\FriendsRepo do PHP.
 *
 * Leitura: `friends` (uma linha por vínculo: amigo, pedido recebido ou pedido enviado) e
 * `squad_history` (com quem o jogador jogou). Os rótulos "HÁ 12 MIN", "128 PARTIDAS" e
 * "Visto há 2 horas" são DERIVADOS de last_seen/matches — nada de texto congelado.
 *
 * As idades saem do próprio banco (julianday), com last_seen/created_at gravados por
 * datetime('now') — o mesmo relógio dos dois lados, como o TIMESTAMPDIFF/NOW() do MySQL.
 *
 * Mutações: invite/accept/decline/cancel devolvem [tipo_de_flash, mensagem] para o controller.
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';

export const REL_FRIEND = 'amigo';
export const REL_INCOMING = 'pedido_recebido';
export const REL_OUTGOING = 'pedido_enviado';

/** Presenças válidas (a coluna aceita qualquer string; a view só conhece estas). */
const PRESENCES = ['ingame', 'lobby', 'online', 'offline'];

export type Flash = [string, string];

export interface Social {
  online: Dict[];
  offline: Dict[];
  pending: Dict[];
  squad: Dict[];
  counts: { online: number; offline: number; pending: number; total: number };
}

/** Tudo o que a view precisa, já separado por aba. */
export async function forUser(userId: number): Promise<Social> {
  const [rows, squadRows] = userId > 0
    ? await Promise.all([
      DB.all(
        `SELECT f.*,
                max(CAST((julianday('now') - julianday(f.last_seen)) * 1440 AS INTEGER), 0)  AS seen_min,
                max(CAST((julianday('now') - julianday(f.created_at)) * 1440 AS INTEGER), 0) AS age_min
           FROM friends f WHERE f.user_id = :u ORDER BY f.sort_order, f.name`,
        { u: userId },
      ),
      DB.all(
        `SELECT s.*, max(CAST((julianday('now') - julianday(s.played_at)) * 1440 AS INTEGER), 0) AS ago_min
           FROM squad_history s WHERE s.user_id = :u ORDER BY s.played_at DESC, s.sort_order LIMIT 8`,
        { u: userId },
      ),
    ])
    : [[], []];

  const online: Dict[] = [];
  const offline: Dict[] = [];
  const pending: Dict[] = [];
  for (const r of rows) {
    if (String(r.relation) === REL_FRIEND) {
      const card = friendCard(r);
      (card.status === 'offline' ? offline : online).push(card);
      continue;
    }
    pending.push(pendingCard(r));
  }

  // pedidos recebidos primeiro, depois os enviados; dentro do grupo, mais recentes antes
  pending.sort((a, b) => {
    const da = a.direction === 'in' ? 0 : 1;
    const db = b.direction === 'in' ? 0 : 1;
    return da !== db ? da - db : Number(a.age_min) - Number(b.age_min);
  });

  const squad: Dict[] = squadRows.map((s) => {
    const ok = String(s.result) === 'extraido';
    return {
      name: String(s.mate_name),
      avatar: String(s.avatar ?? 'portraits/op-assault.jpg'),
      map: String(s.map_name).toUpperCase(),
      result: ok ? 'EXTRAÍDO' : 'ABATIDO',
      ok,
      when: agoLabel(Number(s.ago_min ?? 0) | 0),
    };
  });

  return {
    online,
    offline,
    pending,
    squad,
    counts: { online: online.length, offline: offline.length, pending: pending.length, total: online.length + offline.length },
  };
}

// ------------------------------------------------------------------ mutações

/** Envia convite. Se já existe um pedido recebido do mesmo jogador, a amizade é firmada. */
export async function invite(userId: number, name0: string, selfName = ''): Promise<Flash> {
  const name = cleanName(name0);
  if (userId <= 0) return ['error', 'Não foi possível enviar o convite agora.'];
  if (name === '') return ['error', 'Informe o nome de usuário ou o ID do jogador.'];
  if (selfName !== '' && name.toLowerCase() === selfName.toLowerCase()) {
    return ['error', 'Você não pode adicionar a si mesmo.'];
  }

  const row = await find(userId, name);
  if (row !== null) {
    switch (String(row.relation)) {
      case REL_FRIEND:
        return ['error', `${String(row.name)} já está na sua lista de amigos.`];
      case REL_OUTGOING:
        return ['error', `Você já enviou um convite para ${String(row.name)}.`];
      default:
        return accept(userId, String(row.name));
    }
  }

  // last_seen/created_at pelo relógio do banco, igual ao cálculo das idades
  await DB.run(
    `INSERT INTO friends (user_id, name, level, class, class_icon, avatar, relation, presence,
                          activity, matches, last_seen, created_at, sort_order)
     VALUES (:u, :n, 1, 'RECRUTA', 'class-assault', 'portraits/op-assault.jpg', :r, 'offline',
             'Convite enviado por você', 0, datetime('now'), datetime('now'), 900)`,
    { u: userId, n: name, r: REL_OUTGOING },
  );
  return ['ok', `Convite enviado para ${name}.`];
}

/** Aceita um pedido recebido: vira amizade e entra na aba ONLINE. */
export async function accept(userId: number, name: string): Promise<Flash> {
  const row = await find(userId, name);
  if (row === null || String(row.relation) !== REL_INCOMING) return ['error', 'Este pedido não está mais pendente.'];
  await DB.run(
    `UPDATE friends SET relation = :r, presence = 'online', activity = 'Menu principal',
            party_slots = NULL, last_seen = datetime('now'), updated_at = datetime('now') WHERE id = :id`,
    { r: REL_FRIEND, id: Number(row.id) },
  );
  return ['ok', `Pedido de ${String(row.name)} aceito. Vocês agora são amigos.`];
}

/** Recusa um pedido recebido (remove o vínculo). */
export async function decline(userId: number, name: string): Promise<Flash> {
  const row = await find(userId, name);
  if (row === null || String(row.relation) !== REL_INCOMING) return ['error', 'Este pedido não está mais pendente.'];
  await DB.run('DELETE FROM friends WHERE id = :id', { id: Number(row.id) });
  return ['ok', `Pedido de ${String(row.name)} recusado.`];
}

/** Cancela um convite que o jogador enviou. */
export async function cancel(userId: number, name: string): Promise<Flash> {
  const row = await find(userId, name);
  if (row === null || String(row.relation) !== REL_OUTGOING) return ['error', 'Este convite não está mais pendente.'];
  await DB.run('DELETE FROM friends WHERE id = :id', { id: Number(row.id) });
  return ['ok', `Convite para ${String(row.name)} cancelado.`];
}

// ------------------------------------------------------------------ internos

async function find(userId: number, name0: string): Promise<Dict | null> {
  const name = cleanName(name0);
  if (userId <= 0 || name === '') return null;
  // A collation do MySQL (_ci) achava "ghost_br" em "Ghost_BR"; NOCASE faz o mesmo (só ASCII).
  return DB.one('SELECT * FROM friends WHERE user_id = :u AND name = :n COLLATE NOCASE', { u: userId, n: name });
}

/** Espaços colapsados, aparado, no máximo 40 caracteres (mb_substr conta code points). */
export function cleanName(name: string): string {
  const n = name.replace(/\s+/gu, ' ').trim();
  return Array.from(n).slice(0, 40).join('');
}

function friendCard(r: Dict): Dict {
  const status = PRESENCES.includes(String(r.presence)) ? String(r.presence) : 'offline';
  const minutes = Number(r.seen_min ?? 0) | 0;
  const matches = Number(r.matches) | 0;
  let activity: string;
  let meta: string;
  if (status === 'offline') {
    activity = 'Visto ' + seenLabel(minutes).toLowerCase();
    meta = matches > 0 ? `${matches} PARTIDAS` : 'SEM PARTIDAS';
  } else if (status === 'lobby') {
    activity = String(r.activity ?? '');
    meta = String(r.party_slots ?? '') !== '' ? String(r.party_slots) : agoLabel(minutes);
  } else {
    activity = String(r.activity ?? '');
    meta = agoLabel(minutes);
  }
  return {
    name: String(r.name),
    level: Number(r.level) | 0,
    class: String(r.class ?? ''),
    class_icon: String(r.class_icon ?? 'class-assault'),
    avatar: String(r.avatar ?? 'portraits/op-assault.jpg'),
    status,
    activity,
    meta,
  };
}

function pendingCard(r: Dict): Dict {
  const inn = String(r.relation) === REL_INCOMING;
  const minutes = Number(r.age_min ?? 0) | 0;
  return {
    name: String(r.name),
    level: Number(r.level) | 0,
    class: String(r.class ?? ''),
    class_icon: String(r.class_icon ?? 'class-assault'),
    avatar: String(r.avatar ?? 'portraits/op-assault.jpg'),
    direction: inn ? 'in' : 'out',
    activity: String(r.activity ?? ''),
    meta: agoLabel(minutes),
    age_min: minutes,
  };
}

/** "AGORA" | "HÁ 12 MIN" | "HÁ 5 H" | "ONTEM" | "HÁ 3 DIAS" */
function agoLabel(minutes: number): string {
  if (minutes < 1) return 'AGORA';
  if (minutes < 60) return `HÁ ${minutes} MIN`;
  if (minutes < 1440) return `HÁ ${Math.floor(minutes / 60)} H`;
  const days = Math.floor(minutes / 1440);
  return days === 1 ? 'ONTEM' : `HÁ ${days} DIAS`;
}

/** "Agora" | "há 12 minutos" | "há 5 horas" | "ontem" | "há 3 dias" (para o texto de atividade) */
function seenLabel(minutes: number): string {
  if (minutes < 1) return 'Agora';
  if (minutes < 60) return 'há ' + minutes + (minutes === 1 ? ' minuto' : ' minutos');
  if (minutes < 1440) {
    const h = Math.floor(minutes / 60);
    return 'há ' + h + (h === 1 ? ' hora' : ' horas');
  }
  const days = Math.floor(minutes / 1440);
  return days === 1 ? 'ontem' : 'há ' + days + ' dias';
}
