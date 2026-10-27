/**
 * Consultas da tela ADMIN · JOGADORES (/admin/jogadores) — o App\Repo\AdminPlayersRepo do PHP.
 *
 * Separado de admin-repo.ts de propósito: lá mora a guarda de acesso e o dashboard. Aqui mora
 * só o que governa conta de jogador: a listagem filtrada, o dossiê de um jogador e as três
 * escritas da tela (papel/situação, banir, revogar).
 *
 * `password_hash` NÃO é selecionado por nenhuma função deste arquivo: a tela não mostra e não
 * edita senha, então o dado nem chega à camada de cima.
 *
 * DATAS: como em admin-repo.ts, toda coluna DATETIME é UTC (`datetime('now')` do SQLite).
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import { sincronizar } from './ban-repo';

/** Linhas por página da listagem. */
export const POR_PAGINA = 25;

/** Papéis possíveis em users.role, do menos para o mais poderoso. */
export const PAPEIS = ['player', 'admin', 'owner'];

/** Situações possíveis em users.status. */
export const SITUACOES = ['active', 'banned', 'suspended'];

/**
 * Ordenações aceitas em ?ordem=. A URL escolhe uma CHAVE, e a chave escolhe um trecho de SQL
 * escrito aqui — o parâmetro nunca entra no ORDER BY.
 */
const ORDENS: Record<string, string> = {
  recentes: 'u.created_at DESC, u.id DESC',
  antigos: 'u.created_at ASC, u.id ASC',
  acesso: 'u.last_login IS NULL, u.last_login DESC',
  nome: 'u.username ASC',
  nivel: 'p.level DESC, p.xp DESC, u.id ASC',
  xp: 'p.xp DESC, u.id ASC',
};

/** Tipos de punição aceitos em bans.kind por esta tela. */
export const TIPOS_BAN = ['temp', 'perma'];

/** Filtros da listagem (já validados pelo controller). */
export interface FiltrosLista {
  q?: string;
  papel?: string;
  situacao?: string;
  ordem?: string;
  pagina?: number;
}

// ================================================================================ LISTAGEM

/** Chaves válidas de ordenação, para o controller validar ?ordem=. */
export function ordens(): string[] {
  return Object.keys(ORDENS);
}

/**
 * Página da listagem já filtrada, com o total para a paginação. O total sai de um COUNT com
 * o MESMO WHERE (quantas contas o filtro achou, não quantas couberam na página).
 */
export async function listar(f: FiltrosLista): Promise<{ linhas: Dict[]; total: number; pagina: number; paginas: number; offset: number }> {
  const [where, par] = filtro(f);

  const total = Number(await DB.value('SELECT COUNT(*) FROM users u LEFT JOIN players p ON p.user_id = u.id ' + where, par)) | 0;

  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  const pagina = Math.min(Math.max(1, Math.trunc(Number(f.pagina ?? 1)) || 1), paginas);
  const offset = (pagina - 1) * POR_PAGINA;

  const ordem = ORDENS[String(f.ordem ?? 'recentes')] ?? ORDENS.recentes!;

  // LIMIT/OFFSET interpolados: inteiros derivados de cast, nunca texto da URL.
  const linhas = await DB.all<Dict>(
    `SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login, u.last_ip,
            p.display_name, p.avatar, p.level, p.xp, p.credits, p.player_rank,
            ps.pass_level,
            b.id AS ban_id, b.kind AS ban_kind, b.expires_at AS ban_expires, b.reason AS ban_reason
       FROM users u
       LEFT JOIN players p       ON p.user_id = u.id
       LEFT JOIN player_season ps ON ps.user_id = u.id
       LEFT JOIN bans b          ON b.id = (
            SELECT bb.id FROM bans bb
             WHERE bb.user_id = u.id AND bb.revoked_at IS NULL
               AND (bb.kind = 'perma' OR bb.expires_at IS NULL OR bb.expires_at > datetime('now'))
             ORDER BY bb.created_at DESC, bb.id DESC LIMIT 1
       )
     ${where}
     ORDER BY ${ordem}
     LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    par,
  );

  return { linhas, total, pagina, paginas, offset };
}

/**
 * Contagem por situação para as abas do topo. Ignora a busca e o papel de propósito: as abas
 * são o retrato do CADASTRO INTEIRO.
 */
export async function contagemPorSituacao(): Promise<{ total: number; active: number; banned: number; suspended: number }> {
  const out: Record<string, number> = { total: 0, active: 0, banned: 0, suspended: 0 };
  for (const r of await DB.all<Dict>('SELECT status, COUNT(*) n FROM users GROUP BY status')) {
    const s = String(r.status);
    const n = Number(r.n) | 0;
    out.total! += n;
    if (s in out && s !== 'total') out[s] = n;
  }
  return out as { total: number; active: number; banned: number; suspended: number };
}

/** Quatro números do cabeçalho da tela. `equipe` são as contas com acesso ao painel. */
export async function resumo(): Promise<{ contas: number; equipe: number; novos7: number; punidos: number }> {
  const [contas, equipe, novos7, punidos] = await Promise.all([
    DB.value('SELECT COUNT(*) FROM users'),
    DB.value(`SELECT COUNT(*) FROM users WHERE role IN ('admin','owner')`),
    DB.value(`SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-7 days')`),
    DB.value(
      `SELECT COUNT(DISTINCT user_id) FROM bans
        WHERE revoked_at IS NULL AND (kind = 'perma' OR expires_at IS NULL OR expires_at > datetime('now'))`,
    ),
  ]);
  return { contas: Number(contas) | 0, equipe: Number(equipe) | 0, novos7: Number(novos7) | 0, punidos: Number(punidos) | 0 };
}

/** WHERE compartilhado pela listagem e pelo COUNT. */
function filtro(f: FiltrosLista): [string, DB.Params] {
  const cond: string[] = [];
  const par: DB.Params = {};

  const q = String(f.q ?? '').trim();
  if (q !== '') {
    // LIKE com escape manual: nome com '%' ou '_' é busca literal, não curinga.
    const alvo = '%' + q.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
    par.q = alvo;
    // SQLite: LIKE já ignora caixa em ASCII (como a collation do MySQL fazia).
    cond.push(`(u.username LIKE :q ESCAPE '\\' OR u.email LIKE :q ESCAPE '\\' OR p.display_name LIKE :q ESCAPE '\\')`);
  }

  const papel = String(f.papel ?? '');
  if (PAPEIS.includes(papel)) {
    cond.push('u.role = :papel');
    par.papel = papel;
  }

  const situacao = String(f.situacao ?? '');
  if (SITUACOES.includes(situacao)) {
    cond.push('u.status = :situacao');
    par.situacao = situacao;
  }

  return [cond.length === 0 ? '' : 'WHERE ' + cond.join(' AND '), par];
}

// ======================================================================= DOSSIÊ DE UM JOGADOR

/** Conta + ficha de jogador + passe da temporada. null quando o id não existe (vira 404). */
export async function conta(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  return DB.one<Dict>(
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

/** Inventário resumido: quanto o jogador carrega, onde, e as peças de maior valor. */
export async function inventario(id: number): Promise<{ itens: number; peso: number; valor: number; containers: Dict[]; destaques: Dict[] }> {
  const [tot, containers, destaques] = await Promise.all([
    DB.one<Dict>(
      `SELECT COALESCE(SUM(pi.quantity),0) itens,
              COALESCE(SUM(i.weight_kg * pi.quantity),0) peso,
              COALESCE(SUM(i.base_price * pi.quantity),0) valor
         FROM player_items pi JOIN items i ON i.id = pi.item_id
        WHERE pi.user_id = :id`,
      { id },
    ),
    DB.all<Dict>(
      `SELECT pi.container, COUNT(*) linhas, COALESCE(SUM(pi.quantity),0) itens,
              COALESCE(SUM(i.weight_kg * pi.quantity),0) peso
         FROM player_items pi JOIN items i ON i.id = pi.item_id
        WHERE pi.user_id = :id
        GROUP BY pi.container
        ORDER BY itens DESC, pi.container ASC`,
      { id },
    ),
    DB.all<Dict>(
      `SELECT i.name, i.rarity, i.category, pi.quantity, pi.container,
              (i.base_price * pi.quantity) AS valor
         FROM player_items pi JOIN items i ON i.id = pi.item_id
        WHERE pi.user_id = :id
        ORDER BY valor DESC, i.name ASC
        LIMIT 6`,
      { id },
    ),
  ]);
  return {
    itens: Math.trunc(Number(tot?.itens ?? 0)),
    peso: Number(tot?.peso ?? 0) || 0,
    valor: Math.trunc(Number(tot?.valor ?? 0)),
    containers,
    destaques,
  };
}

/** Missões do jogador; `locked` fica de fora (missão travada é ausência de progresso). */
export function missoes(id: number): Promise<Dict[]> {
  // FIELD(pm.status, 'active','done','available','disponivel') -> CASE (fora da lista = 0, vem primeiro).
  return DB.all<Dict>(
    `SELECT m.name, m.type, m.difficulty, m.act, COALESCE(m.target,0) alvo,
            pm.status, pm.progress, pm.updated_at
       FROM player_missions pm JOIN missions m ON m.id = pm.mission_id
      WHERE pm.user_id = :id AND pm.status <> 'locked'
      ORDER BY CASE pm.status WHEN 'active' THEN 1 WHEN 'done' THEN 2 WHEN 'available' THEN 3 WHEN 'disponivel' THEN 4 ELSE 0 END,
               pm.updated_at DESC
      LIMIT 12`,
    { id },
  );
}

/** Conquistas: concluídas, total e as linhas do cartão. */
export async function conquistas(id: number): Promise<{ feitas: number; total: number; linhas: Dict[] }> {
  const [feitas, total, linhas] = await Promise.all([
    DB.value('SELECT COUNT(*) FROM player_achievements WHERE user_id = :id AND completed_at IS NOT NULL', { id }),
    DB.value('SELECT COUNT(*) FROM achievements'),
    DB.all<Dict>(
      `SELECT a.name, a.category, a.target, pa.progress, pa.completed_at
         FROM player_achievements pa JOIN achievements a ON a.id = pa.achievement_id
        WHERE pa.user_id = :id
        ORDER BY pa.completed_at IS NULL, pa.completed_at DESC, pa.progress DESC
        LIMIT 8`,
      { id },
    ),
  ]);
  return { feitas: Number(feitas) | 0, total: Number(total) | 0, linhas };
}

/** Reputação por facção. */
export function faccoes(id: number): Promise<Dict[]> {
  return DB.all<Dict>(
    `SELECT faction, reputation, tier, contract_active, locked_until, updated_at
       FROM player_factions WHERE user_id = :id
      ORDER BY reputation DESC, faction ASC`,
    { id },
  );
}

/** Últimas 8 partidas + o agregado da carreira inteira (não é a soma das linhas mostradas). */
export async function partidas(id: number): Promise<{ linhas: Dict[]; total: number; extracoes: number; abates: number; ganhos: number }> {
  const [ag, linhas] = await Promise.all([
    DB.one<Dict>(
      `SELECT COUNT(*) total,
              COALESCE(SUM(result = 'extraido'),0) extracoes,
              COALESCE(SUM(kills),0) abates,
              COALESCE(SUM(earnings),0) ganhos
         FROM player_matches WHERE user_id = :id`,
      { id },
    ),
    DB.all<Dict>(
      `SELECT pm.result, pm.kills, pm.xp, pm.earnings, pm.played_at, mp.name AS mapa
         FROM player_matches pm LEFT JOIN maps mp ON mp.id = pm.map_id
        WHERE pm.user_id = :id
        ORDER BY pm.played_at DESC, pm.id DESC
        LIMIT 8`,
      { id },
    ),
  ]);
  return {
    linhas,
    total: Number(ag?.total ?? 0) | 0,
    extracoes: Number(ag?.extracoes ?? 0) | 0,
    abates: Number(ag?.abates ?? 0) | 0,
    ganhos: Math.trunc(Number(ag?.ganhos ?? 0)),
  };
}

// ================================================================================== PUNIÇÕES

/** O banimento em vigor: não revogado E (permanente OU com prazo no futuro). */
export function banAtivo(id: number): Promise<Dict | null> {
  return DB.one<Dict>(
    `SELECT b.*, ua.username AS admin_nome
       FROM bans b LEFT JOIN users ua ON ua.id = b.admin_id
      WHERE b.user_id = :id AND b.revoked_at IS NULL
        AND (b.kind = 'perma' OR b.expires_at IS NULL OR b.expires_at > datetime('now'))
      ORDER BY b.created_at DESC, b.id DESC LIMIT 1`,
    { id },
  );
}

/** Histórico de punições da conta (as 10 mais recentes). */
export function historicoBans(id: number): Promise<Dict[]> {
  return DB.all<Dict>(
    `SELECT b.id, b.reason, b.kind, b.expires_at, b.created_at, b.revoked_at,
            ua.username AS admin_nome, ur.username AS revogou_nome
       FROM bans b
       LEFT JOIN users ua ON ua.id = b.admin_id
       LEFT JOIN users ur ON ur.id = b.revoked_by
      WHERE b.user_id = :id
      ORDER BY b.created_at DESC, b.id DESC
      LIMIT 10`,
    { id },
  );
}

/**
 * Aplica a punição: grava em `bans` e deixa users.status coerente por BanRepo.sincronizar()
 * (a ÚNICA dona da coluna quando o motivo é punição).
 *
 * DIFERENÇA DO PHP: lá as duas escritas iam numa transação. O D1 não tem transação
 * interativa e sincronizar() é uma função própria (lê e depois escreve), então aqui são duas
 * idas seguidas. Se a segunda falhar, o ban fica registrado e a conta é recalculada na
 * próxima sincronização (AccountGuard ou reabertura da tela).
 */
export async function aplicarBan(userId: number, adminId: number, motivo: string, tipo: string, expira: string | null): Promise<number> {
  const id = await DB.insert('bans', {
    user_id: userId,
    admin_id: adminId,
    reason: motivo,
    kind: tipo,
    expires_at: tipo === 'perma' ? null : expira,
  });
  await sincronizar(userId);
  return id;
}

/**
 * Revoga o banimento (só o que ainda não foi revogado) e recalcula a situação da conta —
 * por sincronizar(), porque a conta pode ter OUTRA punição em vigor.
 */
export async function revogarBan(banId: number, userId: number, adminId: number): Promise<boolean> {
  const r = await DB.run(
    `UPDATE bans SET revoked_at = datetime('now'), revoked_by = :admin
      WHERE id = :id AND user_id = :user AND revoked_at IS NULL`,
    { admin: adminId, id: banId, user: userId },
  );
  if (DB.changes(r) === 0) return false;
  await sincronizar(userId);
  return true;
}

/**
 * Devolve à ativa as contas cuja punição com PRAZO já venceu (não há cron: a abertura da tela
 * é o gatilho). Só alcança contas que TÊM histórico em `bans`. Devolve quantas liberou.
 */
export async function liberarVencidos(): Promise<number> {
  const r = await DB.run(
    `UPDATE users SET status = 'active'
      WHERE status IN ('banned', 'suspended')
        AND NOT EXISTS (
            SELECT 1 FROM bans b
             WHERE b.user_id = users.id AND b.revoked_at IS NULL
               AND (b.expires_at IS NULL OR b.expires_at > datetime('now'))
        )
        AND EXISTS (SELECT 1 FROM bans b2 WHERE b2.user_id = users.id)`,
  );
  return DB.changes(r);
}

// ========================================================================== ESCRITA NA CONTA

/** Papel e situação. Nenhuma outra coluna de `users` é escrita por esta tela. */
export async function atualizarConta(id: number, papel: string, situacao: string): Promise<void> {
  await DB.run('UPDATE users SET role = :r, status = :s WHERE id = :id', { r: papel, s: situacao, id });
}

/**
 * O rastro que a conta deixou no jogo (só o que for > 0). Decide se ela pode ser APAGADA:
 * partida, compra e punição são registro histórico do mundo.
 */
export async function referencias(id: number): Promise<Record<string, number>> {
  const consultas: [string, string][] = [
    ['partidas', 'SELECT COUNT(*) FROM player_matches WHERE user_id = :id'],
    ['compras', 'SELECT COUNT(*) FROM player_purchases WHERE user_id = :id'],
    ['punicoes', 'SELECT COUNT(*) FROM bans WHERE user_id = :id'],
    ['itens', 'SELECT COUNT(*) FROM player_items WHERE user_id = :id'],
    ['missoes', 'SELECT COUNT(*) FROM player_missions WHERE user_id = :id'],
    ['conquistas', 'SELECT COUNT(*) FROM player_achievements WHERE user_id = :id'],
  ];
  const ns = await Promise.all(consultas.map(([, sql]) => DB.value(sql, { id })));
  const out: Record<string, number> = {};
  consultas.forEach(([chave], i) => {
    const n = Number(ns[i]) | 0;
    if (n > 0) out[chave] = n;
  });
  return out;
}

/** Tabelas player_* varridas ao apagar a conta (o lote inteiro roda atômico no D1). */
const TABELAS_DO_JOGADOR = [
  'player_items', 'player_missions', 'player_achievements', 'player_factions',
  'player_matches', 'player_season', 'player_settings', 'player_skills',
  'player_challenges', 'player_purchases', 'player_safe_roles',
  'player_bonus_claims', 'player_bug_reports', 'players',
];

/**
 * Remove a conta e a ficha de jogador. Só depois de `referencias()` voltar vazio. As tabelas
 * player_* são varridas mesmo assim para não deixar órfão.
 */
export async function excluirConta(id: number): Promise<void> {
  // Só as tabelas que existem no D1 (um DELETE em tabela inexistente derrubaria o lote todo).
  const existentes = new Set(
    (await DB.all<Dict>(`SELECT name FROM sqlite_master WHERE type = 'table'`)).map((r) => String(r.name)),
  );
  const stmts = TABELAS_DO_JOGADOR.filter((t) => existentes.has(t)).map((t) => DB.prepare(`DELETE FROM "${t}" WHERE user_id = :id`, { id }));
  stmts.push(DB.prepare('DELETE FROM users WHERE id = :id', { id }));
  await DB.batch(stmts);
}

// ============================================================= REGRAS DE HIERARQUIA

/** Peso do papel: jogador 0, administrador 1, dono 2. */
export function peso(papel: string): number {
  const i = PAPEIS.indexOf(papel);
  return i < 0 ? 0 : i;
}

/**
 * Um operador só age sobre conta de papel ESTRITAMENTE inferior ao seu, e nunca sobre a
 * própria (senão "não rebaixe a si mesmo" seria contornável em dois cliques).
 */
export function podeAgirSobre(operador: Dict, alvo: Dict): boolean {
  if ((Number(operador.id) | 0) === (Number(alvo.id) | 0)) return false;
  return peso(String(operador.role)) > peso(String(alvo.role));
}
