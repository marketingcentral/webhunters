/**
 * Leitura da trilha de auditoria (`admin_audit_log`) — o App\Repo\AuditRepo do PHP, o que a
 * tela /admin/logs mostra.
 *
 * Aqui só se LÊ: não existe INSERT, UPDATE ou DELETE neste arquivo (quem escreve é
 * AdminRepo.registrar). Toda consulta devolve `falha` (o banco não respondeu) separado de
 * `total` (respondeu e não há nada) — "não sei" e "não aconteceu" não podem virar a mesma
 * tabela vazia.
 *
 * O `admin_id` NÃO é chave estrangeira: apagar a conta não apaga a prova. O JOIN é LEFT e a
 * linha carrega três estados de autor — conta existente, conta removida, tentativa anônima.
 *
 * Datas: `created_at` é UTC (datetime('now') do SQLite) e é lido/mostrado como UTC, a mesma
 * convenção de admin-repo.ts (paraUnix/formatarData). Os filtros de dia comparam a string.
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import { corta, paraUnix } from './admin-repo';

/** Linhas por página. 25 é o que cabe inteiro em 1366×768 sem a tabela rolar por dentro. */
export const POR_PAGINA = 25;

/** Colunas ordenáveis: chave da URL => expressão SQL (LISTA BRANCA, nunca texto da URL). */
const ORDENS: Record<string, string> = {
  quando: 'a.created_at',
  acao: 'a."action"',
  quem: "COALESCE(u.username, '')",
  alvo: 'a.entity',
};

/** Teto do termo de busca. */
const BUSCA_MAX = 60;

/** Campos varridos pela busca livre. */
const CAMPOS_BUSCA = ['a."action"', 'a.entity', 'a.entity_id', 'a.summary', 'a.ip', 'u.username'];

/** SELECT único da tela: a lista e o detalhe mostram exatamente as mesmas colunas. */
const SELECT = `SELECT a.id, a.admin_id, a."action", a.entity, a.entity_id, a.summary,
                       a.meta, a.ip, a.created_at,
                       u.username, u.role, u.status
                  FROM admin_audit_log a
             LEFT JOIN users u ON u.id = a.admin_id`;

export interface FiltroAudit {
  q: string;
  acao: string;
  quem: string;
  de: string;
  ate: string;
  ord: string;
  dir: string;
  pag: number;
  log: number;
}

export interface LinhaAudit {
  id: number;
  acao: string;
  entidade: string;
  alvo: string;
  resumo: string;
  ip: string;
  quando: string;
  quando_ts: number;
  meta: Dict | null;
  meta_bruto: string;
  quem: { tipo: string; id: number; nome: string; papel: string; situacao: string };
}

export interface PaginaAudit {
  linhas: LinhaAudit[];
  total: number;
  pagina: number;
  paginas: number;
  por_pagina: number;
  falha: boolean;
}

/**
 * Normaliza o que veio da query string: data inválida vira vazio, ordenação desconhecida cai
 * no padrão, página negativa vira 1. `acao` NÃO é conferida contra a lista existente.
 */
export function normalizar(bruto: Record<string, string | null | undefined>): FiltroAudit {
  const texto = (v: string | null | undefined, max: number): string => corta(String(v ?? '').trim(), max);

  let de = data(bruto.de);
  let ate = data(bruto.ate);
  // Datas trocadas seriam um recorte impossível: o par é endireitado em silêncio.
  if (de !== '' && ate !== '' && de > ate) [de, ate] = [ate, de];

  let quem = texto(bruto.quem, 20);
  if (quem !== 'anonimo' && !/^\d+$/.test(quem)) quem = '';

  const ord = String(bruto.ord ?? '');
  const dir = String(bruto.dir ?? '').toLowerCase();

  return {
    q: texto(bruto.q, BUSCA_MAX),
    acao: texto(bruto.acao, 64),
    quem,
    de,
    ate,
    ord: ORDENS[ord] !== undefined ? ord : 'quando',
    dir: dir === 'asc' ? 'asc' : 'desc',
    pag: Math.max(1, Number.parseInt(String(bruto.pag ?? '1'), 10) || 0),
    log: Math.max(0, Number.parseInt(String(bruto.log ?? '0'), 10) || 0),
  };
}

/** Verdadeiro quando algum filtro está em uso. */
export function temFiltro(f: Partial<FiltroAudit>): boolean {
  return (f.q ?? '') !== '' || (f.acao ?? '') !== '' || (f.quem ?? '') !== '' || (f.de ?? '') !== '' || (f.ate ?? '') !== '';
}

/** Uma página da trilha, já com o total do recorte. */
export async function pagina(f: FiltroAudit): Promise<PaginaAudit> {
  const [onde, par] = montarOnde(f);

  const bruto = await valor('SELECT COUNT(*) FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id WHERE ' + onde, par);
  if (bruto === null) {
    return { linhas: [], total: 0, pagina: 1, paginas: 1, por_pagina: POR_PAGINA, falha: true };
  }

  const total = Number(bruto) | 0;
  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  const pag = Math.min(Math.max(1, f.pag | 0), paginas);
  const offset = (pag - 1) * POR_PAGINA;

  const coluna = ORDENS[f.ord] ?? ORDENS.quando!;
  const dir = f.dir === 'asc' ? 'ASC' : 'DESC';

  // `a.id` desempata: sem ele, dois registros do mesmo segundo trocam de lugar entre páginas.
  const linhas = await linhasTodas(`${SELECT} WHERE ${onde} ORDER BY ${coluna} ${dir}, a.id ${dir} LIMIT ${POR_PAGINA} OFFSET ${offset}`, par);

  return { linhas: linhas.map(mapear), total, pagina: pag, paginas, por_pagina: POR_PAGINA, falha: false };
}

/** Um registro pelo id, no mesmo formato da lista. Null quando não existe. */
export async function porId(id: number): Promise<LinhaAudit | null> {
  if (id <= 0) return null;
  const l = await linhaUma(SELECT + ' WHERE a.id = :id LIMIT 1', { id });
  return l === null ? null : mapear(l);
}

/** Agregados DO RECORTE (não da tabela inteira). */
export async function resumo(
  f: FiltroAudit,
  limiteAcoes = 6,
): Promise<{ acoes: Array<{ acao: string; n: number }>; contas: number; primeiro: string; ultimo: string }> {
  const [onde, par] = montarOnde(f);
  const lim = Math.max(1, Math.min(20, Math.trunc(limiteAcoes)));

  const [acoes, extremos] = await Promise.all([
    linhasTodas(
      `SELECT a."action", COUNT(*) AS n
         FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id
        WHERE ${onde}
     GROUP BY a."action"
     ORDER BY n DESC, a."action" ASC
        LIMIT ${lim}`,
      par,
    ),
    linhaUma(
      `SELECT COUNT(DISTINCT a.admin_id) AS contas, MIN(a.created_at) AS primeiro, MAX(a.created_at) AS ultimo
         FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id
        WHERE ${onde}`,
      par,
    ),
  ]);

  return {
    acoes: acoes.map((l) => ({ acao: String(l.action), n: Number(l.n) | 0 })),
    // COUNT(DISTINCT) ignora NULL: tentativas anônimas não viram "conta" fantasma.
    contas: Number(extremos?.contas ?? 0) | 0,
    primeiro: String(extremos?.primeiro ?? ''),
    ultimo: String(extremos?.ultimo ?? ''),
  };
}

/** O que preencher nos dois <select> da barra: as ações e as contas que EXISTEM na trilha. */
export async function opcoes(): Promise<{
  acoes: Array<{ acao: string; n: number }>;
  contas: Array<{ id: number; nome: string; papel: string; n: number }>;
  anonimos: number;
  total: number;
}> {
  const [acoes, contas, anonimos, total] = await Promise.all([
    linhasTodas('SELECT "action", COUNT(*) AS n FROM admin_audit_log GROUP BY "action" ORDER BY "action" ASC'),
    linhasTodas(
      `SELECT a.admin_id, u.username, u.role, COUNT(*) AS n
         FROM admin_audit_log a
    LEFT JOIN users u ON u.id = a.admin_id
        WHERE a.admin_id IS NOT NULL
     GROUP BY a.admin_id, u.username, u.role
     ORDER BY COALESCE(u.username, CAST(a.admin_id AS TEXT)) ASC`,
    ),
    valor('SELECT COUNT(*) FROM admin_audit_log WHERE admin_id IS NULL'),
    valor('SELECT COUNT(*) FROM admin_audit_log'),
  ]);

  return {
    acoes: acoes.map((l) => ({ acao: String(l.action), n: Number(l.n) | 0 })),
    contas: contas.map((l) => ({
      id: Number(l.admin_id) | 0,
      // Conta apagada continua selecionável pelo id.
      nome: String(l.username ?? ''),
      papel: String(l.role ?? ''),
      n: Number(l.n) | 0,
    })),
    anonimos: Number(anonimos ?? 0) | 0,
    total: Number(total ?? 0) | 0,
  };
}

// ---------------------------------------------------------------------------- internos

/** Linha do banco -> linha da tela. A view não decodifica JSON e não interpreta NULL. */
function mapear(l: Dict): LinhaAudit {
  const bruto = l.meta ?? null;
  let meta: Dict | null = null;
  if (typeof bruto === 'string' && bruto !== '') {
    try {
      const d: unknown = JSON.parse(bruto);
      // is_array() do PHP: objeto ou lista.
      meta = d !== null && typeof d === 'object' ? (d as Dict) : null;
    } catch {
      meta = null;
    }
  }

  const adminId = l.admin_id !== null && l.admin_id !== undefined ? Number(l.admin_id) | 0 : 0;
  const nome = String(l.username ?? '');
  // Três estados, nunca dois: sem conta / conta que sumiu / conta que existe.
  const tipo = adminId <= 0 ? 'anonimo' : nome === '' ? 'removido' : 'conta';

  return {
    id: Number(l.id) | 0,
    acao: String(l.action),
    entidade: String(l.entity),
    alvo: String(l.entity_id ?? ''),
    resumo: String(l.summary ?? ''),
    ip: String(l.ip ?? ''),
    quando: String(l.created_at),
    quando_ts: paraUnix(l.created_at),
    meta,
    meta_bruto: typeof bruto === 'string' ? bruto : '',
    quem: {
      tipo,
      id: adminId,
      nome,
      papel: String(l.role ?? ''),
      situacao: String(l.status ?? ''),
    },
  };
}

/**
 * WHERE + parâmetros do recorte. '1' quando não há filtro, para as três consultas não divergirem.
 * ESCAPE '!' porque % e _ digitados pelo operador são texto procurado, não coringa.
 */
function montarOnde(f: Partial<FiltroAudit>): [string, DB.Params] {
  const cond: string[] = [];
  const par: DB.Params = {};

  if ((f.q ?? '') !== '') {
    const termo = '%' + escaparLike(String(f.q)) + '%';
    const partes = CAMPOS_BUSCA.map((campo, i) => {
      par['q' + i] = termo;
      return `${campo} LIKE :q${i} ESCAPE '!'`;
    });
    cond.push('(' + partes.join(' OR ') + ')');
  }

  if ((f.acao ?? '') !== '') {
    cond.push('a."action" = :acao');
    par.acao = String(f.acao);
  }

  if ((f.quem ?? '') === 'anonimo') {
    cond.push('a.admin_id IS NULL');
  } else if ((f.quem ?? '') !== '') {
    cond.push('a.admin_id = :quem');
    par.quem = Number.parseInt(String(f.quem), 10) || 0;
  }

  // A data é um DIA INTEIRO nas duas pontas.
  if ((f.de ?? '') !== '') {
    cond.push('a.created_at >= :de');
    par.de = f.de + ' 00:00:00';
  }
  if ((f.ate ?? '') !== '') {
    cond.push('a.created_at <= :ate');
    par.ate = f.ate + ' 23:59:59';
  }

  return [cond.length === 0 ? '1' : cond.join(' AND '), par];
}

/** 'YYYY-MM-DD' de verdade (31/02 não passa) ou string vazia. */
function data(v: string | null | undefined): string {
  const s = String(v ?? '').trim();
  if (s === '') return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
  if (!m) return '';
  const d = new Date(Date.UTC(+m[1]!, +m[2]! - 1, +m[3]!));
  return d.toISOString().slice(0, 10) === s ? s : '';
}

function escaparLike(termo: string): string {
  return termo.replace(/[!%_]/g, (c) => '!' + c);
}

// As embalagens devolvem null/[] em vez de deixar a exceção subir: a tela precisa DIZER que
// não conseguiu ler, e não devolver 500 nem uma lista vazia que se leia como "nada aconteceu".

async function linhasTodas(sql: string, p: DB.Params = {}): Promise<Dict[]> {
  try {
    return await DB.all<Dict>(sql, p);
  } catch (e) {
    console.error('[extracthunters] logs:', e);
    return [];
  }
}

async function linhaUma(sql: string, p: DB.Params = {}): Promise<Dict | null> {
  try {
    return await DB.one<Dict>(sql, p);
  } catch (e) {
    console.error('[extracthunters] logs:', e);
    return null;
  }
}

/** null = a consulta FALHOU (e não "deu zero"). */
async function valor(sql: string, p: DB.Params = {}): Promise<unknown> {
  try {
    return await DB.value(sql, p);
  } catch (e) {
    console.error('[extracthunters] logs:', e);
    return null;
  }
}
