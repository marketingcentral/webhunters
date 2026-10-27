/**
 * Parâmetros, servidores e livro-caixa — SÓ as funções que a API chama de
 * App\Repo\AdminSettingsRepo, ServersRepo, TransactionsRepo e App\Core\Maintenance.
 * O lote do painel pode portar o resto (filtros/ordenação de tela, séries do gráfico...) e
 * depois fazer estas cópias importarem de lá.
 *
 * Datas: as colunas DATETIME são lidas como UTC (o datetime('now') do SQLite), ao contrário do
 * PHP, que lia no fuso do servidor (ver "Datas" no README).
 */
import * as DB from '../core/database';
import * as Settings from '../repo/settings-repo';
import type { Obj } from './resposta';

const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;

/** strtotime() de uma coluna DATETIME (UTC). 0 quando ilegível. */
export function ts(s: unknown): number {
  if (s === null || s === undefined || s === '') return 0;
  const t = Date.parse(String(s).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(s)) ? '' : 'Z'));
  return Number.isFinite(t) ? Math.floor(t / 1000) : 0;
}

/** date('Y-m-d H:i:s', ts) em UTC. */
export function fmt(t: number): string {
  return new Date(t * 1000).toISOString().slice(0, 19).replace('T', ' ');
}

/** date('d/m H:i', ts) em UTC. */
function fmtCurto(t: number): string {
  const s = fmt(t);
  return `${s.slice(8, 10)}/${s.slice(5, 7)} ${s.slice(11, 16)}`;
}

// ================================================================== App\Core\Maintenance::estado

export const MANUT_PADRAO = 'MANUTENÇÃO PROGRAMADA. VOLTAMOS EM BREVE.';

/**
 * Estado do interruptor de manutenção, SEMPRE lido do banco. No PHP havia um carimbo de 30 s
 * na sessão; a API não tem sessão (e não pode gravar nela), então lê direto.
 */
export async function manutencao(): Promise<[boolean, string]> {
  try {
    const ligada = Boolean(await Settings.valor('maintenance_enabled', false));
    const msg = String(await Settings.valor('maintenance_message', '')).trim() || MANUT_PADRAO;
    return [ligada, msg];
  } catch (e) {
    console.error('[extracthunters] Maintenance:', e);
    return [false, MANUT_PADRAO];
  }
}

// ================================================================== AdminSettingsRepo

const TEXTO_MAX = 2000;
const JSON_MAX = 8000;

/** Todas as linhas, na ordem canônica (grupo, chave). */
export async function configuracoes(): Promise<Obj[]> {
  return DB.all<Obj>(
    `SELECT s."key", s."value", s.kind, s.label, s.grupo, s.updated_at, s.updated_by,
            u.username AS updated_por
       FROM admin_settings s
       LEFT JOIN users u ON u.id = s.updated_by
      ORDER BY s.grupo, s."key"`,
  );
}

export async function configuracao(chave: string): Promise<Obj | null> {
  return DB.one<Obj>(
    `SELECT s."key", s."value", s.kind, s.label, s.grupo, s.updated_at, s.updated_by,
            u.username AS updated_por
       FROM admin_settings s
       LEFT JOIN users u ON u.id = s.updated_by
      WHERE s."key" = :k`,
    { k: chave },
  );
}

/** Grava um valor JÁ VALIDADO. false quando nada mudou (não suja a auditoria). */
export async function gravarConfiguracao(chave: string, valor: string | null, adminId: number): Promise<boolean> {
  const atual = await DB.one<Obj>(`SELECT "value" FROM admin_settings WHERE "key" = :k`, { k: chave });
  if (atual === null) return false;
  if (String(atual.value ?? '') === String(valor ?? '')) return false;
  await DB.run(`UPDATE admin_settings SET "value" = :v, updated_by = :by, updated_at = datetime('now') WHERE "key" = :k`, {
    v: valor,
    by: adminId > 0 ? adminId : null,
    k: chave,
  });
  return true;
}

export interface Validado {
  ok: boolean;
  valor: string | null;
  erro: string;
}

/** AdminSettingsRepo::validar — as MESMAS regras da tela (app/Data/admin-config.php). */
export function validarConfiguracao(tipo: string, bruto: string, regra: Obj = {}): Validado {
  switch (tipo) {
    case 'bool':
      return { ok: true, valor: ['1', 'on', 'true', 'sim'].includes(bruto.trim().toLowerCase()) ? '1' : '0', erro: '' };
    case 'int':
      return validarInt(bruto, regra);
    case 'json':
      return validarJson(bruto);
    default:
      return validarTexto(bruto, regra);
  }
}

function validarInt(bruto: string, regra: Obj): Validado {
  const limpo = bruto.trim().replace(/[ . ]/g, '');
  if (limpo === '') return { ok: false, valor: null, erro: 'Informe um número.' };
  if (!/^-?\d+$/.test(limpo)) return { ok: false, valor: null, erro: 'Só números inteiros — sem vírgula nem unidade.' };
  const n = Number(limpo);
  if (!Number.isSafeInteger(n)) return { ok: false, valor: null, erro: 'Número grande demais para este campo.' };
  const min = regra.min !== undefined ? int(regra.min) : null;
  const max = regra.max !== undefined ? int(regra.max) : null;
  if (min !== null && n < min) return { ok: false, valor: null, erro: `Mínimo aceito: ${min}.` };
  if (max !== null && n > max) return { ok: false, valor: null, erro: `Máximo aceito: ${max}.` };
  return { ok: true, valor: String(n), erro: '' };
}

function validarTexto(bruto: string, regra: Obj): Validado {
  let txt = bruto.replace(/\r\n|\r/g, '\n');
  // eslint-disable-next-line no-control-regex
  txt = txt.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '').trim();
  const max = regra.max_len !== undefined ? Math.max(1, int(regra.max_len)) : TEXTO_MAX;
  const tam = [...txt].length;
  if (tam > max) return { ok: false, valor: null, erro: `Máximo de ${max} caracteres (enviou ${tam}).` };
  return { ok: true, valor: txt, erro: '' };
}

function validarJson(bruto: string): Validado {
  const txt = bruto.trim();
  if (txt === '') return { ok: true, valor: null, erro: '' };
  if (new TextEncoder().encode(txt).length > JSON_MAX) {
    return { ok: false, valor: null, erro: `Documento acima de ${JSON_MAX} bytes — isso é conteúdo, não configuração.` };
  }
  let dado: unknown;
  try {
    dado = JSON.parse(txt);
  } catch (e) {
    return { ok: false, valor: null, erro: `JSON inválido: ${(e as Error).message}.` };
  }
  if (dado === null || typeof dado !== 'object') {
    return { ok: false, valor: null, erro: 'A raiz precisa ser um objeto {…} ou uma lista […].' };
  }
  return { ok: true, valor: JSON.stringify(dado), erro: '' };
}

// ================================================================== ServersRepo

export const ESTADOS_SERVIDOR: Record<string, string> = { ok: 'Operacional', warn: 'Instável', down: 'Fora do ar' };
const JANELAS_POR_PAGINA = 8;

/** Janela mais relevante por rótulo de região: a ATIVA vence a próxima agendada. */
async function janelasPorRegiao(): Promise<Record<string, { ativa: boolean; quando: number; n: number }>> {
  const agora = Math.floor(Date.now() / 1000);
  const out: Record<string, { ativa: boolean; quando: number; n: number }> = {};
  const linhas = await DB.all<Obj>(
    `SELECT region, starts_at, ends_at FROM maintenance_windows
      WHERE ends_at >= datetime('now', '-1 day') ORDER BY starts_at ASC`,
  );
  for (const j of linhas) {
    const reg = String(j.region);
    const ini = ts(j.starts_at);
    const fim = ts(j.ends_at);
    const ativa = ini <= agora && agora <= fim;
    const n = (out[reg]?.n ?? 0) + 1;
    if (!out[reg] || (ativa && !out[reg]!.ativa)) out[reg] = { ativa, quando: ativa ? fim : ini, n };
    else out[reg]!.n = n;
  }
  return out;
}

/** Regiões com o estado EFETIVO (janela ativa = manutenção, mesmo com state 'ok'). Sem filtro/ordem de tela. */
export async function regioes(): Promise<Obj[]> {
  const linhas = await DB.all<Obj>('SELECT id, slug, label, ping_ms, players, state, sort_order FROM server_regions ORDER BY sort_order, id ASC');
  const janelas = await janelasPorRegiao();
  let pico = 0;
  for (const r of linhas) pico = Math.max(pico, int(r.players));

  return linhas.map((r) => {
    const label = String(r.label);
    const manut = janelas[label] ?? null;
    let efetivo: string, rotulo: string, nota: string | null;
    if (manut !== null && manut.ativa) [efetivo, rotulo, nota] = ['manutencao', 'Em manutenção', 'Janela ativa até ' + fmtCurto(manut.quando)];
    else if (String(r.state) === 'down') [efetivo, rotulo, nota] = ['fora', 'Fora do ar', null];
    else if (String(r.state) === 'warn') [efetivo, rotulo, nota] = ['instavel', 'Instável', null];
    else if (manut !== null) [efetivo, rotulo, nota] = ['agendada', 'Manut. agendada', 'Janela em ' + fmtCurto(manut.quando)];
    else [efetivo, rotulo, nota] = ['online', 'Online', null];
    return {
      id: int(r.id),
      slug: String(r.slug),
      label,
      ping: int(r.ping_ms),
      jogadores: int(r.players),
      state: String(r.state),
      ordem: int(r.sort_order),
      estado: efetivo,
      estado_rotulo: rotulo,
      nota,
      ocupacao: pico > 0 ? int(r.players) / pico : 0,
      janelas: manut?.n ?? 0,
    };
  });
}

/** Linha crua da região, ou null. */
export async function regiao(id: number): Promise<Obj | null> {
  return id > 0 ? DB.one<Obj>('SELECT * FROM server_regions WHERE id = :id LIMIT 1', { id }) : null;
}

/** Serviços (sem filtro/ordem de tela). */
export async function servicos(): Promise<Obj[]> {
  const agora = Math.floor(Date.now() / 1000);
  const linhas = await DB.all<Obj>(
    `SELECT id, slug, label, state, status_label, short_label, maint_scope,
            maint_starts_at, maint_minutes, sort_order
       FROM server_services ORDER BY sort_order, id ASC`,
  );
  return linhas.map((r) => {
    const inicio = r.maint_starts_at !== null ? ts(r.maint_starts_at) : 0;
    const min = int(r.maint_minutes);
    const state = String(r.state);
    return {
      id: int(r.id),
      slug: String(r.slug),
      label: String(r.label),
      state,
      estado: state === 'ok' ? 'online' : state === 'down' ? 'fora' : 'manutencao',
      rotulo: String(r.status_label ?? ''),
      curto: String(r.short_label ?? ''),
      escopo: String(r.maint_scope ?? '') || null,
      inicio,
      minutos: min > 0 ? min : null,
      ordem: int(r.sort_order),
      no_contador: state !== 'ok' && inicio > 0,
      faltam: inicio > 0 ? inicio - agora : null,
    };
  });
}

export async function servico(id: number): Promise<Obj | null> {
  return id > 0 ? DB.one<Obj>('SELECT * FROM server_services WHERE id = :id LIMIT 1', { id }) : null;
}

/** PRIMEIRA página de janelas (8, mais recentes primeiro) — o que /servers devolve. */
export async function janelas(): Promise<Obj[]> {
  const linhas = await DB.all<Obj>(
    `SELECT id, starts_at, ends_at, region, note, news_slug
       FROM maintenance_windows ORDER BY starts_at DESC LIMIT ${JANELAS_POR_PAGINA} OFFSET 0`,
  );
  const agora = Math.floor(Date.now() / 1000);
  const conhecidas = (await DB.all<Obj>('SELECT label FROM server_regions ORDER BY sort_order, id')).map((r) => String(r.label));
  return linhas.map((r) => {
    const ini = ts(r.starts_at);
    const fim = ts(r.ends_at);
    return {
      id: int(r.id),
      inicio: ini,
      fim,
      minutos: Math.round(Math.max(0, fim - ini) / 60),
      regiao: String(r.region),
      nota: String(r.note ?? '') || null,
      noticia: String(r.news_slug ?? '') || null,
      situacao: ini <= agora && agora <= fim ? 'ativa' : ini > agora ? 'agendada' : 'encerrada',
      orfa: !conhecidas.includes(String(r.region)),
    };
  });
}

/** Os números do topo de /admin/servidores (ServersRepo::resumo). */
export async function resumoServidores(): Promise<Obj> {
  const regs = await regioes();
  const srvs = await servicos();
  let regOnline = 0;
  let jogadores = 0;
  let somaPing = 0;
  for (const r of regs) {
    regOnline += r.estado === 'online' ? 1 : 0;
    jogadores += r.jogadores as number;
    somaPing += r.ping as number;
  }
  const srvOk = srvs.filter((s) => s.state === 'ok').length;
  const proxima = await DB.one<Obj>(
    `SELECT starts_at, ends_at, region FROM maintenance_windows
      WHERE ends_at >= datetime('now') ORDER BY starts_at LIMIT 1`,
  );
  return {
    regioes: regs.length,
    regioes_ok: regOnline,
    servicos: srvs.length,
    servicos_ok: srvOk,
    jogadores,
    ping_medio: regs.length === 0 ? 0 : Math.round(somaPing / regs.length),
    proxima:
      proxima === null
        ? null
        : {
            inicio: ts(proxima.starts_at),
            fim: ts(proxima.ends_at),
            regiao: String(proxima.region),
            ativa: ts(proxima.starts_at) <= Math.floor(Date.now() / 1000),
          },
    janelas_total: int(await DB.value('SELECT COUNT(*) FROM maintenance_windows')),
  };
}

/** Troca só o estado (ok|warn|down). A tabela sai de um switch, nunca do pedido. */
export async function trocarEstado(tipo: string, id: number, state: string): Promise<boolean> {
  const tabela = tipo === 'regiao' ? 'server_regions' : tipo === 'servico' ? 'server_services' : null;
  if (tabela === null || id <= 0 || !ESTADOS_SERVIDOR[state]) return false;
  await DB.run(`UPDATE ${tabela} SET state = :st WHERE id = :id`, { st: state, id });
  return true;
}

// ================================================================== TransactionsRepo

export const MOEDAS_LIVRO: Record<string, { label: string; curto: string; tom: string }> = {
  credits: { label: 'Créditos', curto: 'CR', tom: 'lime' },
  cash: { label: 'Dinheiro', curto: 'DIN', tom: 'cyan' },
  points: { label: 'Pontos', curto: 'PT', tom: 'amber' },
};

export interface FiltroLivro {
  de: string;
  ate: string;
  moeda: string;
  busca: string;
}

const JOIN_LIVRO = `FROM player_purchases pp
       LEFT JOIN users u  ON u.id  = pp.user_id
       LEFT JOIN items it ON it.id = pp.item_id`;

/** WHERE compartilhado: período como par de DATETIME (usa o índice), moeda e busca. */
function ondeLivro(f: FiltroLivro): { sql: string; p: Obj } {
  const cond = ['pp.bought_at >= :de', 'pp.bought_at <= :ate'];
  const p: Obj = { de: f.de + ' 00:00:00', ate: f.ate + ' 23:59:59' };
  if (f.moeda !== '') {
    cond.push('pp.currency = :moeda');
    p.moeda = f.moeda;
  }
  const busca = f.busca.trim();
  if (busca !== '') {
    // SQLite não tem escape padrão no LIKE (o MySQL usa `\`): ESCAPE explícito.
    const termo = '%' + busca.replace(/[\\%_]/g, (c) => '\\' + c) + '%';
    const partes = [`u.username LIKE :q1 ESCAPE '\\'`, `it.name LIKE :q2 ESCAPE '\\'`, `it.slug LIKE :q3 ESCAPE '\\'`];
    p.q1 = p.q2 = p.q3 = termo;
    if (/^\d+$/.test(busca)) {
      partes.push('pp.id = :qid');
      p.qid = parseInt(busca, 10);
    }
    cond.push('(' + partes.join(' OR ') + ')');
  }
  return { sql: 'WHERE ' + cond.join(' AND '), p };
}

/** Contagens do período — as únicas grandezas que podem somar moedas diferentes. */
export async function totaisLivro(f: FiltroLivro): Promise<{ transacoes: number; unidades: number; jogadores: number }> {
  const { sql, p } = ondeLivro(f);
  const l =
    (await DB.one<Obj>(
      `SELECT COUNT(*) AS transacoes, COALESCE(SUM(pp.quantity),0) AS unidades, COUNT(DISTINCT pp.user_id) AS jogadores
         ${JOIN_LIVRO} ${sql}`,
      p,
    )) ?? {};
  return { transacoes: int(l.transacoes), unidades: int(l.unidades), jogadores: int(l.jogadores) };
}

async function agregadoPorMoeda(f: FiltroLivro): Promise<Record<string, { transacoes: number; unidades: number; jogadores: number; total: number }>> {
  const { sql, p } = ondeLivro(f);
  const linhas = await DB.all<Obj>(
    `SELECT pp.currency, COUNT(*) AS transacoes, COALESCE(SUM(pp.quantity),0) AS unidades,
            COUNT(DISTINCT pp.user_id) AS jogadores, SUM(pp.price * pp.quantity) AS total
       ${JOIN_LIVRO} ${sql}
      GROUP BY pp.currency`,
    p,
  );
  const out: Record<string, { transacoes: number; unidades: number; jogadores: number; total: number }> = {};
  for (const l of linhas) {
    out[String(l.currency)] = { transacoes: int(l.transacoes), unidades: int(l.unidades), jogadores: int(l.jogadores), total: int(l.total) };
  }
  return out;
}

/** A janela imediatamente anterior, de mesma duração (base do `delta`). */
function janelaAnterior(f: FiltroLivro): FiltroLivro {
  const ini = ts(f.de + ' 00:00:00');
  const fim = ts(f.ate + ' 00:00:00');
  const dur = Math.max(1, Math.floor((fim - ini) / 86400) + 1);
  return { de: fmt(ini - dur * 86400).slice(0, 10), ate: fmt(ini - 86400).slice(0, 10), moeda: f.moeda, busca: f.busca };
}

/** Total, ticket médio e variação POR MOEDA, na ordem da loja. */
export async function porMoeda(f: FiltroLivro): Promise<Obj[]> {
  const atual = await agregadoPorMoeda(f);
  const anterior = await agregadoPorMoeda(janelaAnterior(f));
  const ordem = Object.keys(MOEDAS_LIVRO);
  const saida = Object.entries(atual).map(([moeda, a]) => {
    const meta = MOEDAS_LIVRO[moeda] ?? { label: moeda, curto: moeda.toUpperCase(), tom: 'muted' };
    const antes = anterior[moeda]?.total ?? 0;
    return {
      moeda,
      label: meta.label,
      curto: meta.curto,
      tom: meta.tom,
      transacoes: a.transacoes,
      unidades: a.unidades,
      jogadores: a.jogadores,
      total: a.total,
      ticket: a.transacoes > 0 ? a.total / a.transacoes : 0,
      delta: antes > 0 ? (a.total - antes) / antes : null,
    };
  });
  const pos = (m: string) => (ordem.indexOf(m) < 0 ? 99 : ordem.indexOf(m));
  return saida.sort((x, y) => pos(x.moeda) - pos(y.moeda));
}

/** Uma página da lista, mais recentes primeiro (a API sempre pede data/desc). */
export async function listaLivro(f: FiltroLivro, limite: number, offset: number): Promise<Obj[]> {
  const { sql, p } = ondeLivro(f);
  limite = Math.max(1, Math.min(500, limite));
  offset = Math.max(0, offset);
  return DB.all<Obj>(
    `SELECT pp.id, pp.quantity, pp.currency, pp.price, pp.bought_at,
            pp.price * pp.quantity AS total,
            pp.user_id, u.username, u.status AS user_status, u.role AS user_role,
            pp.item_id, it.name AS item_name, it.slug AS item_slug,
            it.category AS item_category, it.rarity AS item_rarity
       ${JOIN_LIVRO} ${sql}
      ORDER BY pp.bought_at DESC, pp.id DESC
      LIMIT ${limite} OFFSET ${offset}`,
    p,
  );
}

export async function contarLivro(f: FiltroLivro): Promise<number> {
  const { sql, p } = ondeLivro(f);
  return int(await DB.value(`SELECT COUNT(*) ${JOIN_LIVRO} ${sql}`, p));
}

/** Itens mais vendidos do período, por unidades (uma linha por item e moeda). */
export async function topItens(f: FiltroLivro, n = 5): Promise<Obj[]> {
  const { sql, p } = ondeLivro(f);
  n = Math.max(1, Math.min(50, n));
  const linhas = await DB.all<Obj>(
    `SELECT pp.item_id, it.name AS nome, pp.currency,
            SUM(pp.quantity) AS unidades, COUNT(*) AS transacoes, SUM(pp.price * pp.quantity) AS total
       ${JOIN_LIVRO} ${sql}
      GROUP BY pp.item_id, it.name, pp.currency
      ORDER BY unidades DESC, total DESC
      LIMIT ${n}`,
    p,
  );
  return linhas.map((l) => ({
    item_id: int(l.item_id),
    nome: String(l.nome ?? ''),
    moeda: String(l.currency),
    unidades: int(l.unidades),
    transacoes: int(l.transacoes),
    total: int(l.total),
  }));
}

/** Quem mais gastou no período, por moeda. */
export async function topJogadores(f: FiltroLivro, n = 5): Promise<Obj[]> {
  const { sql, p } = ondeLivro(f);
  n = Math.max(1, Math.min(50, n));
  const linhas = await DB.all<Obj>(
    `SELECT pp.user_id, u.username AS nome, pp.currency,
            COUNT(*) AS transacoes, SUM(pp.price * pp.quantity) AS total
       ${JOIN_LIVRO} ${sql}
      GROUP BY pp.user_id, u.username, pp.currency
      ORDER BY total DESC
      LIMIT ${n}`,
    p,
  );
  return linhas.map((l) => ({
    user_id: int(l.user_id),
    nome: String(l.nome ?? ''),
    moeda: String(l.currency),
    transacoes: int(l.transacoes),
    total: int(l.total),
  }));
}
