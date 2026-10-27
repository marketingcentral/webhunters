/**
 * Desafios e temporada, conciliados por user_id — o App\Repo\ChallengeRepo do PHP.
 *
 * Catálogo: `seasons`, `season_tiers`, `challenges`, `challenge_bonuses`.
 * Estado do jogador: `player_challenges`, `player_season`, `player_bonus_claims`.
 *
 * Os prazos saem como SEGUNDOS restantes, calculados a partir de datas reais (fim do dia,
 * próxima segunda, fim da temporada) no fuso de São Paulo, como o PHP fazia — ver core/relogio.ts.
 */
import { memo } from '../core/context';
import * as DB from '../core/database';
import { lerDataSp, meiaNoite, segundaQueVem, semanaIso, ymd } from '../core/relogio';
import type { Dict } from '../core/view';
import * as PlayerRepo from './player-repo';

/** Abas da tela, na ordem. */
export const SCOPES = ['diarios', 'semanais', 'temporada'];

/**
 * Desafios-META: o progresso deles é a contagem real de concluídos do próprio escopo
 * (excluindo o meta), nunca a coluna `player_challenges.progress`.
 */
const META_SLUGS = ['conclua-todos'];

export interface Grupo {
  items: Dict[];
  done: number;
  total: number;
  seconds: number;
}

export type Quadro = Record<string, Grupo>;

/** Temporada corrente (ou null se o catálogo ainda não foi populado). */
export function season(): Promise<Dict | null> {
  return memo('challenge.season', () => DB.one('SELECT * FROM seasons WHERE is_current = 1 ORDER BY number DESC LIMIT 1'));
}

/** Recompensas do passe, em ordem de nível. */
export function tiers(seasonId: number): Promise<Dict[]> {
  return DB.all('SELECT * FROM season_tiers WHERE season_id = :s ORDER BY level, sort_order', { s: seasonId });
}

/** Bônus indexado por escopo. */
export function bonuses(): Promise<Record<string, Dict>> {
  return memo('challenge.bonuses', async () => {
    const out: Record<string, Dict> = {};
    for (const b of await DB.all('SELECT * FROM challenge_bonuses')) out[String(b.scope)] = b;
    return out;
  });
}

/** Quadro completo do jogador: uma entrada por aba com itens, contagem e prazo. */
export async function board(userId: number): Promise<Quadro> {
  const prazos = await deadlines();
  // FIELD(c.scope, ...) do MySQL -> CASE
  const rows = await DB.all(
    `SELECT c.*, pc.progress, pc.completed_at
       FROM challenges c
       LEFT JOIN player_challenges pc ON pc.challenge_id = c.id AND pc.user_id = :u
      ORDER BY CASE c.scope WHEN :s1 THEN 1 WHEN :s2 THEN 2 WHEN :s3 THEN 3 ELSE 0 END, c.sort_order, c.id`,
    { u: userId, s1: SCOPES[0], s2: SCOPES[1], s3: SCOPES[2] },
  );

  const quadro: Quadro = {};
  for (const scope of SCOPES) quadro[scope] = { items: [], done: 0, total: 0, seconds: prazos[scope] ?? 0 };

  // 1ª passada: desafios normais, direto da coluna de progresso. Metas ficam para depois.
  const meta: Array<[string, number]> = [];
  for (const r of rows) {
    const scope = String(r.scope);
    const g = quadro[scope];
    if (!g) continue;
    const slug = String(r.slug);
    const target = Math.max(1, Math.trunc(Number(r.target) || 0));
    const isMeta = META_SLUGS.includes(slug);
    const cur = isMeta ? 0 : Math.min(target, Math.max(0, Math.trunc(Number(r.progress ?? 0) || 0)));
    const done = !isMeta && cur >= target;
    g.items.push({
      slug,
      label: String(r.label),
      desc: String(r.description ?? ''),
      icon: String(r.icon ?? 'flag'),
      cur,
      max: target,
      pct: Math.round((cur / target) * 100),
      xp: Math.trunc(Number(r.xp) || 0),
      credits: Math.trunc(Number(r.credits) || 0),
      hot: Number(r.highlight) === 1,
      done,
    });
    g.total++;
    if (done) g.done++;
    if (isMeta) meta.push([scope, g.items.length - 1]);
  }

  // 2ª passada: cada meta recebe a contagem real de concluídos do seu escopo.
  for (const [scope, idx] of meta) {
    const g = quadro[scope]!;
    const item = g.items[idx]!;
    const target = Number(item.max);
    const cur = Math.min(target, g.done); // metas nunca contam a si mesmos
    const done = cur >= target;
    item.cur = cur;
    item.pct = Math.round((cur / target) * 100);
    item.done = done;
    if (done) g.done++;
  }
  return quadro;
}

/** Concluídos / total do quadro inteiro (o anel "DESAFIOS CONCLUÍDOS"). */
export function boardTotals(quadro: Partial<Quadro>): [number, number] {
  let done = 0;
  let total = 0;
  for (const scope of SCOPES) {
    done += Math.trunc(Number(quadro[scope]?.done ?? 0));
    total += Math.trunc(Number(quadro[scope]?.total ?? 0));
  }
  return [done, total];
}

/** Passe + estatísticas da semana do jogador (linha padrão se ainda não existe). */
export async function playerSeason(userId: number, seasonId: number): Promise<Dict> {
  const row = await DB.one('SELECT * FROM player_season WHERE user_id = :u AND season_id = :s', { u: userId, s: seasonId });
  return (
    row ?? {
      user_id: userId, season_id: seasonId, pass_level: 0, pass_xp: 0,
      week_done: 0, week_goal: 12, week_xp: 0, streak_days: 0,
    }
  );
}

/** Escopos já resgatados no período corrente: { semanais: true, ... } */
export async function claimed(userId: number): Promise<Record<string, boolean>> {
  const keys = await periodKeys();
  const out: Record<string, boolean> = {};
  await Promise.all(
    Object.entries(keys).map(async ([scope, period]) => {
      const n = await DB.value(
        'SELECT COUNT(*) FROM player_bonus_claims WHERE user_id = :u AND scope = :s AND period_key = :p',
        { u: userId, s: scope, p: period },
      );
      out[scope] = Number(n) > 0;
    }),
  );
  return out;
}

type Resgate = { ok: boolean; error?: string; xp?: number; credits?: number };

/**
 * Resgata o bônus de uma aba: só com todos os desafios do escopo concluídos e o período
 * ainda não resgatado. Credita XP + créditos e move o passe da temporada.
 */
export async function claimBonus(userId: number, scope: string): Promise<Resgate> {
  if (userId <= 0 || !SCOPES.includes(scope)) return { ok: false, error: 'Requisição inválida.' };
  const bonus = (await bonuses())[scope] ?? null;
  if (bonus === null) return { ok: false, error: 'Bônus indisponível.' };
  const full = await board(userId);
  const g = full[scope]!;
  if (g.total === 0 || g.done < g.total) {
    return { ok: false, error: 'Conclua todos os desafios desta aba para resgatar o bônus.' };
  }
  const period = (await periodKeys())[scope]!;
  const xp = Math.trunc(Number(bonus.xp) || 0);
  const credits = Math.trunc(Number(bonus.credits) || 0);

  // A chave primária (user_id, scope, period_key) é a trava: o PHP pegava o erro 23000;
  // aqui o DO NOTHING devolve 0 linhas alteradas no mesmo caso.
  const r = await DB.run(
    `INSERT INTO player_bonus_claims (user_id, scope, period_key, xp, credits)
     VALUES (:u, :s, :p, :x, :c) ON CONFLICT(user_id, scope, period_key) DO NOTHING`,
    { u: userId, s: scope, p: period, x: xp, c: credits },
  );
  if (DB.changes(r) === 0) return { ok: false, error: 'Este bônus já foi resgatado neste período.' };

  await PlayerRepo.adjustCurrency(userId, 'credits', credits);
  await PlayerRepo.addXp(userId, xp);
  await creditSeason(userId, xp, full);

  return { ok: true, xp, credits };
}

/** Aplica o XP do bônus na linha do jogador NESTA temporada (com transbordo de nível). */
async function creditSeason(userId: number, xp: number, quadro: Quadro): Promise<void> {
  const s = await season();
  if (s === null) return;
  const seasonId = Number(s.id);
  const perLevel = Math.max(1, Math.trunc(Number(s.xp_per_level ?? 10000) || 0));
  const [weekDone, weekTotal] = boardTotals(quadro);

  const ps = await playerSeason(userId, seasonId);
  let level = Math.trunc(Number(ps.pass_level) || 0);
  let passXp = Math.trunc(Number(ps.pass_xp) || 0) + xp;
  while (passXp >= perLevel) {
    passXp -= perLevel;
    level++;
  }

  await DB.run(
    `INSERT INTO player_season (user_id, season_id, pass_level, pass_xp, week_done, week_goal, week_xp, streak_days)
     VALUES (:u, :s, :lv, :px, :wd, :wg, :wx, :st)
     ON CONFLICT(user_id, season_id) DO UPDATE SET pass_level = excluded.pass_level, pass_xp = excluded.pass_xp,
       week_done = excluded.week_done, week_goal = excluded.week_goal, week_xp = excluded.week_xp`,
    {
      u: userId,
      s: seasonId,
      lv: level,
      px: passXp,
      wd: weekDone,
      wg: Math.max(1, weekTotal, Math.trunc(Number(ps.week_goal) || 0)),
      wx: Math.trunc(Number(ps.week_xp) || 0) + xp,
      st: Math.trunc(Number(ps.streak_days) || 0),
    },
  );
}

/**
 * Segundos restantes de cada aba: diarios = próxima meia-noite; semanais = próxima
 * segunda 00:00; temporada = seasons.ends_at (sem temporada: +18 dias).
 */
export function deadlines(): Promise<Record<string, number>> {
  return memo('challenge.deadlines', async () => {
    const now = Date.now();
    const s = await season();
    let fim = s !== null ? lerDataSp(String(s.ends_at)) : NaN;
    if (Number.isNaN(fim)) fim = now + 18 * 86400000;
    const seg = (ms: number): number => Math.max(0, Math.floor(ms / 1000) - Math.floor(now / 1000));
    return {
      diarios: seg(meiaNoite(1, now)),
      semanais: seg(segundaQueVem(now)),
      temporada: seg(fim),
    };
  });
}

/** Segundos até o fim do evento em destaque. */
export async function eventSeconds(): Promise<number> {
  const s = await season();
  if (s === null) return 0;
  const fim = lerDataSp(String(s.event_ends_at));
  if (Number.isNaN(fim)) return 0;
  return Math.max(0, Math.floor(fim / 1000) - Math.floor(Date.now() / 1000));
}

/** Chave do período corrente por escopo (impede resgatar duas vezes no mesmo dia/semana/temporada). */
export async function periodKeys(): Promise<Record<string, string>> {
  const s = await season();
  return {
    diarios: ymd(),
    semanais: semanaIso(),
    temporada: 'temporada-' + String(s?.number ?? 0),
  };
}
