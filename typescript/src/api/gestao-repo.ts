/**
 * Leituras e escritas de CONTAS e PUNIÇÕES que a API usa.
 *
 * Reúne o App\Repo\ApiGestaoRepo do PHP inteiro e SÓ as funções de AdminPlayersRepo, BanRepo e
 * AdminRepo que a API chama — os repos do painel ainda não foram portados; quando o lote do
 * painel portar BanRepo/AdminPlayersRepo/AdminRepo, estas cópias podem passar a importar de lá.
 *
 * Transações do PHP (BanRepo::aplicar/revogar) viram `batch()` do D1: o ban e o recálculo de
 * `users.status` gravam no mesmo lote atômico.
 */
import * as DB from '../core/database';
import type { Obj } from './resposta';

// ============================================================ AdminRepo::registrar (auditoria)

/** Uma linha em `admin_audit_log`. Nunca lança: falhar em auditar não derruba a resposta. */
export async function registrarAuditoria(
  adminId: number | null,
  acao: string,
  entidade = 'sistema',
  entidadeId: string | null = null,
  resumo = '',
  meta: Obj = {},
  ip: string | null = null,
): Promise<void> {
  const corta = (s: string, n: number) => [...s].slice(0, n).join('');
  try {
    await DB.insert('admin_audit_log', {
      admin_id: adminId !== null && adminId > 0 ? adminId : null,
      action: corta(acao, 64),
      entity: corta(entidade, 48),
      entity_id: entidadeId !== null ? corta(entidadeId, 64) : null,
      summary: corta(resumo, 255),
      meta: Object.keys(meta).length === 0 ? null : JSON.stringify(meta),
      ip: ip !== null ? corta(ip, 45) : null,
    });
  } catch (e) {
    console.error(`[extracthunters] auditoria (${acao}):`, e);
  }
}

// ============================================================ AdminPlayersRepo (só o que a API usa)

export const PAPEIS = ['player', 'admin', 'owner'];
export const SITUACOES = ['active', 'banned', 'suspended'];

/** Conta + ficha de jogador + passe da temporada, ou null. Nunca seleciona password_hash. */
export async function conta(id: number): Promise<Obj | null> {
  if (id <= 0) return null;
  return DB.one<Obj>(
    `SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login, u.last_ip,
            p.display_name, p.avatar, p.level, p.xp, p.xp_next, p.credits, p.cash, p.points,
            p.clan, p.class, p.player_rank, p.season_level, p.skill_points,
            p.operational_state, p.hunter_rank, p.hunter_role, p.survivor_since,
            ps.pass_level, ps.pass_xp, ps.week_done, ps.week_goal, ps.week_xp, ps.streak_days,
            s.number AS season_number, s.pass_name, s.xp_per_level
       FROM users u
       LEFT JOIN players p        ON p.user_id = u.id
       LEFT JOIN player_season ps ON ps.user_id = u.id
       LEFT JOIN seasons s        ON s.id = ps.season_id
      WHERE u.id = :id LIMIT 1`,
    { id },
  );
}

/** Punição VIGENTE da conta (não revogada e no prazo), ou null. */
export async function banAtivo(id: number): Promise<Obj | null> {
  return DB.one<Obj>(
    `SELECT b.*, ua.username AS admin_nome
       FROM bans b LEFT JOIN users ua ON ua.id = b.admin_id
      WHERE b.user_id = :id AND b.revoked_at IS NULL
        AND (b.kind = 'perma' OR b.expires_at IS NULL OR b.expires_at > datetime('now'))
      ORDER BY b.created_at DESC, b.id DESC LIMIT 1`,
    { id },
  );
}

/** Papel e situação. Nenhuma outra coluna de `users` é escrita. */
export async function atualizarConta(id: number, papel: string, situacao: string): Promise<void> {
  await DB.run('UPDATE users SET role = :r, status = :s WHERE id = :id', { r: papel, s: situacao, id });
}

// ============================================================ BanRepo (só o que a API usa)

export const TIPOS_BAN: Record<string, { rotulo: string; status: string; prazo: boolean; tom: string }> = {
  temp: { rotulo: 'Temporário', status: 'banned', prazo: true, tom: 'warn' },
  perma: { rotulo: 'Definitivo', status: 'banned', prazo: false, tom: 'danger' },
  suspensao: { rotulo: 'Suspensão', status: 'suspended', prazo: true, tom: 'info' },
};

export const ESTADOS_BAN = ['ativo', 'expirado', 'revogado'];

/** A expressão de estado derivado, uma vez só (mesma regra do painel). */
export const ESTADO_BAN = `CASE
    WHEN b.revoked_at IS NOT NULL THEN 'revogado'
    WHEN b.expires_at IS NULL OR b.expires_at > datetime('now') THEN 'ativo'
    ELSE 'expirado'
  END`;

const VIGENTE = `b.revoked_at IS NULL AND (b.expires_at IS NULL OR b.expires_at > datetime('now'))`;

/**
 * BanRepo::sincronizar() como UM statement (para caber no batch): recalcula `users.status`
 * a partir dos bans vigentes — ban duro (temp/perma) vale mais que suspensão.
 * `userSql` é a expressão que dá o id da conta (literal parametrizado ou subconsulta).
 */
function sincronizarStmt(userSql: string, params: Obj): D1PreparedStatement {
  const novo = `CASE
      WHEN EXISTS (SELECT 1 FROM bans b WHERE b.user_id = users.id AND b.kind IN ('temp','perma') AND ${VIGENTE}) THEN 'banned'
      WHEN EXISTS (SELECT 1 FROM bans b WHERE b.user_id = users.id AND b.kind NOT IN ('temp','perma') AND ${VIGENTE}) THEN 'suspended'
      ELSE 'active' END`;
  return DB.prepare(`UPDATE users SET status = ${novo} WHERE id = ${userSql} AND status <> ${novo}`, params);
}

/** Aplica a punição e deixa `users.status` coerente, no mesmo lote atômico. Devolve o id do ban. */
export async function aplicarBan(
  userId: number,
  adminId: number | null,
  tipo: string,
  motivo: string,
  expiraEm: string | null,
): Promise<number> {
  if (!TIPOS_BAN[tipo]) tipo = 'temp';
  const r = await DB.batch([
    DB.insertStmt('bans', {
      user_id: userId,
      admin_id: adminId !== null && adminId > 0 ? adminId : null,
      reason: motivo,
      kind: tipo,
      expires_at: TIPOS_BAN[tipo]!.prazo ? expiraEm : null,
    }),
    sincronizarStmt(':u', { u: userId }),
  ]);
  return Number(r[0]?.meta.last_row_id ?? 0);
}

/** Revoga um ban vigente. false quando já estava revogado (a condição mora no UPDATE). */
export async function revogarBan(banId: number, adminId: number | null, motivo: string): Promise<boolean> {
  if (banId <= 0) return false;
  const r = await DB.batch([
    DB.prepare(
      `UPDATE bans SET revoked_at = datetime('now'), revoked_by = :admin, revoke_reason = :motivo
        WHERE id = :id AND revoked_at IS NULL`,
      { admin: adminId !== null && adminId > 0 ? adminId : null, motivo, id: banId },
    ),
    // Recalcula sempre: se nada mudou acima, o resultado é o mesmo status (e o WHERE não toca a linha).
    sincronizarStmt('(SELECT user_id FROM bans WHERE id = :b)', { b: banId }),
  ]);
  return DB.changes(r[0]!) > 0;
}

// ============================================================ ApiGestaoRepo

const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;
const str = (v: unknown): string => (v === null || v === undefined ? '' : String(v));
const strOuNull = (v: unknown): string | null => (v === null || v === undefined ? null : String(v));

/** Página da lista de jogadores (sem e-mail: use /players/{id}). */
export async function jogadores(
  f: { q?: string; role?: string; status?: string },
  limite: number,
  deslocamento: number,
): Promise<{ linhas: Obj[]; total: number }> {
  const cond: string[] = [];
  const par: Obj = {};

  const q = [...(f.q ?? '').trim()].slice(0, 60).join('');
  if (q !== '') {
    const termo = '%' + q.replace(/[!%_]/g, (c) => '!' + c) + '%';
    cond.push(`(u.username LIKE :q0 ESCAPE '!' OR u.email LIKE :q1 ESCAPE '!' OR p.display_name LIKE :q2 ESCAPE '!')`);
    par.q0 = par.q1 = par.q2 = termo;
  }
  if (PAPEIS.includes(f.role ?? '')) {
    cond.push('u.role = :papel');
    par.papel = f.role;
  }
  if (SITUACOES.includes(f.status ?? '')) {
    cond.push('u.status = :situacao');
    par.situacao = f.status;
  }
  const onde = cond.length === 0 ? '1' : cond.join(' AND ');

  const total = int(await DB.value(`SELECT COUNT(*) FROM users u LEFT JOIN players p ON p.user_id = u.id WHERE ${onde}`, par));
  limite = Math.max(1, Math.min(200, limite));
  deslocamento = Math.max(0, deslocamento);

  const linhas = await DB.all<Obj>(
    `SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login,
            p.display_name, p.level, p.xp, p.credits, p.cash, p.points, p.player_rank,
            p.operational_state
       FROM users u
       LEFT JOIN players p ON p.user_id = u.id
      WHERE ${onde}
      ORDER BY u.id DESC
      LIMIT ${limite} OFFSET ${deslocamento}`,
    par,
  );
  return { linhas: linhas.map(jogadorResumo), total };
}

function jogadorResumo(l: Obj): Obj {
  return {
    id: int(l.id),
    username: str(l.username),
    role: str(l.role),
    status: str(l.status),
    created_at: str(l.created_at),
    last_login: strOuNull(l.last_login),
    player:
      l.display_name === null || l.display_name === undefined
        ? null
        : {
            display_name: str(l.display_name),
            level: int(l.level),
            xp: int(l.xp),
            rank: str(l.player_rank),
            operational_state: str(l.operational_state),
            wallet: { credits: int(l.credits), cash: int(l.cash), points: int(l.points) },
          },
  };
}

/** Uma conta inteira, com a punição vigente. Null quando o id não existe. */
export async function jogador(id: number): Promise<Obj | null> {
  const c = await conta(id);
  if (c === null) return null;
  const ban = await banAtivo(id);
  return {
    id: int(c.id),
    username: str(c.username),
    email: str(c.email),
    role: str(c.role),
    status: str(c.status),
    created_at: str(c.created_at),
    last_login: strOuNull(c.last_login),
    last_ip: str(c.last_ip),
    player:
      c.display_name === null || c.display_name === undefined
        ? null
        : {
            display_name: str(c.display_name),
            level: int(c.level),
            xp: int(c.xp),
            xp_next: int(c.xp_next),
            rank: str(c.player_rank),
            clan: str(c.clan),
            class: str(c.class),
            skill_points: int(c.skill_points),
            operational_state: str(c.operational_state),
            hunter_rank: str(c.hunter_rank),
            hunter_role: str(c.hunter_role),
            wallet: { credits: int(c.credits), cash: int(c.cash), points: int(c.points) },
          },
    ban:
      ban === null
        ? null
        : {
            id: int(ban.id),
            kind: str(ban.kind),
            reason: str(ban.reason),
            expires_at: strOuNull(ban.expires_at),
            created_at: str(ban.created_at),
          },
  };
}

/** Página do histórico de punições, com limit/offset. */
export async function banimentos(
  f: { user_id?: number; state?: string; kind?: string },
  limite: number,
  deslocamento: number,
): Promise<{ linhas: Obj[]; total: number }> {
  const cond: string[] = [];
  const par: Obj = {};
  if ((f.user_id ?? 0) > 0) {
    cond.push('b.user_id = :uid');
    par.uid = f.user_id;
  }
  if (f.kind && TIPOS_BAN[f.kind]) {
    cond.push('b.kind = :kind');
    par.kind = f.kind;
  }
  if (ESTADOS_BAN.includes(f.state ?? '')) {
    cond.push(`(${ESTADO_BAN}) = :estado`);
    par.estado = f.state;
  }
  const onde = cond.length === 0 ? '1' : cond.join(' AND ');

  const total = int(await DB.value(`SELECT COUNT(*) FROM bans b JOIN users u ON u.id = b.user_id WHERE ${onde}`, par));
  limite = Math.max(1, Math.min(200, limite));
  deslocamento = Math.max(0, deslocamento);

  const linhas = await DB.all<Obj>(
    `SELECT b.id, b.user_id, b.admin_id, b.reason, b.kind, b.expires_at, b.created_at,
            b.revoked_at, b.revoked_by, b.revoke_reason,
            ${ESTADO_BAN} AS estado,
            u.username AS alvo_nome, a.username AS admin_nome, r.username AS revogador_nome
       FROM bans b
       JOIN users u ON u.id = b.user_id
       LEFT JOIN users a ON a.id = b.admin_id
       LEFT JOIN users r ON r.id = b.revoked_by
      WHERE ${onde}
      ORDER BY b.created_at DESC, b.id DESC
      LIMIT ${limite} OFFSET ${deslocamento}`,
    par,
  );
  return { linhas: linhas.map(banimentoSaida), total };
}

/** Um ban pelo id, no formato da API, ou null. */
export async function banimento(id: number): Promise<Obj | null> {
  if (id <= 0) return null;
  const l = await DB.one<Obj>(
    `SELECT b.*, ${ESTADO_BAN} AS estado,
            u.username AS alvo_nome, a.username AS admin_nome, r.username AS revogador_nome
       FROM bans b
       JOIN users u ON u.id = b.user_id
       LEFT JOIN users a ON a.id = b.admin_id
       LEFT JOIN users r ON r.id = b.revoked_by
      WHERE b.id = :id LIMIT 1`,
    { id },
  );
  return l === null ? null : banimentoSaida(l);
}

function banimentoSaida(l: Obj): Obj {
  return {
    id: int(l.id),
    user_id: int(l.user_id),
    username: str(l.alvo_nome),
    kind: str(l.kind),
    state: str(l.estado),
    reason: str(l.reason),
    expires_at: strOuNull(l.expires_at),
    created_at: str(l.created_at),
    applied_by: strOuNull(l.admin_nome),
    revoked_at: strOuNull(l.revoked_at),
    revoked_by: strOuNull(l.revogador_nome),
    revoke_reason: str(l.revoke_reason),
  };
}

/** Progresso: temporada, missões e conquistas (com o total do catálogo). */
export async function progresso(id: number): Promise<Obj | null> {
  const c = await conta(id);
  if (c === null) return null;

  const m =
    (await DB.one<Obj>(
      `SELECT COUNT(*) AS total, SUM(status = 'done') AS concluidas, SUM(status = 'active') AS ativas
         FROM player_missions WHERE user_id = :id`,
      { id },
    )) ?? {};
  const a =
    (await DB.one<Obj>(
      `SELECT COUNT(*) AS total, SUM(completed_at IS NOT NULL) AS concluidas
         FROM player_achievements WHERE user_id = :id`,
      { id },
    )) ?? {};

  return {
    user_id: id,
    season:
      c.pass_level === null || c.pass_level === undefined
        ? null
        : {
            number: int(c.season_number),
            pass_name: str(c.pass_name),
            pass_level: int(c.pass_level),
            pass_xp: int(c.pass_xp),
            xp_per_level: int(c.xp_per_level),
            week_done: int(c.week_done),
            week_goal: int(c.week_goal),
            streak_days: int(c.streak_days),
          },
    missions: {
      catalog: int(await DB.value('SELECT COUNT(*) FROM missions')),
      started: int(m.total),
      completed: int(m.concluidas),
      active: int(m.ativas),
    },
    achievements: {
      catalog: int(await DB.value('SELECT COUNT(*) FROM achievements')),
      started: int(a.total),
      completed: int(a.concluidas),
    },
  };
}
