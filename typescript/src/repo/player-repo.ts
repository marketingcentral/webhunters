/**
 * Jogador da conta logada — o App\Repo\PlayerRepo do PHP.
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import * as OperationalRepo from './operational-repo';

function json(v: unknown): Dict {
  if (typeof v !== 'string' || v === '') return {};
  try {
    return (JSON.parse(v) as Dict) || {};
  } catch {
    return {};
  }
}

export async function forUser(userId: number): Promise<Dict | null> {
  const p = await DB.one(
    'SELECT pl.*, u.username, u.email FROM players pl JOIN users u ON u.id = pl.user_id WHERE pl.user_id = :id',
    { id: userId },
  );
  if (p === null) return null;

  const [stats, unread, operational] = await Promise.all([
    DB.one(
      `SELECT COUNT(*) matches,
              COALESCE(SUM(kills),0) kills,
              COALESCE(SUM(result = 'extraido'),0) extractions,
              COALESCE(SUM(result = 'abatido'),0) deaths
         FROM player_matches WHERE user_id = :id`,
      { id: userId },
    ),
    unreadMail(userId),
    OperationalRepo.fromRow(userId, p),
  ]);

  return {
    id: Number(p.user_id),
    username: String(p.username),
    name: String(p.display_name),
    level: Number(p.level),
    xp: Number(p.xp),
    xp_next: Number(p.xp_next),
    avatar: String(p.avatar ?? 'portraits/op-assault.jpg'),
    clan: String(p.clan ?? ''),
    class: String(p.class ?? ''),
    class_icon: String(p.class_icon ?? 'class-assault'),
    rank: String(p.player_rank ?? ''),
    season_level: Number(p.season_level),
    skill_points: Number(p.skill_points),
    currencies: { credits: Number(p.credits), cash: Number(p.cash), points: Number(p.points) },
    health: json(p.health),
    survival: json(p.survival),
    operational,
    match_stats: {
      matches: Number(stats?.matches ?? 0),
      kills: Number(stats?.kills ?? 0),
      extractions: Number(stats?.extractions ?? 0),
      deaths: Number(stats?.deaths ?? 0),
    },
    friends_online: 8,
    unread_mail: unread,
    notifications: 0,
    status: 'online',
  };
}

async function unreadMail(userId: number): Promise<number> {
  try {
    return Number(await DB.value('SELECT COUNT(*) FROM message_threads WHERE user_id = :id AND unread = 1', { id: userId })) || 0;
  } catch {
    return 0;
  }
}

function currencyColumn(c: string): string {
  return c === 'cash' ? 'cash' : c === 'points' ? 'points' : 'credits';
}

export async function balance(userId: number, currency: string): Promise<number> {
  const col = currencyColumn(currency);
  return Number(await DB.value(`SELECT "${col}" FROM players WHERE user_id = :id`, { id: userId })) || 0;
}

/** Débito só passa com saldo (o WHERE garante na mesma instrução, sem corrida). */
export async function adjustCurrency(userId: number, currency: string, delta: number): Promise<boolean> {
  const col = currencyColumn(currency);
  if (delta < 0) {
    const r = await DB.run(
      `UPDATE players SET "${col}" = "${col}" + :d WHERE user_id = :id AND "${col}" >= :need`,
      { d: delta, id: userId, need: -delta },
    );
    return DB.changes(r) > 0;
  }
  await DB.run(`UPDATE players SET "${col}" = "${col}" + :d WHERE user_id = :id`, { d: delta, id: userId });
  return true;
}

export async function addXp(userId: number, amount: number): Promise<void> {
  const p = await DB.one('SELECT level, xp, xp_next FROM players WHERE user_id = :id', { id: userId });
  if (p === null) return;
  let level = Number(p.level);
  let xp = Number(p.xp) + amount;
  let next = Number(p.xp_next) || 1000;
  while (xp >= next) {
    xp -= next;
    level++;
    next = 1000 + level * 500;
  }
  await DB.run('UPDATE players SET level = :l, xp = :x, xp_next = :n WHERE user_id = :id', { l: level, x: xp, n: next, id: userId });
}
