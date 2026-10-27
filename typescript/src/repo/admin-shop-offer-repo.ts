/**
 * A vitrine da loja pelo lado de dentro — o App\Repo\ShopOfferRepo do PHP, inteiro:
 * `shop_offers` sempre conciliada com `items`.
 *
 * Uma oferta SOZINHA não é vendável — ela é um preço pendurado num item_id. Por isso não
 * existe aqui uma função que devolva shop_offers pura: toda leitura já vem com o JOIN em items
 * (nome, raridade, arte, preço de catálogo). Uma linha órfã não aparece na loja do jogo
 * (CatalogRepo.shopOffers também usa JOIN) e também não pode aparecer no painel fingindo
 * estar à venda.
 *
 * A REGRA DO PREÇO. O banco guarda `price` e `discount` separados e NÃO guarda o preço final:
 * quem calcula é o jogo, na hora da compra (ShopController::offerToCard). precoFinal() repete
 * exatamente aquela conta — é a única forma de o número da tabela do painel ser o mesmo que o
 * jogador vê no botão COMPRAR.
 *
 * Preço aqui é MOEDA DE JOGO (créditos, dinheiro, pontos) — nunca real.
 *
 * Datas: shop_offers não tem janela de início/fim; a única data lida é
 * player_purchases.bought_at (comprasDoItem), convertida com paraUnix() (UTC, ver README).
 * A tela só mostra a contagem, não a data.
 *
 * DIFERENÇAS PARA O MySQL (D1/SQLite):
 *   - FIELD() não existe: a ordem editorial das abas vira `CASE o.category WHEN … END`
 *     (o que não está na lista cai em 9999, como o CASE do PHP fazia com o 0 do FIELD).
 *   - `/` entre inteiros é divisão INTEIRA no SQLite: o preço final da ordenação usa `/ 100.0`
 *     para o ROUND arredondar como o MySQL (que dividia em DECIMAL).
 *   - LIKE: `ESCAPE '\'` explícito (no SQLite não há escape padrão) e o LIKE ignora caixa só
 *     em ASCII (a collation do MySQL ignorava caixa E acento).
 *   - ORDER BY i.name: COLLATE NOCASE para chegar perto do utf8mb4_unicode_ci.
 *   - Database::transaction() de trocarOrdem() vira um batch() só.
 */
import { all, batch, insert, one, prepare, run, value } from '../core/database';
import type { Dict } from '../core/view';
import { paraUnix } from './admin-repo';

/** Moedas aceitas por `shop_offers.currency` — é a carteira que o jogador tem no perfil. */
export const MOEDAS = ['credits', 'cash', 'points'];

/** Teto do desconto. 100% sairia de graça; acima de 90 o preço final vira ruído. */
export const DESCONTO_MAX = 90;

/** `tag` era varchar(16) no MySQL: o formulário recusa antes (no D1 é TEXT, sem corte). */
export const TAG_MAX = 16;

/** `sort_order` era smallint unsigned. */
export const ORDEM_MAX = 65535;

/** Teto prático do preço: um preço com dez dígitos é sempre erro de digitação. */
export const PRECO_MAX = 99999999;

/** Quantos nichos a vitrine do jogo desenha por aba. */
export const NICHOS_VITRINE = 12;

/** Linhas por página da listagem — o mesmo 12 da vitrine, para a leitura casar. */
export const POR_PAGINA = 12;

/**
 * Ordenações aceitas pela listagem (lista branca: o trecho vai concatenado no SQL).
 * O desempate final é sempre `o.id`. `#ABA#` vira a ordem EDITORIAL das abas.
 */
const ORDENS: Record<string, string> = {
  // A lista de abas fica sempre na ordem da loja: inverter a ORDEM inverte a posição
  // dentro da aba, não a fila das abas.
  ordem: '#ABA# ASC, o.sort_order %s',
  item: 'i.name COLLATE NOCASE %s',
  categoria: '#ABA# %s, o.sort_order ASC',
  preco: 'preco_final %s',
  desconto: 'COALESCE(o.discount, 0) %s',
  destaque: 'o.featured %s, #ABA# ASC, o.sort_order ASC',
};

/** Estados do filtro rápido, como cláusula WHERE. Mesma razão de lista branca. */
const ESTADOS: Record<string, string> = {
  destaque: 'o.featured = 1',
  comum: 'o.featured = 0',
  promo: 'COALESCE(o.discount, 0) > 0',
  cheio: 'COALESCE(o.discount, 0) = 0',
};

/** Colunas comuns de toda leitura de oferta (o JOIN com o item). */
const COLUNAS_OFERTA = `o.id, o.item_id, o.category, o.price, o.currency, o.discount, o.tag,
                    o.featured, o.sort_order,
                    i.name AS item_nome, i.slug AS item_slug, i.rarity AS item_raridade,
                    i.image AS item_imagem, i.category AS item_categoria,
                    i.base_price AS item_preco_base, i.currency AS item_moeda_base`;

export interface Lista {
  linhas: Dict[];
  total: number;
  pagina: number;
  paginas: number;
  por_pagina: number;
}

const int = (v: unknown): number => {
  const n = Number(v);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
};

/**
 * O preço que o jogador realmente paga. Cópia fiel de ShopController::offerToCard().
 * Math.round arredonda meio para cima, igual ao round() do PHP para valores positivos.
 */
export function precoFinal(preco: number, desconto: number | null): number {
  const d = int(desconto);
  if (d <= 0) return preco;
  return Math.round((preco * (100 - Math.min(100, d))) / 100);
}

/** Uma página da listagem, já com o item de cada oferta. */
export async function listar(f: Dict): Promise<Lista> {
  const [where, params] = filtro(f);

  const ordKey = String(f.ord ?? '');
  const ord = Object.prototype.hasOwnProperty.call(ORDENS, ordKey) ? ordKey : 'ordem';
  const dir = String(f.dir ?? 'asc').toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
  const abas = Array.isArray(f.abas) ? (f.abas as unknown[]).map(String) : [];
  const orderBy = ORDENS[ord]!.replace(/%s/g, dir).replace(/#ABA#/g, ordemDasAbas(abas)) + ', o.id ASC';

  const total = int(await valor('SELECT COUNT(*) FROM shop_offers o JOIN items i ON i.id = o.item_id WHERE ' + where, params));

  const porPagina = POR_PAGINA;
  const paginas = Math.max(1, Math.ceil(total / porPagina));
  // Página fora do intervalo volta para a última que existe.
  const pagina = Math.max(1, Math.min(paginas, int(f.pag ?? 1)));
  const offset = (pagina - 1) * porPagina;

  const linhasCruas = await linhas(
    `SELECT ${COLUNAS_OFERTA},
                    ROUND(o.price * (100 - COALESCE(o.discount, 0)) / 100.0) AS preco_final
               FROM shop_offers o
               JOIN items i ON i.id = o.item_id
              WHERE ${where}
              ORDER BY ${orderBy}
              LIMIT ${porPagina} OFFSET ${offset}`,
    params,
  );

  return {
    linhas: linhasCruas.map(hidratar),
    total,
    pagina,
    paginas,
    por_pagina: porPagina,
  };
}

/** Uma oferta com o item embutido — o que o formulário e a confirmação leem. */
export async function porId(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  const l = await linha(
    `SELECT ${COLUNAS_OFERTA}
               FROM shop_offers o
               JOIN items i ON i.id = o.item_id
              WHERE o.id = :id LIMIT 1`,
    { id },
  );
  return l === null ? null : hidratar(l);
}

/** Catálogo inteiro para o seletor do formulário, com quantas ofertas cada item já tem. */
export async function itensDoCatalogo(): Promise<Dict[]> {
  const ls = await linhas(
    `SELECT i.id, i.name, i.slug, i.category, i.rarity, i.base_price, i.currency,
                    COUNT(o.id) AS ofertas
               FROM items i
          LEFT JOIN shop_offers o ON o.item_id = i.id
           GROUP BY i.id
           ORDER BY i.category ASC, i.name COLLATE NOCASE ASC`,
  );
  return ls.map((l) => ({
    id: int(l.id),
    nome: String(l.name ?? ''),
    slug: String(l.slug ?? ''),
    categoria: String(l.category ?? ''),
    raridade: String(l.rarity ?? ''),
    preco_base: int(l.base_price),
    moeda_base: String(l.currency ?? ''),
    ofertas: int(l.ofertas),
  }));
}

/** Os números da faixa de indicadores. */
export async function resumo(): Promise<Dict> {
  const [l, moedas, itens, comOferta] = await Promise.all([
    linha(
      `SELECT COUNT(*) AS ofertas,
                    COALESCE(SUM(o.featured = 1), 0) AS destaque,
                    COALESCE(SUM(COALESCE(o.discount, 0) > 0), 0) AS promo,
                    COALESCE(AVG(NULLIF(o.discount, 0)), 0) AS desconto_medio
               FROM shop_offers o JOIN items i ON i.id = o.item_id`,
    ),
    linhas(
      `SELECT o.currency, COUNT(*) AS n
               FROM shop_offers o JOIN items i ON i.id = o.item_id
           GROUP BY o.currency`,
    ),
    valor('SELECT COUNT(*) FROM items'),
    valor('SELECT COUNT(DISTINCT item_id) FROM shop_offers'),
  ]);

  const porMoeda: Record<string, number> = {};
  for (const m of moedas) porMoeda[String(m.currency)] = int(m.n);

  const r = l ?? {};
  const nItens = int(itens);
  return {
    ofertas: int(r.ofertas),
    destaque: int(r.destaque),
    promo: int(r.promo),
    desconto_medio: Math.round(Number(r.desconto_medio ?? 0) || 0),
    itens: nItens,
    itens_sem_oferta: Math.max(0, nItens - int(comOferta)),
    por_moeda: porMoeda,
  };
}

/** Quantas ofertas cada aba tem hoje (contador, aviso de aba cheia, recusa de excluir). */
export async function contagemPorCategoria(): Promise<Record<string, number>> {
  const saida: Record<string, number> = {};
  for (const l of await linhas(
    `SELECT o.category, COUNT(*) AS n
               FROM shop_offers o JOIN items i ON i.id = o.item_id
           GROUP BY o.category`,
  )) {
    saida[String(l.category)] = int(l.n);
  }
  return saida;
}

/** A mesma dupla item+categoria já está publicada? (ignorando a própria oferta na edição) */
export async function categoriaDoDuplicado(itemId: number, categoria: string, ignorarId = 0): Promise<boolean> {
  return int(await valor(
    `SELECT COUNT(*) FROM shop_offers
              WHERE item_id = :i AND category = :c AND id <> :ig`,
    { i: itemId, c: categoria, ig: ignorarId },
  )) > 0;
}

/** Item do catálogo, para validar o item_id que chegou. */
export async function item(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  return linha(
    `SELECT id, name, slug, category, rarity, image, base_price, currency
               FROM items WHERE id = :id LIMIT 1`,
    { id },
  );
}

/** Histórico de compra do ITEM (player_purchases guarda item_id, não offer_id). */
export async function comprasDoItem(itemId: number): Promise<{ n: number; ultima: number }> {
  const l = (await linha(
    'SELECT COUNT(*) AS n, MAX(bought_at) AS ultima FROM player_purchases WHERE item_id = :i',
    { i: itemId },
  )) ?? {};
  const ultima = String(l.ultima ?? '');
  return {
    n: int(l.n),
    ultima: ultima !== '' ? paraUnix(ultima) : 0,
  };
}

/** Próxima posição livre no fim de uma aba — o padrão de quem cria uma oferta nova. */
export async function proximaOrdem(categoria: string): Promise<number> {
  const max = await valor('SELECT MAX(sort_order) FROM shop_offers WHERE category = :c', { c: categoria });
  return max === null ? 0 : Math.min(ORDEM_MAX, int(max) + 1);
}

/** A oferta imediatamente acima (ou abaixo) na MESMA aba, pela ordem que a loja usa. */
export async function vizinha(oferta: Dict, direcao: string): Promise<Dict | null> {
  const acima = direcao === 'cima';
  // O par (sort_order, id) é o que ordena a vitrine.
  return linha(
    `SELECT id, sort_order FROM shop_offers
              WHERE category = :c
                AND (sort_order ${acima ? '<' : '>'} :s
                     OR (sort_order = :s2 AND id ${acima ? '<' : '>'} :i))
              ORDER BY sort_order ${acima ? 'DESC' : 'ASC'}, id ${acima ? 'DESC' : 'ASC'}
              LIMIT 1`,
    {
      c: String(oferta.category),
      s: int(oferta.sort_order),
      s2: int(oferta.sort_order),
      i: int(oferta.id),
    },
  );
}

/** Posição (1, 2, 3…) de uma oferta dentro da própria aba, contada como a vitrine ordena. */
export async function posicaoNaAba(oferta: Dict): Promise<number> {
  return 1 + int(await valor(
    `SELECT COUNT(*) FROM shop_offers
              WHERE category = :c
                AND (sort_order < :s OR (sort_order = :s2 AND id < :i))`,
    {
      c: String(oferta.category),
      s: int(oferta.sort_order),
      s2: int(oferta.sort_order),
      i: int(oferta.id),
    },
  ));
}

// ---------------------------------------------------------------------------- escrita
// A ESCRITA não é embalada de propósito: um INSERT que falha tem de estourar, para o
// controller não gravar auditoria de uma coisa que não aconteceu.

/** @return id da oferta criada */
export async function criar(d: Dict): Promise<number> {
  return insert('shop_offers', colunas(d));
}

export async function atualizar(id: number, d: Dict): Promise<void> {
  const cols = colunas(d);
  const set = Object.keys(cols).map((c) => `"${c}" = :${c}`);
  await run('UPDATE shop_offers SET ' + set.join(', ') + ' WHERE id = :id', { ...cols, id });
}

export async function apagar(id: number): Promise<void> {
  await run('DELETE FROM shop_offers WHERE id = :id', { id });
}

export async function definirDestaque(id: number, destaque: boolean): Promise<void> {
  await run('UPDATE shop_offers SET featured = :f WHERE id = :id', { f: destaque ? 1 : 0, id });
}

/**
 * Troca a posição de duas ofertas de forma atômica: o Database::transaction() do PHP vira
 * um batch() (o D1 roda o lote numa transação).
 */
export async function trocarOrdem(idA: number, ordemA: number, idB: number, ordemB: number): Promise<void> {
  await batch([
    prepare('UPDATE shop_offers SET sort_order = :s WHERE id = :id', { s: ordemB, id: idA }),
    prepare('UPDATE shop_offers SET sort_order = :s WHERE id = :id', { s: ordemA, id: idB }),
  ]);
}

// ------------------------------------------------------------------------------- apoio

/** Só as colunas que existem em shop_offers — nada do formulário entra direto no SQL. */
function colunas(d: Dict): Dict {
  const tag = d.tag;
  return {
    item_id: int(d.item_id),
    category: String(d.category),
    price: int(d.price),
    currency: String(d.currency),
    discount: d.discount === null || d.discount === undefined ? null : int(d.discount),
    tag: tag === null || tag === undefined || tag === '' ? null : String(tag),
    featured: d.featured ? 1 : 0,
    sort_order: int(d.sort_order),
  };
}

/** Linha crua do JOIN -> a forma que controller e view usam, já tipada. */
function hidratar(l: Dict): Dict {
  const preco = int(l.price);
  const desconto = l.discount === null || l.discount === undefined ? null : int(l.discount);
  return {
    id: int(l.id),
    item_id: int(l.item_id),
    category: String(l.category ?? ''),
    price: preco,
    currency: String(l.currency ?? ''),
    discount: desconto,
    tag: String(l.tag ?? ''),
    featured: int(l.featured) === 1,
    sort_order: int(l.sort_order),
    preco_final: precoFinal(preco, desconto),
    item_nome: String(l.item_nome ?? ''),
    item_slug: String(l.item_slug ?? ''),
    item_raridade: String(l.item_raridade ?? ''),
    item_imagem: String(l.item_imagem ?? ''),
    item_categoria: String(l.item_categoria ?? ''),
    item_preco_base: int(l.item_preco_base),
    item_moeda_base: String(l.item_moeda_base ?? ''),
  };
}

/** WHERE + parâmetros da listagem. Tudo do usuário é BINDADO; o concatenado vem de lista branca. */
function filtro(f: Dict): [string, Dict] {
  const where = ['1 = 1'];
  const params: Dict = {};

  const q = String(f.q ?? '').trim();
  if (q !== '') {
    // Busca no nome, no slug, na etiqueta e no próprio id da oferta.
    where.push(`(i.name LIKE :q1 ESCAPE '\\' OR i.slug LIKE :q2 ESCAPE '\\' OR o.tag LIKE :q3 ESCAPE '\\' OR o.id = :qid)`);
    const curinga = '%' + escaparLike(q) + '%';
    params.q1 = curinga;
    params.q2 = curinga;
    params.q3 = curinga;
    params.qid = /^\d+$/.test(q) ? int(q) : 0;
  }

  const cat = String(f.cat ?? '');
  if (cat !== '') {
    where.push('o.category = :cat');
    params.cat = cat;
  }

  const moeda = String(f.moeda ?? '');
  if (MOEDAS.includes(moeda)) {
    where.push('o.currency = :moeda');
    params.moeda = moeda;
  }

  const estado = String(f.estado ?? '');
  if (Object.prototype.hasOwnProperty.call(ESTADOS, estado)) {
    where.push(ESTADOS[estado]!);
  }

  return [where.join(' AND '), params];
}

/**
 * A ordem EDITORIAL das abas, como expressão SQL. O FIELD() do MySQL vira CASE (o SQLite não
 * tem FIELD); a categoria antiga que não é aba vai para o fim (9999), como no PHP.
 * Os slugs passam por filtro estrito antes de entrar na string (ORDER BY não aceita placeholder).
 */
function ordemDasAbas(abas: string[]): string {
  const limpos: string[] = [];
  for (const slug of abas) {
    if (/^[a-z0-9_-]{1,24}$/.test(slug)) limpos.push(slug);
  }
  if (limpos.length === 0) return 'o.category';
  return 'CASE o.category ' + limpos.map((s, i) => `WHEN '${s}' THEN ${i + 1}`).join(' ') + ' ELSE 9999 END';
}

/** `%`, `_` e `\` digitados na busca são texto, não curinga. */
function escaparLike(s: string): string {
  return s.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_');
}

// As três embalagens seguem a regra do AdminRepo: leitura que falha vira vazio e o painel
// continua de pé, com o estado vazio honesto na tela.

async function linhas(sql: string, p: Dict = {}): Promise<Dict[]> {
  try {
    return await all<Dict>(sql, p);
  } catch (e) {
    console.error('[extracthunters] admin/loja:', e);
    return [];
  }
}

async function linha(sql: string, p: Dict = {}): Promise<Dict | null> {
  try {
    return await one<Dict>(sql, p);
  } catch (e) {
    console.error('[extracthunters] admin/loja:', e);
    return null;
  }
}

async function valor(sql: string, p: Dict = {}): Promise<unknown> {
  try {
    return await value(sql, p);
  } catch (e) {
    console.error('[extracthunters] admin/loja:', e);
    return null;
  }
}
