/**
 * Catálogo mestre de itens (`items`) para o painel administrativo — o App\Repo\ItemsRepo do PHP.
 *
 * Todo SQL da tela /admin/itens vive aqui — o controller decide POLÍTICA (o que é válido, o
 * que pode ser excluído) e a view desenha; nenhum dos dois monta consulta.
 *
 * PORQUE A COLUNA "EM INVENTÁRIOS" EXISTE: `player_items.item_id` tem FOREIGN KEY com
 * ON DELETE CASCADE. Apagar uma linha de `items` apagaria, em silêncio, todas as instâncias
 * daquele item na mochila de todo mundo — e `shop_offers` cai junto pelo mesmo motivo.
 * A contagem não é enfeite de tabela: é o dado que autoriza (ou proíbe) a exclusão.
 *
 * Existe separado de repo/catalog-repo.ts e repo/inventory-repo.ts (lado do jogador): aqui
 * o catálogo é lido CRU, com os contadores de posse, para poder editar e excluir.
 *
 * DIFERENÇAS PARA O MySQL (D1/SQLite):
 *   - `weight_kg` é REAL no D1 (DECIMAL(6,3) no MySQL, que o PDO devolvia como "3.600").
 *     porId() devolve a coluna já formatada com 3 casas, como string — é o que o formulário
 *     mostra, o que a comparação de "o que mudou" usa e o que vai para a auditoria.
 *   - Busca: CONCAT_WS do MySQL vira `||` com COALESCE (o CONCAT_WS ignorava NULL). LIKE com
 *     `ESCAPE '\'` explícito (no SQLite não há escape padrão) e sem ignorar acento (o
 *     utf8mb4_unicode_ci ignorava): buscar "municao" não acha "Munição".
 *   - ORDER BY name com COLLATE NOCASE para chegar perto da collation do MySQL.
 *   - information_schema.TABLES vira sqlite_master; o `static $existe` vira memo() (cache da
 *     requisição, não do processo).
 */
import { memo } from '../core/context';
import { all, insert, one, run, value } from '../core/database';
import type { Dict } from '../core/view';

/** Linhas por página da listagem. */
export const POR_PAGINA = 20;

/**
 * Ordenações aceitas -> ORDER BY. A chave vem da URL e NUNCA é interpolada: só o valor
 * desta tabela entra no SQL. `nome` é o desempate de todas, para a paginação ser estável.
 */
const ORDEM: Record<string, string> = {
  nome: 'i.name COLLATE NOCASE ASC',
  recente: 'i.id DESC',
  preco: 'i.base_price DESC, i.name COLLATE NOCASE ASC',
  peso: 'i.weight_kg DESC, i.name COLLATE NOCASE ASC',
  uso: 'inst DESC, i.name COLLATE NOCASE ASC',
};

/** Colunas que o painel pode escrever (o nome entra no SQL de atualizar(), então é lista fechada). */
const COLUNAS = [
  'slug', 'name', 'short_name', 'category', 'subtype', 'slot_type', 'grid_w', 'grid_h',
  'weight_kg', 'rarity', 'image', 'description', 'base_price', 'currency', 'stackable',
  'max_stack', 'durability_max', 'grid_cols', 'grid_rows', 'ammo_caliber', 'mag_capacity',
  'attach_slots', 'fits_calibers', 'meta',
];

/** Tabelas que contarSeExistir() pode consultar (o nome entra no SQL). */
const TABELAS_REF = ['shop_offers', 'mission_rewards', 'achievement_rewards'];

export interface Lista {
  linhas: Dict[];
  total: number;
  pagina: number;
  paginas: number;
}

export interface Usos {
  instancias: number;
  jogadores: number;
  ofertas: number;
  missoes: number;
  conquistas: number;
  total: number;
}

export interface Resumo {
  itens: number;
  instancias: number;
  donos: number;
  categorias: number;
  ofertas: number;
  orfaos: number;
}

const int = (v: unknown): number => Number(v ?? 0) | 0;

/**
 * Página da listagem, já com a contagem de posse de cada item.
 * A posse vem de uma tabela derivada agregada UMA vez (e não de subconsulta por linha).
 */
export async function listar(f: Dict = {}): Promise<Lista> {
  const [where, params] = filtro(f);
  const ordem = ORDEM[String(f.ord ?? 'nome')] ?? ORDEM.nome!;

  const total = int(await value(`SELECT COUNT(*) FROM items i ${where}`, params));

  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  const pagina = Math.min(Math.max(1, int(f.pagina ?? 1)), paginas);
  const offset = (pagina - 1) * POR_PAGINA;

  // LIMIT/OFFSET entram como inteiros já validados acima (mesma regra do PHP).
  const linhas = await all<Dict>(
    `SELECT i.id, i.slug, i.name, i.short_name, i.category, i.subtype, i.slot_type,
            i.grid_w, i.grid_h, i.weight_kg, i.rarity, i.image, i.base_price, i.currency,
            i.stackable, i.max_stack, i.durability_max, i.ammo_caliber, i.mag_capacity,
            COALESCE(p.inst, 0) AS inst,
            COALESCE(p.jogadores, 0) AS jogadores
       FROM items i
       LEFT JOIN (
            SELECT item_id, COUNT(*) AS inst, COUNT(DISTINCT user_id) AS jogadores
              FROM player_items GROUP BY item_id
       ) p ON p.item_id = i.id
       ${where}
      ORDER BY ${ordem}
      LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    params,
  );

  return { linhas, total, pagina, paginas };
}

/** Cabeçalho de números da tela. Tudo medido, nada estimado. */
export async function resumo(): Promise<Resumo> {
  const [t, ofertas, orfaos] = await Promise.all([
    one<Dict>(
      `SELECT (SELECT COUNT(*) FROM items) AS itens,
              (SELECT COUNT(*) FROM player_items) AS instancias,
              (SELECT COUNT(DISTINCT user_id) FROM player_items) AS donos,
              (SELECT COUNT(DISTINCT category) FROM items) AS categorias`,
    ),
    contarSeExistir('shop_offers'),
    // Item que ninguém possui: existe no catálogo e não chega a jogador nenhum.
    value(
      `SELECT COUNT(*) FROM items i
        WHERE NOT EXISTS (SELECT 1 FROM player_items pi WHERE pi.item_id = i.id)`,
    ),
  ]);
  const r = t ?? {};
  return {
    itens: int(r.itens),
    instancias: int(r.instancias),
    donos: int(r.donos),
    categorias: int(r.categorias),
    ofertas,
    orfaos: int(orfaos),
  };
}

/** Categorias presentes no banco, com quantos itens cada uma tem. (memo: a tela e a validação pedem as duas) */
export function categoriasUsadas(): Promise<Record<string, number>> {
  return memo('admin-items.cats', () => contagem('category'));
}

/** Raridades presentes no banco. */
export function raridadesUsadas(): Promise<Record<string, number>> {
  return memo('admin-items.rars', () => contagem('rarity'));
}

async function contagem(coluna: 'category' | 'rarity'): Promise<Record<string, number>> {
  const out: Record<string, number> = {};
  for (const r of await all<Dict>(`SELECT ${coluna} AS v, COUNT(*) AS n FROM items GROUP BY ${coluna}`)) {
    out[String(r.v)] = int(r.n);
  }
  return out;
}

/** Linha inteira de `items` (weight_kg já como "0.000", igual ao DECIMAL do PDO). */
export async function porId(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  const r = await one<Dict>('SELECT * FROM items WHERE id = :id LIMIT 1', { id });
  if (r !== null && r.weight_kg !== null && r.weight_kg !== undefined) {
    r.weight_kg = (Number(r.weight_kg) || 0).toFixed(3);
  }
  return r;
}

/** Slug já em uso? `exceto` é o próprio item numa edição. */
export async function slugEmUso(slug: string, exceto = 0): Promise<boolean> {
  return int(await value('SELECT COUNT(*) FROM items WHERE slug = :s AND id <> :id', { s: slug, id: exceto })) > 0;
}

/**
 * Onde este item é referenciado. É a consulta que decide se o botão Excluir funciona.
 * `player_items`/`shop_offers` caem em CASCADE; as recompensas usam SET NULL. As duas bloqueiam.
 */
export async function usos(id: number): Promise<Usos> {
  const [posse, ofertas, missoes, conquistas] = await Promise.all([
    one<Dict>('SELECT COUNT(*) AS inst, COUNT(DISTINCT user_id) AS jog FROM player_items WHERE item_id = :id', { id }),
    contarSeExistir('shop_offers', id),
    contarSeExistir('mission_rewards', id),
    contarSeExistir('achievement_rewards', id),
  ]);
  const u = {
    instancias: int(posse?.inst),
    jogadores: int(posse?.jog),
    ofertas,
    missoes,
    conquistas,
  };
  // `jogadores` é recorte de `instancias`, não uma referência a mais.
  return { ...u, total: u.instancias + u.ofertas + u.missoes + u.conquistas };
}

/** INSERT do item; devolve o id novo. */
export async function criar(dados: Dict): Promise<number> {
  return insert('items', filtrarColunas(dados));
}

/**
 * UPDATE só das colunas entregues. A lista de colunas vem das CHAVES do objeto montado pelo
 * controller a partir de um esquema fixo — e é filtrada de novo por COLUNAS aqui.
 */
export async function atualizar(id: number, dados: Dict): Promise<void> {
  const campos = filtrarColunas(dados);
  const cols = Object.keys(campos);
  if (cols.length === 0) return;
  const sets = cols.map((c) => `"${c}" = :${c}`);
  await run(`UPDATE items SET ${sets.join(', ')} WHERE id = :id_alvo`, { ...campos, id_alvo: id });
}

export async function excluir(id: number): Promise<void> {
  await run('DELETE FROM items WHERE id = :id', { id });
}

// ============================================================================ internos

function filtrarColunas(dados: Dict): Dict {
  const out: Dict = {};
  for (const c of COLUNAS) if (c in dados) out[c] = dados[c];
  return out;
}

/** WHERE + parâmetros dos filtros da listagem. */
function filtro(f: Dict): [string, Dict] {
  const cond: string[] = [];
  const params: Dict = {};

  const q = String(f.q ?? '').trim();
  if (q !== '') {
    // LIKE com curinga nas pontas; o escape dos curingas impede que um `%` digitado vire "tudo".
    // CONCAT_WS(' ', name, slug, short_name) do MySQL: o COALESCE faz o NULL sumir, como lá.
    cond.push(`(i.name || ' ' || i.slug || COALESCE(' ' || i.short_name, '')) LIKE :q ESCAPE '\\'`);
    params.q = '%' + q.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
  }

  const cat = String(f.cat ?? '').trim();
  if (cat !== '') {
    cond.push('i.category = :cat');
    params.cat = cat;
  }

  const rar = String(f.rar ?? '').trim();
  if (rar !== '') {
    cond.push('i.rarity = :rar');
    params.rar = rar;
  }

  return [cond.length === 0 ? '' : 'WHERE ' + cond.join(' AND '), params];
}

/**
 * COUNT numa tabela que pode não existir neste banco. Devolver 0 por erro diria que NÃO HÁ
 * referência — e liberaria uma exclusão perigosa. Por isso a ausência é conferida antes.
 */
async function contarSeExistir(tabela: string, itemId = 0): Promise<number> {
  if (!TABELAS_REF.includes(tabela)) return 0;
  const existe = await memo('admin-items.existe.' + tabela, async () =>
    int(await value(`SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t`, { t: tabela })) > 0,
  );
  if (!existe) return 0;
  return itemId > 0
    ? int(await value(`SELECT COUNT(*) FROM "${tabela}" WHERE item_id = :id`, { id: itemId }))
    : int(await value(`SELECT COUNT(*) FROM "${tabela}" WHERE item_id IS NOT NULL`));
}
