import { ctx } from './context';

/**
 * Camada fina sobre o D1 — o App\Core\Database do PHP.
 *
 * Os repositórios PHP usam placeholders NOMEADOS (`:id`, `:u`), e o D1 só aceita
 * posicionais (`?1`, `?2`). Para o SQL portado ficar igual ao do PHP, cada `:nome` vira `?N`
 * aqui, pulando o que estiver entre aspas (um literal '12:30' não é parâmetro).
 *
 * Transações: o D1 não tem BEGIN/COMMIT por conexão. O que no PHP era
 * Database::transaction() vira batch() — o D1 executa o lote inteiro numa transação.
 */
export type Row = Record<string, unknown>;
export type Params = Record<string, unknown>;

const cacheSql = new Map<string, { sql: string; nomes: string[] }>();

function compilar(sql: string): { sql: string; nomes: string[] } {
  const pronto = cacheSql.get(sql);
  if (pronto) return pronto;
  const nomes: string[] = [];
  let out = '';
  let aspas: string | null = null;
  for (let i = 0; i < sql.length; i++) {
    const c = sql[i]!;
    if (aspas) {
      out += c;
      if (c === aspas) aspas = null;
      continue;
    }
    if (c === "'" || c === '"' || c === '`') {
      aspas = c;
      out += c;
      continue;
    }
    if (c === ':' && sql[i - 1] !== ':' && /[A-Za-z_]/.test(sql[i + 1] ?? '')) {
      let j = i + 1;
      while (j < sql.length && /\w/.test(sql[j]!)) j++;
      const nome = sql.slice(i + 1, j);
      let idx = nomes.indexOf(nome);
      if (idx < 0) {
        nomes.push(nome);
        idx = nomes.length - 1;
      }
      out += '?' + (idx + 1);
      i = j - 1;
      continue;
    }
    out += c;
  }
  const r = { sql: out, nomes };
  cacheSql.set(sql, r);
  return r;
}

function valor(v: unknown): unknown {
  if (v === undefined) return null;
  if (typeof v === 'boolean') return v ? 1 : 0;
  if (v !== null && typeof v === 'object') return JSON.stringify(v);
  return v;
}

export function db(): D1Database {
  return ctx().env.DB;
}

/** Prepara um statement com parâmetros nomeados. */
export function prepare(sql: string, params: Params = {}): D1PreparedStatement {
  const c = compilar(sql);
  const stmt = db().prepare(c.sql);
  if (c.nomes.length === 0) return stmt;
  return stmt.bind(
    ...c.nomes.map((n) => {
      if (!(n in params)) throw new Error(`Parâmetro :${n} sem valor em: ${sql.slice(0, 80)}`);
      return valor(params[n]);
    }),
  );
}

export async function run(sql: string, params: Params = {}): Promise<D1Result> {
  return prepare(sql, params).run();
}

export async function one<T = Row>(sql: string, params: Params = {}): Promise<T | null> {
  return (await prepare(sql, params).first<T>()) ?? null;
}

export async function all<T = Row>(sql: string, params: Params = {}): Promise<T[]> {
  return (await prepare(sql, params).all<T>()).results ?? [];
}

export async function value<T = unknown>(sql: string, params: Params = {}): Promise<T | null> {
  const r = await prepare(sql, params).raw<unknown[]>();
  const first = r[0];
  return first ? ((first[0] as T) ?? null) : null;
}

/** INSERT a partir de um objeto; devolve o id novo. */
export async function insert(table: string, data: Params): Promise<number> {
  const r = await insertStmt(table, data).run();
  return Number(r.meta.last_row_id ?? 0);
}

export function insertStmt(table: string, data: Params): D1PreparedStatement {
  const cols = Object.keys(data);
  const sql = `INSERT INTO "${table}" (${cols.map((c) => `"${c}"`).join(',')}) VALUES (${cols.map((c) => ':' + c).join(',')})`;
  return prepare(sql, data);
}

/** O Database::transaction() do PHP: o lote roda atômico no D1. */
export async function batch(stmts: D1PreparedStatement[]): Promise<D1Result[]> {
  if (stmts.length === 0) return [];
  return db().batch(stmts);
}

/** Linhas alteradas por um run() (o rowCount() do PDO). */
export function changes(r: D1Result): number {
  return Number(r.meta.changes ?? 0);
}

/** "Agora" no formato DATETIME que as colunas guardam (UTC, como o datetime('now') do SQLite). */
export function agora(): string {
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}
