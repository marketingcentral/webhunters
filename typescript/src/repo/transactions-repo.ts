/**
 * Livro-caixa da loja do jogo: `player_purchases` × `users` × `items` — o App\Repo\TransactionsRepo do PHP.
 *
 * TODO o SQL da tela /admin/transacoes mora aqui. O controller só traduz a URL em filtro e a
 * view só desenha.
 *
 * REGRA DO ARQUIVO INTEIRO: `currency` é moeda DE JOGO (credits, cash, points) — três
 * unidades incomparáveis. Nenhum SUM(price*quantity) sem GROUP BY currency. O que soma a tela
 * inteira são CONTAGENS (transações, unidades, jogadores distintos).
 *
 * JOINs são LEFT: um livro-caixa que ESCONDE uma compra porque o item saiu do catálogo é pior
 * do que um que a mostra sem nome.
 *
 * Datas: o período é tratado em UTC, como as colunas do D1 (`datetime('now')`) e o resto do
 * painel (admin-repo: paraUnix/formatarData). O PHP usava o fuso do servidor (São Paulo).
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import { formatarData, paraUnix } from './admin-repo';

/** As três moedas do jogo, na ordem da loja. `tom` é a cor do design system. */
export const MOEDAS: Record<string, { label: string; curto: string; tom: string }> = {
  credits: { label: 'Créditos', curto: 'CR', tom: 'lime' },
  cash: { label: 'Dinheiro', curto: 'DIN', tom: 'cyan' },
  points: { label: 'Pontos', curto: 'PT', tom: 'amber' },
};

/** LISTA BRANCA de ordenação (`ord` vem da URL e é concatenado no ORDER BY). */
const ORDENS: Record<string, string> = {
  data: 'pp.bought_at',
  valor: 'pp.price * pp.quantity',
  jogador: 'u.username',
  item: 'it.name',
  id: 'pp.id',
};

/** Acima desta quantidade de dias o gráfico agrupa por semana. */
const DIAS_POR_DIA = 92;

const DE_ONDE = `FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id`;

export interface Filtro {
  de: string;
  ate: string;
  moeda: string;
  busca: string;
}

// ============================================================================ leituras

/** Contagens do período — as únicas grandezas que podem somar moedas diferentes. */
export async function totais(f: Filtro): Promise<{ transacoes: number; unidades: number; jogadores: number }> {
  const { sql, p } = onde(f);
  const l =
    (await DB.one<Dict>(
      `SELECT COUNT(*) AS transacoes, COALESCE(SUM(pp.quantity),0) AS unidades, COUNT(DISTINCT pp.user_id) AS jogadores
         ${DE_ONDE}
        ${sql}`,
      p,
    )) ?? {};
  return {
    transacoes: Number(l.transacoes ?? 0) | 0,
    unidades: Number(l.unidades ?? 0) | 0,
    jogadores: Number(l.jogadores ?? 0) | 0,
  };
}

/**
 * Total, ticket médio e variação POR MOEDA (uma linha por moeda com movimento). `delta`
 * compara com o período anterior de mesma duração; null quando não há base.
 */
export async function porMoeda(f: Filtro): Promise<Dict[]> {
  const [atual, anterior] = await Promise.all([agregadoPorMoeda(f), agregadoPorMoeda(janelaAnterior(f))]);

  const saida: Dict[] = [];
  for (const [moeda, a] of Object.entries(atual)) {
    const meta = MOEDAS[moeda] ?? { label: moeda, curto: moeda.toUpperCase(), tom: 'muted' };
    const antes = anterior[moeda]?.total ?? 0;
    saida.push({
      moeda,
      label: meta.label,
      curto: meta.curto,
      tom: meta.tom,
      transacoes: a.transacoes,
      unidades: a.unidades,
      jogadores: a.jogadores,
      total: a.total,
      ticket: a.transacoes > 0 ? a.total / a.transacoes : 0.0,
      delta: antes > 0 ? (a.total - antes) / antes : null,
    });
  }

  // A ordem é a da loja (créditos, dinheiro, pontos), não a que o banco devolveu.
  const ordem = Object.keys(MOEDAS);
  const idx = (m: string) => {
    const i = ordem.indexOf(m);
    return i < 0 ? 99 : i;
  };
  saida.sort((x, y) => idx(String(x.moeda)) - idx(String(y.moeda)));
  return saida;
}

/** Ano-semana ISO ('o-W' do PHP) de um instante unix, em UTC. */
function semanaIso(ts: number): string {
  const d = new Date(ts * 1000);
  const dia = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()));
  const dow = dia.getUTCDay() || 7; // segunda = 1 ... domingo = 7
  dia.setUTCDate(dia.getUTCDate() + 4 - dow); // quinta-feira da mesma semana
  const ano = dia.getUTCFullYear();
  const semana = Math.ceil(((dia.getTime() - Date.UTC(ano, 0, 1)) / 86400000 + 1) / 7);
  return ano + '-' + String(semana).padStart(2, '0');
}

/**
 * Série do gráfico: um balde por dia (ou por semana, em período longo), por moeda. O eixo
 * completo — inclusive os dias de valor zero — é montado aqui.
 */
export async function serie(f: Filtro): Promise<{ granularidade: string; moedas: Dict[] }> {
  const { sql, p } = onde(f);
  const linhas = await DB.all<Dict>(
    `SELECT date(pp.bought_at) AS dia, pp.currency,
            SUM(pp.price * pp.quantity) AS total, COUNT(*) AS n
       ${DE_ONDE}
      ${sql}
      GROUP BY dia, pp.currency
      ORDER BY dia ASC`,
    p,
  );

  const ini = paraUnix(f.de);
  const fim = paraUnix(f.ate);
  const dias = Math.floor((fim - ini) / 86400) + 1;
  const semanal = dias > DIAS_POR_DIA;
  const chaveDe = (t: number) => (semanal ? semanaIso(t) : formatarData(t, 'Y-m-d'));

  // Eixo: a lista fechada de baldes do período, sem buracos.
  const baldes = new Map<string, { rotulo: string; titulo: string }>();
  for (let t = ini; t <= fim; t += 86400) {
    const chave = chaveDe(t);
    if (!baldes.has(chave)) {
      baldes.set(chave, {
        rotulo: formatarData(t, 'd/m'),
        titulo: semanal ? 'Semana de ' + formatarData(t, 'd/m/Y') : formatarData(t, 'd/m/Y'),
      });
    }
  }

  const porMoedaMap: Record<string, Record<string, { valor: number; n: number }>> = {};
  for (const l of linhas) {
    const chave = chaveDe(paraUnix(l.dia));
    const m = String(l.currency);
    const alvo = ((porMoedaMap[m] ??= {})[chave] ??= { valor: 0, n: 0 });
    alvo.valor += Math.trunc(Number(l.total));
    alvo.n += Number(l.n) | 0;
  }

  const montar = (valores: Record<string, { valor: number; n: number }>) => {
    const pontos: Dict[] = [];
    let max = 0;
    let total = 0;
    for (const [chave, b] of baldes) {
      const v = valores[chave]?.valor ?? 0;
      const n = valores[chave]?.n ?? 0;
      max = Math.max(max, v);
      total += v;
      pontos.push({ rotulo: b.rotulo, titulo: b.titulo, valor: v, n });
    }
    return { pontos, max, total };
  };

  const saida: Dict[] = [];
  for (const moeda of Object.keys(MOEDAS)) {
    if (!porMoedaMap[moeda]) continue;
    const meta = MOEDAS[moeda]!;
    const { pontos, max, total } = montar(porMoedaMap[moeda]!);
    saida.push({ moeda, label: meta.label, curto: meta.curto, tom: meta.tom, max, total, pontos });
  }
  // Moeda fora do catálogo (dado antigo, importação): não pode sumir do gráfico.
  for (const [moeda, valores] of Object.entries(porMoedaMap)) {
    if (MOEDAS[moeda]) continue;
    const { pontos, max, total } = montar(valores);
    saida.push({ moeda, label: moeda, curto: moeda.toUpperCase(), tom: 'muted', max, total, pontos });
  }

  return { granularidade: semanal ? 'semana' : 'dia', moedas: saida };
}

/** Uma página da lista, já com o nome do jogador e o nome do item resolvidos. */
export async function lista(f: Filtro, ord: string, dir: string, limite: number, offset: number): Promise<Dict[]> {
  const { sql, p } = onde(f);
  const coluna = ORDENS[ord] ?? ORDENS.data!;
  const d = dir.toLowerCase() === 'asc' ? 'ASC' : 'DESC';
  // O id desempata: sem ele, duas compras no mesmo segundo trocam de lugar entre páginas.
  const ordem = `${coluna} ${d}, pp.id ${d}`;
  // Teto de 500 como no PHP (o CSV pede 5000, mas recebe no máximo 500 — igual lá).
  limite = Math.max(1, Math.min(500, Math.trunc(limite)));
  offset = Math.max(0, Math.trunc(offset));

  return DB.all<Dict>(
    `SELECT pp.id, pp.quantity, pp.currency, pp.price, pp.bought_at,
            pp.price * pp.quantity AS total,
            pp.user_id, u.username, u.status AS user_status, u.role AS user_role,
            pp.item_id, it.name AS item_name, it.slug AS item_slug,
            it.category AS item_category, it.rarity AS item_rarity
       ${DE_ONDE}
      ${sql}
      ORDER BY ${ordem}
      LIMIT ${limite} OFFSET ${offset}`,
    p,
  );
}

export async function contar(f: Filtro): Promise<number> {
  const { sql, p } = onde(f);
  return Number(await DB.value(`SELECT COUNT(*) ${DE_ONDE} ${sql}`, p)) | 0;
}

/** Itens mais vendidos do período, por unidades (um item em duas moedas aparece duas vezes). */
export async function topItens(f: Filtro, n = 5): Promise<Dict[]> {
  const { sql, p } = onde(f);
  n = Math.max(1, Math.min(50, Math.trunc(n)));
  const linhas = await DB.all<Dict>(
    `SELECT pp.item_id, it.name AS nome, pp.currency,
            SUM(pp.quantity) AS unidades, COUNT(*) AS transacoes,
            SUM(pp.price * pp.quantity) AS total
       ${DE_ONDE}
      ${sql}
      GROUP BY pp.item_id, it.name, pp.currency
      ORDER BY unidades DESC, total DESC
      LIMIT ${n}`,
    p,
  );
  return linhas.map((l) => ({
    item_id: Number(l.item_id) | 0,
    nome: String(l.nome ?? ''),
    moeda: String(l.currency),
    unidades: Number(l.unidades) | 0,
    transacoes: Number(l.transacoes) | 0,
    total: Math.trunc(Number(l.total)),
  }));
}

/** Quem mais gastou no período, por moeda. */
export async function topJogadores(f: Filtro, n = 5): Promise<Dict[]> {
  const { sql, p } = onde(f);
  n = Math.max(1, Math.min(50, Math.trunc(n)));
  const linhas = await DB.all<Dict>(
    `SELECT pp.user_id, u.username AS nome, pp.currency,
            COUNT(*) AS transacoes, SUM(pp.price * pp.quantity) AS total
       ${DE_ONDE}
      ${sql}
      GROUP BY pp.user_id, u.username, pp.currency
      ORDER BY total DESC
      LIMIT ${n}`,
    p,
  );
  return linhas.map((l) => ({
    user_id: Number(l.user_id) | 0,
    nome: String(l.nome ?? ''),
    moeda: String(l.currency),
    transacoes: Number(l.transacoes) | 0,
    total: Math.trunc(Number(l.total)),
  }));
}

/** O livro-caixa inteiro, sem filtro: quantas linhas e entre que datas. */
export async function extremos(): Promise<{ n: number; primeira: string | null; ultima: string | null }> {
  const l = (await DB.one<Dict>('SELECT COUNT(*) AS n, MIN(bought_at) AS primeira, MAX(bought_at) AS ultima FROM player_purchases')) ?? {};
  return {
    n: Number(l.n ?? 0) | 0,
    primeira: l.primeira !== null && l.primeira !== undefined ? String(l.primeira) : null,
    ultima: l.ultima !== null && l.ultima !== undefined ? String(l.ultima) : null,
  };
}

/** Moedas que REALMENTE aparecem no livro-caixa (o seletor só oferece o que existe). */
export async function moedasUsadas(): Promise<string[]> {
  const linhas = await DB.all<Dict>('SELECT DISTINCT currency FROM player_purchases ORDER BY currency');
  return linhas.map((l) => String(l.currency));
}

// =============================================================================== apoio

export function rotuloMoeda(moeda: string): string {
  return MOEDAS[moeda]?.label ?? moeda;
}

export function siglaMoeda(moeda: string): string {
  return MOEDAS[moeda]?.curto ?? moeda.toUpperCase();
}

export function tomMoeda(moeda: string): string {
  return MOEDAS[moeda]?.tom ?? 'muted';
}

export function ordensValidas(): string[] {
  return Object.keys(ORDENS);
}

interface Agregado {
  transacoes: number;
  unidades: number;
  jogadores: number;
  total: number;
}

/** Agregado bruto por moeda de UMA janela (a pedida e a anterior). */
async function agregadoPorMoeda(f: Filtro): Promise<Record<string, Agregado>> {
  const { sql, p } = onde(f);
  const linhas = await DB.all<Dict>(
    `SELECT pp.currency,
            COUNT(*) AS transacoes,
            COALESCE(SUM(pp.quantity),0) AS unidades,
            COUNT(DISTINCT pp.user_id) AS jogadores,
            SUM(pp.price * pp.quantity) AS total
       ${DE_ONDE}
      ${sql}
      GROUP BY pp.currency`,
    p,
  );
  const saida: Record<string, Agregado> = {};
  for (const l of linhas) {
    saida[String(l.currency)] = {
      transacoes: Number(l.transacoes) | 0,
      unidades: Number(l.unidades) | 0,
      jogadores: Number(l.jogadores) | 0,
      total: Math.trunc(Number(l.total)),
    };
  }
  return saida;
}

/** A janela imediatamente anterior, de mesma duração — a base do `delta`. */
function janelaAnterior(f: Filtro): Filtro {
  const ini = paraUnix(f.de);
  const fim = paraUnix(f.ate);
  const dur = Math.max(1, Math.floor((fim - ini) / 86400) + 1);
  return {
    de: formatarData(ini - dur * 86400, 'Y-m-d'),
    ate: formatarData(ini - 86400, 'Y-m-d'),
    moeda: f.moeda,
    busca: f.busca,
  };
}

/**
 * O WHERE compartilhado por todas as consultas, com os valores VINCULADOS. O período entra
 * como par de DATETIME (00:00:00 e 23:59:59) para não aplicar função na coluna.
 */
function onde(f: Filtro): { sql: string; p: DB.Params } {
  const cond = ['pp.bought_at >= :de', 'pp.bought_at <= :ate'];
  const p: DB.Params = { de: f.de + ' 00:00:00', ate: f.ate + ' 23:59:59' };

  if ((f.moeda ?? '') !== '') {
    cond.push('pp.currency = :moeda');
    p.moeda = f.moeda;
  }

  const busca = String(f.busca ?? '').trim();
  if (busca !== '') {
    // Curingas escapados ("100%" é "100%"). No SQLite o ESCAPE precisa ser explícito.
    const termo = '%' + busca.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
    const partes = [`u.username LIKE :q1 ESCAPE '\\'`, `it.name LIKE :q2 ESCAPE '\\'`, `it.slug LIKE :q3 ESCAPE '\\'`];
    p.q1 = termo;
    p.q2 = termo;
    p.q3 = termo;
    // Busca numérica também casa o número da transação.
    if (/^\d+$/.test(busca)) {
      partes.push('pp.id = :qid');
      p.qid = Number(busca);
    }
    cond.push('(' + partes.join(' OR ') + ')');
  }

  return { sql: 'WHERE ' + cond.join(' AND '), p };
}
