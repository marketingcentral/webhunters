/**
 * Punições de conta: a tabela `bans` e o reflexo dela em `users.status` — o App\Repo\BanRepo do PHP.
 *
 * `bans` é o histórico (o que aconteceu, quando, por ordem de quem) e `users.status` é o
 * estado atual (a conta entra ou não entra). Quem as concilia é um lugar só: aqui.
 *
 * ESTADO DE UM BAN é derivado, nunca uma coluna:
 *   revogado -> revoked_at preenchido (alguém desfez)
 *   ativo    -> sem revogação e sem prazo, ou com prazo ainda no futuro
 *   expirado -> sem revogação e com prazo já vencido
 *
 * O prazo vencendo NÃO dispara nada por si só (não há cron): quem devolve a conta ao ar é
 * reconciliar(), chamada quando a tela abre, e sincronizar(), chamada pelo AccountGuard.
 *
 * Datas: `datetime('now')` do SQLite é UTC (ver README, "Datas").
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';

/**
 * Tipos de punição. A chave vai para `bans.kind`; `status` é o que a punição escreve em
 * `users.status` enquanto estiver ativa. 'perma' é o mesmo literal do dashboard.
 */
export const TIPOS: Record<string, { rotulo: string; status: string; prazo: boolean; tom: string }> = {
  temp: { rotulo: 'Temporário', status: 'banned', prazo: true, tom: 'warn' },
  perma: { rotulo: 'Definitivo', status: 'banned', prazo: false, tom: 'danger' },
  suspensao: { rotulo: 'Suspensão', status: 'suspended', prazo: true, tom: 'info' },
};

/** Estados derivados aceitos como filtro. 'todos' é a ausência de filtro. */
export const ESTADOS = ['ativo', 'expirado', 'revogado'];

/** A expressão de estado, uma vez só (a API de gestão usa a MESMA regra). */
export const ESTADO = `CASE
            WHEN b.revoked_at IS NOT NULL THEN 'revogado'
            WHEN b.expires_at IS NULL OR b.expires_at > datetime('now') THEN 'ativo'
            ELSE 'expirado'
        END`;

/** Um ban conta como ativo quando não foi revogado e o prazo não venceu. */
export const VIGENTE = `b.revoked_at IS NULL AND (b.expires_at IS NULL OR b.expires_at > datetime('now'))`;

/**
 * Recalcula `users.status` a partir das punições vigentes e devolve o status resultante.
 * Punição dura (temp/perma) => 'banned'; só leve (suspensão) => 'suspended'; nada => 'active'.
 */
export async function sincronizar(userId: number): Promise<string> {
  if (userId <= 0) return 'active';

  // Os tipos entram como literal (constantes do código), idênticos aos de reconciliar().
  const linha =
    (await DB.one<Dict>(
      `SELECT
           MAX(CASE WHEN b.kind IN ('temp','perma') THEN 1 ELSE 0 END) AS duro,
           MAX(CASE WHEN b.kind NOT IN ('temp','perma') THEN 1 ELSE 0 END) AS leve
         FROM bans b
        WHERE b.user_id = :u AND ${VIGENTE}`,
      { u: userId },
    )) ?? {};

  const status = (Number(linha.duro ?? 0) | 0) === 1 ? 'banned' : (Number(linha.leve ?? 0) | 0) === 1 ? 'suspended' : 'active';

  // `status <> :atual` para não gastar escrita à toa.
  await DB.run('UPDATE users SET status = :novo WHERE id = :u AND status <> :novo', { novo: status, u: userId });
  return status;
}

/** Ordenações permitidas -> ORDER BY. A URL não escreve SQL; ela escolhe de uma lista. */
const ORDENS: Record<string, string> = {
  recente: 'b.created_at DESC, b.id DESC',
  antigo: 'b.created_at ASC, b.id ASC',
  validade: 'b.expires_at IS NULL, b.expires_at ASC, b.id DESC',
  conta: 'u.username ASC, b.created_at DESC',
};

export const POR_PAGINA = 15;

/** Colunas de um ban com os dois usuários resolvidos (listagem e porId mostram o MESMO). */
const COLUNAS = `b.id, b.user_id, b.admin_id, b.reason, b.kind, b.expires_at, b.created_at,
                    b.revoked_at, b.revoked_by, b.revoke_reason,
                    ${ESTADO} AS estado,
                    u.username AS alvo_nome, u.email AS alvo_email,
                    u.status AS alvo_status, u.role AS alvo_papel,
                    a.username AS admin_nome,
                    r.username AS revogador_nome
               FROM bans b
               JOIN users u ON u.id = b.user_id
               LEFT JOIN users a ON a.id = b.admin_id
               LEFT JOIN users r ON r.id = b.revoked_by`;

export interface FiltrosBan {
  busca?: string;
  estado?: string;
  tipo?: string;
  ordem?: string;
  pagina?: number;
}

export interface ListaBans {
  linhas: Dict[];
  total: number;
  pagina: number;
  paginas: number;
  primeiro: number;
  ultimo: number;
}

// ======================================================================== leitura

/** Página da listagem, já com os dois lados do JOIN (quem foi punido, quem puniu). */
export async function listar(filtros: FiltrosBan = {}): Promise<ListaBans> {
  const [onde, params] = filtro(filtros);
  const ordem = ORDENS[String(filtros.ordem ?? '')] ?? ORDENS.recente!;

  const total = Number(await DB.value('SELECT COUNT(*) FROM bans b JOIN users u ON u.id = b.user_id WHERE ' + onde, params)) | 0;

  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  const pagina = Math.max(1, Math.min(paginas, Math.trunc(Number(filtros.pagina ?? 1)) || 1));
  // LIMIT/OFFSET como literal: são inteiros calculados aqui.
  const offset = (pagina - 1) * POR_PAGINA;

  const linhas = await DB.all<Dict>(
    `SELECT ${COLUNAS}
              WHERE ${onde}
              ORDER BY ${ordem}
              LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    params,
  );

  return {
    linhas,
    total,
    pagina,
    paginas,
    primeiro: total === 0 ? 0 : offset + 1,
    ultimo: Math.min(total, offset + linhas.length),
  };
}

/** Quantos bans em cada estado, mais quantas CONTAS distintas estão punidas agora. */
export async function contagens(): Promise<{ ativo: number; expirado: number; revogado: number; total: number; contas: number }> {
  const linha =
    (await DB.one<Dict>(
      `SELECT COUNT(*) AS total,
              SUM(CASE WHEN b.revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revogado,
              SUM(CASE WHEN ${VIGENTE} THEN 1 ELSE 0 END) AS ativo,
              SUM(CASE WHEN b.revoked_at IS NULL AND b.expires_at IS NOT NULL
                            AND b.expires_at <= datetime('now') THEN 1 ELSE 0 END) AS expirado,
              COUNT(DISTINCT CASE WHEN ${VIGENTE} THEN b.user_id END) AS contas
         FROM bans b`,
    )) ?? {};
  return {
    total: Number(linha.total ?? 0) | 0,
    ativo: Number(linha.ativo ?? 0) | 0,
    expirado: Number(linha.expirado ?? 0) | 0,
    revogado: Number(linha.revogado ?? 0) | 0,
    contas: Number(linha.contas ?? 0) | 0,
  };
}

/** Um ban com os dois usuários resolvidos. */
export async function porId(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  return DB.one<Dict>(`SELECT ${COLUNAS} WHERE b.id = :id LIMIT 1`, { id });
}

/** Resolve o alvo digitado no formulário: id numérico, nome de usuário ou e-mail. */
export async function usuarioPorLogin(login: string): Promise<Dict | null> {
  login = login.trim();
  if (login === '') return null;
  if (/^\d+$/.test(login)) {
    return DB.one<Dict>('SELECT id, username, email, role, status FROM users WHERE id = :id LIMIT 1', { id: Number(login) });
  }
  return DB.one<Dict>(
    `SELECT id, username, email, role, status FROM users
      WHERE username = :login1 OR email = :login2
      ORDER BY id LIMIT 1`,
    { login1: login, login2: login },
  );
}

/** Chaves de ordenação aceitas (o controller valida `?ordem=` contra a MESMA lista do SQL). */
export function ordensValidas(): string[] {
  return Object.keys(ORDENS);
}

/** A punição VIGENTE da conta, se houver. */
export async function vigenteDe(userId: number): Promise<Dict | null> {
  if (userId <= 0) return null;
  return DB.one<Dict>(
    `SELECT b.id, b.kind, b.reason, b.expires_at, b.created_at
       FROM bans b
      WHERE b.user_id = :u AND ${VIGENTE}
      ORDER BY b.created_at DESC, b.id DESC
      LIMIT 1`,
    { u: userId },
  );
}

/** Sugestões de alvo para o datalist do formulário (contas ativas primeiro). */
export async function contasParaEscolha(limite = 50): Promise<Dict[]> {
  limite = Math.max(1, Math.min(200, Math.trunc(limite)));
  return DB.all<Dict>(
    `SELECT id, username, email, role, status
       FROM users
      ORDER BY status = 'active' DESC, username ASC
      LIMIT ${limite}`,
  );
}

// ======================================================================== escrita

/**
 * O mesmo recálculo de sincronizar(), como UM statement (para entrar no batch() junto do
 * ban). MAX de conjunto vazio é NULL e cai no ELSE 'active'.
 */
function sincronizarStmt(userId: number): D1PreparedStatement {
  return DB.prepare(
    `UPDATE users SET status = (
        SELECT CASE
                 WHEN MAX(CASE WHEN b.kind IN ('temp','perma') THEN 1 ELSE 0 END) = 1 THEN 'banned'
                 WHEN MAX(CASE WHEN b.kind NOT IN ('temp','perma') THEN 1 ELSE 0 END) = 1 THEN 'suspended'
                 ELSE 'active'
               END
          FROM bans b
         WHERE b.user_id = :u AND ${VIGENTE})
      WHERE id = :u`,
    { u: userId },
  );
}

/**
 * Aplica a punição e deixa `users.status` coerente com ela, no MESMO lote atômico (o
 * Database::transaction do PHP). `expiraEm` é 'Y-m-d H:i:s' (UTC) ou null (definitivo).
 * Devolve o id do ban criado.
 */
export async function aplicar(userId: number, adminId: number | null, tipo: string, motivo: string, expiraEm: string | null): Promise<number> {
  tipo = TIPOS[tipo] ? tipo : 'temp';
  const r = await DB.batch([
    DB.insertStmt('bans', {
      user_id: userId,
      admin_id: adminId !== null && adminId > 0 ? adminId : null,
      reason: motivo,
      kind: tipo,
      // Definitivo não tem prazo: gravar uma data criaria um ban que se desfaz sozinho.
      expires_at: TIPOS[tipo]!.prazo ? expiraEm : null,
    }),
    sincronizarStmt(userId),
  ]);
  return Number(r[0]?.meta.last_row_id ?? 0);
}

/**
 * Derruba um ban vigente. false quando o ban já estava revogado — a condição está no próprio
 * UPDATE para dois operadores ao mesmo tempo não sobrescreverem a assinatura um do outro.
 */
export async function revogar(banId: number, adminId: number | null, motivo: string): Promise<boolean> {
  if (banId <= 0) return false;
  const userId = Number(await DB.value('SELECT user_id FROM bans WHERE id = :id', { id: banId })) | 0;
  if (userId <= 0) return false;
  const r = await DB.batch([
    DB.prepare(
      `UPDATE bans
          SET revoked_at = datetime('now'), revoked_by = :admin, revoke_reason = :motivo
        WHERE id = :id AND revoked_at IS NULL`,
      { admin: adminId !== null && adminId > 0 ? adminId : null, motivo, id: banId },
    ),
    sincronizarStmt(userId),
  ]);
  return DB.changes(r[0]!) > 0;
}

/**
 * Apaga uma linha do histórico. A condição `NÃO vigente` mora no SQL: uma punição em vigor
 * nunca sai da tabela pela porta dos fundos.
 */
export async function remover(banId: number): Promise<boolean> {
  if (banId <= 0) return false;
  const userId = Number(await DB.value('SELECT user_id FROM bans WHERE id = :id', { id: banId })) | 0;
  if (userId <= 0) return false;
  const r = await DB.batch([
    DB.prepare(
      `DELETE FROM bans
        WHERE id = :id
          AND (revoked_at IS NOT NULL OR (expires_at IS NOT NULL AND expires_at <= datetime('now')))`,
      { id: banId },
    ),
    sincronizarStmt(userId),
  ]);
  return DB.changes(r[0]!) > 0;
}

/**
 * Passa a régua entre `bans` e `users.status` e devolve o que estava divergente. É isto que
 * faz um ban temporário acabar (não há agendador). O JOIN é INTERNO de propósito: só entram
 * contas que TÊM linha em `bans`.
 */
export async function reconciliar(): Promise<Array<{ id: number; username: string; de: string; para: string }>> {
  const divergentes = await DB.all<Dict>(
    `SELECT u.id, u.username, u.status AS atual,
            CASE
              WHEN MAX(CASE WHEN ${VIGENTE}
                             AND b.kind IN ('temp','perma') THEN 1 ELSE 0 END) = 1 THEN 'banned'
              WHEN MAX(CASE WHEN ${VIGENTE}
                             AND b.kind NOT IN ('temp','perma') THEN 1 ELSE 0 END) = 1 THEN 'suspended'
              ELSE 'active'
            END AS desejado
       FROM users u
       JOIN bans b ON b.user_id = u.id
      GROUP BY u.id, u.username, u.status
     HAVING desejado <> atual`,
  );
  if (divergentes.length === 0) return [];

  await DB.batch(divergentes.map((d) => DB.prepare('UPDATE users SET status = :s WHERE id = :u', { s: String(d.desejado), u: Number(d.id) | 0 })));
  return divergentes.map((d) => ({
    id: Number(d.id) | 0,
    username: String(d.username),
    de: String(d.atual),
    para: String(d.desejado),
  }));
}

// ========================================================================== apoio

/**
 * WHERE + parâmetros da listagem. Base sempre verdadeira ('1=1') para concatenar sem checar.
 * LIKE ganha ESCAPE explícito: no SQLite não há caractere de escape padrão (no MySQL era '\').
 */
function filtro(f: FiltrosBan): [string, DB.Params] {
  const onde = ['1=1'];
  const params: DB.Params = {};

  const busca = String(f.busca ?? '').trim();
  if (busca !== '') {
    // `#12` procura o ban 12; o resto procura em conta, e-mail e motivo.
    const m = /^#(\d+)$/.exec(busca);
    if (m) {
      onde.push('b.id = :bid');
      params.bid = Number(m[1]);
    } else {
      onde.push(`(u.username LIKE :q1 ESCAPE '!' OR u.email LIKE :q2 ESCAPE '!' OR b.reason LIKE :q3 ESCAPE '!')`);
      const curinga = '%' + busca.replace(/[!%_]/g, (c) => '!' + c) + '%';
      params.q1 = params.q2 = params.q3 = curinga;
    }
  }

  const estado = String(f.estado ?? '');
  if (ESTADOS.includes(estado)) {
    onde.push(ESTADO + ' = :estado');
    params.estado = estado;
  }

  const tipo = String(f.tipo ?? '');
  if (TIPOS[tipo]) {
    onde.push('b.kind = :tipo');
    params.tipo = tipo;
  }

  return [onde.join(' AND '), params];
}
