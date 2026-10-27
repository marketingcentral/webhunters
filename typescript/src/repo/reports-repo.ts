/**
 * Leitura e escrita da tela ADMIN · RELATÓRIOS (/admin/relatorios) — o App\Repo\ReportsRepo do PHP.
 *
 * Duas tabelas, um único formato de saída: `player_bug_reports` (tela RELATÓRIO DE BUG) e
 * `support_tickets` (tela SUPORTE). A view não sabe qual das duas está desenhando — recebe as
 * mesmas chaves e um `tipo`. A diferença entre elas cabe numa tabela de metadados (FONTES).
 *
 * SEGURANÇA: nada de valor de usuário entra em SQL como texto. ORDER BY / LIMIT saem de listas
 * fechadas daqui (ORDENS) ou de inteiros.
 *
 * Datas: lidas como UTC (paraUnix do admin-repo), ver README ("Datas").
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import { agoraUnix, paraUnix } from './admin-repo';

interface Fonte {
  tabela: string;
  codigo: string;
  titulo: string;
  grupo: string;
  peso: string;
  corpo: string;
  busca: string[];
}

/** Aba => tabela e colunas que mudam de nome entre as duas. */
const FONTES: Record<string, Fonte> = {
  bugs: {
    tabela: 'player_bug_reports',
    codigo: 'code', // identificador que o jogador vê (BUG-4812)
    titulo: 'title',
    grupo: 'area', // campo "categoria" desta aba
    peso: 'severity', // campo "gravidade" desta aba
    corpo: 'description',
    busca: ['code', 'title', 'description', 'steps'],
  },
  suporte: {
    tabela: 'support_tickets',
    codigo: 'protocol',
    titulo: 'subject',
    grupo: 'category',
    peso: 'priority',
    corpo: 'message',
    busca: ['protocol', 'subject', 'message'],
  },
};

/**
 * Estados de cada aba, na ordem do ciclo. `true` marca o estado terminal (carimba
 * `closed_at` nos bugs). Mesma lista de app/Data/admin-relatorios.php: lá ficam os rótulos,
 * aqui a regra que o banco obedece.
 */
const ESTADOS: Record<string, Record<string, boolean>> = {
  bugs: { recebido: false, analise: false, corrigido: true, duplicado: true, recusado: true },
  suporte: { aberto: false, analise: false, resolvido: true, fechado: true },
};

/** FIELD(col, a, b, ...) do MySQL: posição 1..n, 0 quando não está na lista. */
function field(col: string, valores: string[]): string {
  return 'CASE ' + col + ' ' + valores.map((v, i) => `WHEN '${v}' THEN ${i + 1}`).join(' ') + ' ELSE 0 END';
}

const SEV = ['critica', 'alta', 'media', 'baixa'];
const PRIO = ['CRÍTICA', 'ALTA', 'NORMAL', 'BAIXA'];

/**
 * Ordenações permitidas, já em SQL. `peso` ordena do mais grave para o menos; um valor
 * desconhecido (FIELD = 0) não fura a fila na frente de uma crítica — daí o `=0` antes.
 */
const ORDENS: Record<string, Record<string, string>> = {
  bugs: {
    recentes: 'r.created_at DESC, r.id DESC',
    antigos: 'r.created_at ASC, r.id ASC',
    peso: `(${field('r.severity', SEV)})=0, ${field('r.severity', SEV)}, r.created_at DESC`,
  },
  suporte: {
    recentes: 'r.created_at DESC, r.id DESC',
    antigos: 'r.created_at ASC, r.id ASC',
    peso: `(${field('r.priority', PRIO)})=0, ${field('r.priority', PRIO)}, r.created_at DESC`,
  },
};

/**
 * O jogador dono do relatório entra por LEFT JOIN: a conta pode ter sido apagada e o
 * relatório continua existindo como registro de operação.
 */
const JUNCAO = ' LEFT JOIN users u ON u.id = r.user_id LEFT JOIN players p ON p.user_id = r.user_id';

/** Abas válidas — a whitelist que o controller usa. */
export function abas(): string[] {
  return Object.keys(FONTES);
}

/** Estados graváveis nesta aba, na ordem do ciclo. */
export function estados(aba: string): string[] {
  return Object.keys(ESTADOS[aba] ?? {});
}

/** O estado encerra o relatório (e, nos bugs, carimba closed_at)? */
export function encerra(aba: string, status: string): boolean {
  return Boolean(ESTADOS[aba]?.[status] ?? false);
}

/** Estados que ainda contam como fila de trabalho. */
export function estadosAbertos(aba: string): string[] {
  return Object.entries(ESTADOS[aba] ?? {})
    .filter(([, t]) => !t)
    .map(([k]) => k);
}

export interface Resumo {
  bugs_abertos: number;
  bugs_total: number;
  tickets_abertos: number;
  tickets_total: number;
  criticos: number;
}

/** Contagem de fila das DUAS abas, para os KPIs da topbar (sem filtro nenhum). */
export async function resumo(): Promise<Resumo> {
  const bugsAbertos = estadosAbertos('bugs');
  const tickAbertos = estadosAbertos('suporte');
  const [ba, bt, ta, tt, c1, c2] = await Promise.all([
    contar('player_bug_reports', 'status', bugsAbertos),
    DB.value('SELECT COUNT(*) FROM player_bug_reports'),
    contar('support_tickets', 'status', tickAbertos),
    DB.value('SELECT COUNT(*) FROM support_tickets'),
    // Gravidade máxima AINDA na fila, somando as duas tabelas.
    contar('player_bug_reports', 'severity', ['critica', 'alta'], 'status', bugsAbertos),
    contar('support_tickets', 'priority', ['CRÍTICA', 'ALTA'], 'status', tickAbertos),
  ]);
  return {
    bugs_abertos: ba,
    bugs_total: Number(bt) | 0,
    tickets_abertos: ta,
    tickets_total: Number(tt) | 0,
    criticos: c1 + c2,
  };
}

/**
 * Quantos relatórios existem em CADA estado, com os demais filtros aplicados mas ignorando
 * o filtro de estado — os chips dizem para onde cada um levaria.
 */
export async function contagemPorEstado(aba: string, filtros: Record<string, string>): Promise<Record<string, number>> {
  const f = FONTES[aba]!;
  const [onde, par] = criterios(aba, filtros, false);
  const out: Record<string, number> = {};
  for (const e of estados(aba)) out[e] = 0;
  const linhas = await DB.all<Dict>(`SELECT r.status, COUNT(*) AS n FROM ${f.tabela} r ${JUNCAO}${onde} GROUP BY r.status`, par);
  for (const l of linhas) out[String(l.status)] = Number(l.n) | 0;
  return out;
}

export interface Pagina {
  linhas: Dict[];
  total: number;
  pagina: number;
  paginas: number;
  de: number;
  ate: number;
}

/** Uma página da listagem. Filtros: q, status, grupo, peso, ord. */
export async function listar(aba: string, filtros: Record<string, string>, pagina = 1, porPagina = 20): Promise<Pagina> {
  const f = FONTES[aba]!;
  const [onde, par] = criterios(aba, filtros, true);

  const total = Number(await DB.value(`SELECT COUNT(*) FROM ${f.tabela} r ${JUNCAO}${onde}`, par)) | 0;

  // Página fora do fim volta para a última página que existe.
  porPagina = Math.max(5, Math.min(100, porPagina));
  const paginas = Math.max(1, Math.ceil(total / porPagina));
  pagina = Math.max(1, Math.min(pagina, paginas));
  const offset = (pagina - 1) * porPagina;

  const ordem = ORDENS[aba]![filtros.ord ?? ''] ?? ORDENS[aba]!.recentes!;

  const linhas =
    total === 0
      ? []
      : await DB.all<Dict>(
          `SELECT ${colunas(aba)} FROM ${f.tabela} r ${JUNCAO}${onde} ORDER BY ${ordem} LIMIT ${porPagina} OFFSET ${offset}`,
          par,
        );

  return {
    linhas: linhas.map((l) => normalizar(aba, l)),
    total,
    pagina,
    paginas,
    de: total === 0 ? 0 : offset + 1,
    ate: Math.min(offset + porPagina, total),
  };
}

/** Um relatório inteiro (com descrição e passos), ou null quando o id não existe. */
export async function detalhe(aba: string, id: number): Promise<Dict | null> {
  if (id <= 0 || !FONTES[aba]) return null;
  const f = FONTES[aba]!;
  const linha = await DB.one<Dict>(`SELECT ${colunas(aba, true)} FROM ${f.tabela} r ${JUNCAO} WHERE r.id = :id LIMIT 1`, { id });
  return linha === null ? null : normalizar(aba, linha, true);
}

/**
 * Grava o novo estado. false quando o id sumiu entre a leitura e o POST.
 * Nos bugs o `closed_at` acompanha o estado: terminal carimba (sem reescrever um carimbo
 * antigo), voltar para a fila limpa.
 */
export async function mudarEstado(aba: string, id: number, status: string): Promise<boolean> {
  if (ESTADOS[aba]?.[status] === undefined) return false; // dupla checagem
  const tabela = FONTES[aba]!.tabela;
  let sql: string;
  if (aba === 'bugs') {
    sql = encerra(aba, status)
      ? `UPDATE ${tabela} SET status = :s, closed_at = COALESCE(closed_at, datetime('now')) WHERE id = :id`
      : `UPDATE ${tabela} SET status = :s, closed_at = NULL WHERE id = :id`;
  } else {
    sql = `UPDATE ${tabela} SET status = :s WHERE id = :id`;
  }
  return DB.changes(await DB.run(sql, { s: status, id })) > 0;
}

/** Apaga um relatório. O chamador já conferiu que ele está encerrado. */
export async function excluir(aba: string, id: number): Promise<boolean> {
  if (!FONTES[aba] || id <= 0) return false;
  return DB.changes(await DB.run(`DELETE FROM ${FONTES[aba]!.tabela} WHERE id = :id`, { id })) > 0;
}

/** Valores de categoria realmente presentes na tabela — é isso que enche o seletor. */
export async function gruposEmUso(aba: string): Promise<string[]> {
  const f = FONTES[aba]!;
  const rows = await DB.all<Dict>(`SELECT DISTINCT ${f.grupo} AS v FROM ${f.tabela} WHERE ${f.grupo} <> '' ORDER BY v`);
  return rows.map((r) => String(r.v));
}

// ============================================================================ interno

/** Colunas do SELECT. O corpo (descrição/mensagem) só entra no detalhe. */
function colunas(aba: string, completo = false): string {
  const comum = 'r.id, r.user_id, r.status, r.created_at, r.attach_logs, u.username, p.display_name, p.avatar';
  if (aba === 'bugs') {
    const col = comum + ', r.code AS codigo, r.title AS titulo, r.area AS grupo, r.severity AS peso, r.closed_at';
    return completo ? col + ', r.description, r.steps' : col;
  }
  const col = comum + ', r.protocol AS codigo, r.subject AS titulo, r.category AS grupo, r.priority AS peso, r.updated_at';
  return completo ? col + ', r.message' : col;
}

/** WHERE + parâmetros a partir dos filtros da tela. */
function criterios(aba: string, filtros: Record<string, string>, comStatus: boolean): [string, DB.Params] {
  const f = FONTES[aba]!;
  const cond: string[] = [];
  const par: DB.Params = {};

  const status = String(filtros.status ?? '');
  if (comStatus && status !== '' && ESTADOS[aba]?.[status] !== undefined) {
    cond.push('r.status = :status');
    par.status = status;
  }

  const grupo = String(filtros.grupo ?? '');
  if (grupo !== '') {
    cond.push('r.' + f.grupo + ' = :grupo');
    par.grupo = grupo;
  }

  const peso = String(filtros.peso ?? '');
  if (peso !== '') {
    cond.push('r.' + f.peso + ' = :peso');
    par.peso = peso;
  }

  const q = String(filtros.q ?? '').trim();
  if (q !== '') {
    // Curingas do próprio usuário escapados: "100%" procura "100%". No SQLite o LIKE não
    // tem caractere de escape padrão — daí o ESCAPE '\' explícito (o MySQL já usava \).
    const termo = '%' + q.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
    const alvos = [...f.busca.map((c) => 'r.' + c), 'u.username', 'p.display_name'];
    const campos = alvos.map((coluna, i) => {
      par['q' + i] = termo;
      return `${coluna} LIKE :q${i} ESCAPE '\\'`;
    });
    cond.push('(' + campos.join(' OR ') + ')');
  }

  return [cond.length === 0 ? '' : ' WHERE ' + cond.join(' AND '), par];
}

/** Uma linha do banco no formato único que a view consome. */
function normalizar(aba: string, l: Dict, completo = false): Dict {
  const criado = paraUnix(l.created_at) || agoraUnix();
  const comum: Dict = {
    id: Number(l.id) | 0,
    tipo: aba,
    codigo: aba === 'bugs' ? String(l.codigo) : '#' + (Number(l.codigo) | 0),
    titulo: String(l.titulo),
    grupo: String(l.grupo),
    peso: String(l.peso),
    status: String(l.status),
    criado,
    logs: Boolean(Number(l.attach_logs)),
    user_id: Number(l.user_id) | 0,
    // display_name é o nome no jogo; username é a conta. Os dois vazios = conta apagada.
    jogador: String(l.display_name ?? '') || String(l.username ?? ''),
    conta: String(l.username ?? ''),
    avatar: String(l.avatar ?? ''),
  };

  if (aba === 'bugs') {
    const fechado = l.closed_at ?? null;
    comum.encerrado = fechado ? paraUnix(fechado) || null : null;
    if (completo) {
      comum.descricao = String(l.description ?? '');
      comum.passos = String(l.steps ?? '');
    }
    return comum;
  }

  comum.atualizado = paraUnix(l.updated_at) || criado;
  if (completo) {
    comum.descricao = String(l.message ?? '');
    comum.passos = '';
  }
  return comum;
}

/** COUNT com uma (ou duas) cláusulas IN de tamanho variável (placeholders por posição). */
async function contar(tabela: string, coluna: string, valores: string[], coluna2: string | null = null, valores2: string[] = []): Promise<number> {
  if (valores.length === 0 || (coluna2 !== null && valores2.length === 0)) return 0;
  const par: DB.Params = {};
  const cond = [coluna + ' IN (' + marcadores(valores, 'a', par) + ')'];
  if (coluna2 !== null) cond.push(coluna2 + ' IN (' + marcadores(valores2, 'b', par) + ')');
  return Number(await DB.value(`SELECT COUNT(*) FROM ${tabela} WHERE ${cond.join(' AND ')}`, par)) | 0;
}

function marcadores(valores: string[], prefixo: string, par: DB.Params): string {
  return valores
    .map((v, i) => {
      par[prefixo + i] = v;
      return ':' + prefixo + i;
    })
    .join(',');
}
