/**
 * Catálogo de conquistas visto pelo PAINEL — o App\Repo\AchievementRepo do PHP: todo o SQL da
 * tela /admin/conquistas.
 *
 * Por que um repositório próprio e não AdminRepo: AdminRepo é a leitura do dashboard (métrica
 * agregada, só leitura). Aqui há ESCRITA em duas tabelas que precisam mudar juntas
 * (achievements + achievement_rewards) e contagem de uso em player_achievements.
 *
 * Três regras que valem para o arquivo inteiro (iguais ao PHP):
 *
 *  1. Nenhum rótulo mora aqui. `category` sai como veio do banco ('combate'); quem traduz é
 *     dataMock('admin-conquistas'). Categoria criada no banco por outro time não desaparece
 *     da tela só porque ninguém escreveu o nome bonito dela.
 *  2. Todo valor de usuário entra por parâmetro nomeado. As DUAS exceções (ORDER BY e LIMIT)
 *     não são valores: vêm de whitelist e de inteiro saneado, e estão comentadas no lugar.
 *  3. A contagem de uso é a razão de existir da trava de exclusão. `desbloqueios` conta
 *     jogador que CONCLUIU (completed_at preenchido); `progresso` conta quem começou e não
 *     terminou. Apagar a conquista levaria as duas coisas junto por ON DELETE CASCADE.
 *
 * DIFERENÇAS PARA O MySQL (D1/SQLite):
 *   - LIKE: o SQLite não tem `\` como escape padrão — vai `ESCAPE '\'` explícito. E o LIKE do
 *     SQLite ignora caixa só em ASCII (o utf8mb4_unicode_ci do MySQL ignorava caixa E acento).
 *   - ORDER BY name: COLLATE NOCASE para chegar perto da collation do MySQL (acentos ainda
 *     ordenam pelo código, não pelo alfabeto).
 *   - Database::transaction() de criar()/atualizar() vira UM batch() (ver criar()).
 */
import { all, batch, insertStmt, one, prepare, run, value } from '../core/database';
import type { Dict } from '../core/view';

/** Estados derivados do uso — o filtro da lista e o selo de cada linha. */
export const ESTADO_DESBLOQUEADA = 'desbloqueada';
export const ESTADO_PROGRESSO = 'progresso';
export const ESTADO_PARADA = 'parada';

/** Ordenações aceitas: chave da URL => trecho de ORDER BY (NUNCA texto do usuário). */
const ORDENS: Record<string, string> = {
  ordem: 'a.sort_order ASC, a.name COLLATE NOCASE ASC',
  nome: 'a.name COLLATE NOCASE ASC',
  desbloqueios: 'desbloqueios DESC, a.sort_order ASC',
  meta: 'a.target DESC, a.sort_order ASC',
};

/** Tipos de recompensa que o jogo entende (achievement_rewards.kind). */
export const RECOMPENSAS = ['xp', 'cash', 'points', 'item'];

/** Recompensas que são um NÚMERO (as demais apontam para um item do catálogo). */
export const RECOMPENSAS_NUMERICAS = ['xp', 'cash', 'points'];

/** Teto de recompensas por conquista — ver o comentário do formulário na view. */
export const MAX_RECOMPENSAS = 6;

/** Uma recompensa já validada, pronta para gravar. */
export interface RecompensaNova {
  kind: string;
  amount: number;
  item_id: number | null;
}

export interface Uso {
  jogadores: number;
  desbloqueios: number;
  progresso: number;
}

export interface Resumo {
  conquistas: number;
  categorias: number;
  desbloqueios: number;
  jogadores: number;
  paradas: number;
  sem_recompensa: number;
}

export interface Categoria {
  slug: string;
  conquistas: number;
  desbloqueios: number;
}

export interface Pagina {
  linhas: Dict[];
  total: number;
  pagina: number;
  paginas: number;
  por_pagina: number;
  inicio: number;
  fim: number;
}

const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;

/* =====================================================================================
   LEITURA
   ===================================================================================== */

/** Uma página do catálogo, já com contagem de uso e com as recompensas de cada linha. */
export async function catalogo(f: Dict = {}): Promise<Pagina> {
  const busca = String(f.busca ?? '').trim();
  const categoria = String(f.categoria ?? '').trim();
  const estado = String(f.estado ?? '').trim();
  const ordem = ORDENS[String(f.ordem ?? '')] ?? ORDENS.ordem!;
  const porPagina = Math.max(1, Math.min(100, int(f.por_pagina ?? 20)));
  let pagina = Math.max(1, int(f.pagina ?? 1));

  const [onde, p] = filtros(busca, categoria, estado);

  const total = int(await value('SELECT COUNT(*) FROM (' + baseSql(onde) + ') t', p));
  const paginas = Math.max(1, Math.ceil(total / porPagina));
  pagina = Math.min(pagina, paginas);
  const offset = (pagina - 1) * porPagina;

  /* LIMIT/OFFSET entram no texto do SQL (como no PHP): os dois já passaram por inteiro e
     por min/max acima. A ordenação vem de ORDENS, nunca do que veio na URL. */
  const sql = baseSql(onde) + ' ORDER BY a.category ASC, ' + ordem + ' LIMIT ' + porPagina + ' OFFSET ' + offset;

  const linhas = (await all<Dict>(sql, p)).map(normalizar);

  // As recompensas de TODA a página numa consulta só — uma por linha viraria N+1.
  const recs = await recompensasDe(linhas.map((l) => int(l.id)));
  for (const l of linhas) l.recompensas = recs[int(l.id)] ?? [];

  return {
    linhas,
    total,
    pagina,
    paginas,
    por_pagina: porPagina,
    inicio: total === 0 ? 0 : offset + 1,
    fim: Math.min(offset + porPagina, total),
  };
}

/** Os números do topo da tela. Todos medidos; nenhum estimado. */
export async function resumo(): Promise<Resumo> {
  const [a, u, paradas, semRecompensa] = await Promise.all([
    one<Dict>('SELECT COUNT(*) AS conquistas, COUNT(DISTINCT category) AS categorias FROM achievements'),
    one<Dict>(
      `SELECT COUNT(*) AS desbloqueios, COUNT(DISTINCT user_id) AS jogadores
         FROM player_achievements WHERE completed_at IS NOT NULL`,
    ),
    // "Parada" é a conquista que ninguém concluiu E ninguém começou: conteúdo morto.
    value(
      `SELECT COUNT(*) FROM achievements a
        WHERE NOT EXISTS (SELECT 1 FROM player_achievements p
                           WHERE p.achievement_id = a.id
                             AND (p.completed_at IS NOT NULL OR p.progress > 0))`,
    ),
    value(
      `SELECT COUNT(*) FROM achievements a
        WHERE NOT EXISTS (SELECT 1 FROM achievement_rewards r WHERE r.achievement_id = a.id)`,
    ),
  ]);

  return {
    conquistas: int(a?.conquistas),
    categorias: int(a?.categorias),
    desbloqueios: int(u?.desbloqueios),
    jogadores: int(u?.jogadores),
    paradas: int(paradas),
    sem_recompensa: int(semRecompensa),
  };
}

/**
 * Categorias que EXISTEM no banco, com quantas conquistas e quantos desbloqueios cada uma
 * tem. É a fonte do filtro e do painel lateral.
 */
export async function categorias(): Promise<Categoria[]> {
  const linhas = await all<Dict>(
    `SELECT a.category AS slug,
            COUNT(*) AS conquistas,
            COALESCE(SUM(u.desbloqueios), 0) AS desbloqueios
       FROM achievements a
       LEFT JOIN (SELECT achievement_id, COUNT(*) AS desbloqueios
                    FROM player_achievements
                   WHERE completed_at IS NOT NULL
                   GROUP BY achievement_id) u ON u.achievement_id = a.id
      GROUP BY a.category
      ORDER BY a.category ASC`,
  );
  return linhas.map((l) => ({
    slug: String(l.slug),
    conquistas: int(l.conquistas),
    desbloqueios: int(l.desbloqueios),
  }));
}

/** Uma conquista com contagem de uso, ou null. */
export async function porId(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  const linha = await one<Dict>(baseSql('a.id = :id'), { id });
  if (linha === null) return null;
  const n = normalizar(linha);
  n.recompensas = (await recompensasDe([id]))[id] ?? [];
  return n;
}

/** O dono do slug, quando já houver um (usado pela validação de unicidade). */
export async function porSlug(slug: string, excetoId = 0): Promise<Dict | null> {
  return one<Dict>('SELECT id, name FROM achievements WHERE slug = :s AND id <> :e LIMIT 1', { s: slug, e: excetoId });
}

/** Uso de UMA conquista — a consulta que autoriza (ou proíbe) a exclusão. */
export async function uso(id: number): Promise<Uso> {
  const l = (await one<Dict>(
    `SELECT COUNT(*) AS jogadores,
            COALESCE(SUM(completed_at IS NOT NULL), 0) AS desbloqueios,
            COALESCE(SUM(completed_at IS NULL AND progress > 0), 0) AS progresso
       FROM player_achievements WHERE achievement_id = :id`,
    { id },
  )) ?? {};
  return {
    jogadores: int(l.jogadores),
    desbloqueios: int(l.desbloqueios),
    progresso: int(l.progresso),
  };
}

/**
 * Itens que podem ser recompensa. Lista inteira porque o catálogo tem dezenas de linhas,
 * não milhares — quando passar disso, aqui é o lugar de trocar por busca.
 */
export async function itens(): Promise<Array<{ id: number; name: string; category: string; rarity: string }>> {
  const linhas = await all<Dict>('SELECT id, name, category, rarity FROM items ORDER BY category ASC, name COLLATE NOCASE ASC');
  return linhas.map((l) => ({
    id: int(l.id),
    name: String(l.name),
    category: String(l.category),
    rarity: String(l.rarity),
  }));
}

/** Existe mesmo este item? (a validação não confia no <select> que voltou do navegador) */
export async function itemExiste(id: number): Promise<boolean> {
  return id > 0 && (await value('SELECT 1 FROM items WHERE id = :id', { id })) !== null;
}

/** Próxima posição livre dentro da categoria — o padrão do campo "ordem" ao criar. */
export async function proximaOrdem(categoria: string): Promise<number> {
  const max = await value('SELECT MAX(sort_order) FROM achievements WHERE category = :c', { c: categoria });
  return max === null ? 0 : Math.min(65535, int(max) + 1);
}

/* =====================================================================================
   ESCRITA — conquista e recompensas mudam JUNTAS ou não mudam
   ===================================================================================== */

/**
 * Cria a conquista e as recompensas num lote atômico.
 *
 * O Database::transaction() do PHP vira UM batch() do D1. Como o id da conquista nova só
 * existe depois do INSERT, as recompensas entram com `INSERT ... SELECT id FROM achievements
 * WHERE slug = :slug` — o slug é UNIQUE e acabou de ser gravado no mesmo lote. O id novo sai
 * do meta.last_row_id do primeiro statement.
 */
export async function criar(dados: Dict, recompensas: RecompensaNova[]): Promise<number> {
  const cols = colunas(dados);
  const res = await batch([
    insertStmt('achievements', cols),
    ...recompensas.map((r) =>
      prepare(
        `INSERT INTO achievement_rewards (achievement_id, kind, amount, item_id)
         SELECT id, :k, :m, :i FROM achievements WHERE slug = :slug`,
        { k: String(r.kind), m: int(r.amount), i: r.item_id === null ? null : int(r.item_id), slug: cols.slug },
      ),
    ),
  ]);
  return Number(res[0]?.meta.last_row_id ?? 0);
}

/** Atualiza a conquista e REESCREVE o conjunto de recompensas, no mesmo lote atômico. */
export async function atualizar(id: number, dados: Dict, recompensas: RecompensaNova[]): Promise<void> {
  await batch([
    prepare(
      `UPDATE achievements
          SET slug = :slug, name = :name, category = :category, description = :description,
              icon = :icon, target = :target, sort_order = :sort_order
        WHERE id = :id`,
      { ...colunas(dados), id },
    ),
    // Recompensa não tem identidade estável na tela (o operador reordena e apaga linhas à
    // vontade), então o conjunto é reescrito inteiro dentro do lote.
    prepare('DELETE FROM achievement_rewards WHERE achievement_id = :id', { id }),
    ...recompensas.map((r) => stmtRecompensa(id, r)),
  ]);
}

/**
 * Apaga a conquista. NÃO decide se pode: quem decide é o controller, com uso().
 * As recompensas caem por ON DELETE CASCADE (fk_ar_ach).
 */
export async function excluir(id: number): Promise<void> {
  await run('DELETE FROM achievements WHERE id = :id', { id });
}

/* =====================================================================================
   INTERNO
   ===================================================================================== */

/** O SELECT que todas as leituras compartilham: a conquista mais as três contagens de uso. */
function baseSql(onde: string): string {
  return `SELECT a.id, a.slug, a.name, a.category, a.description, a.icon, a.target, a.sort_order,
                 COALESCE(u.jogadores, 0)    AS jogadores,
                 COALESCE(u.desbloqueios, 0) AS desbloqueios,
                 COALESCE(u.progresso, 0)    AS progresso,
                 COALESCE(r.recompensas, 0)  AS n_recompensas
            FROM achievements a
            LEFT JOIN (SELECT achievement_id,
                              COUNT(*) AS jogadores,
                              SUM(completed_at IS NOT NULL) AS desbloqueios,
                              SUM(completed_at IS NULL AND progress > 0) AS progresso
                         FROM player_achievements
                        GROUP BY achievement_id) u ON u.achievement_id = a.id
            LEFT JOIN (SELECT achievement_id, COUNT(*) AS recompensas
                         FROM achievement_rewards
                        GROUP BY achievement_id) r ON r.achievement_id = a.id
           WHERE ` + onde;
}

/** Monta o WHERE da listagem. Cada filtro é uma cláusula independente; todo valor é parâmetro. */
function filtros(busca: string, categoria: string, estado: string): [string, Dict] {
  const onde = ['1 = 1'];
  const p: Dict = {};

  if (busca !== '') {
    // Busca no que o operador enxerga: nome, slug e o texto da descrição.
    // ESCAPE explícito: no MySQL a `\` já era o escape padrão do LIKE; no SQLite não há padrão.
    onde.push("(a.name LIKE :q1 ESCAPE '\\' OR a.slug LIKE :q2 ESCAPE '\\' OR a.description LIKE :q3 ESCAPE '\\')");
    const alvo = '%' + busca.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
    p.q1 = alvo;
    p.q2 = alvo;
    p.q3 = alvo;
  }

  if (categoria !== '') {
    onde.push('a.category = :cat');
    p.cat = categoria;
  }

  const temConclusao = `EXISTS (SELECT 1 FROM player_achievements p
                                 WHERE p.achievement_id = a.id AND p.completed_at IS NOT NULL)`;
  const temProgresso = `EXISTS (SELECT 1 FROM player_achievements p
                                 WHERE p.achievement_id = a.id AND p.progress > 0)`;

  switch (estado) {
    case ESTADO_DESBLOQUEADA:
      onde.push(temConclusao);
      break;
    case ESTADO_PROGRESSO:
      onde.push('NOT ' + temConclusao + ' AND ' + temProgresso);
      break;
    case ESTADO_PARADA:
      onde.push('NOT ' + temConclusao + ' AND NOT ' + temProgresso);
      break;
    default:
      onde.push('1 = 1');
  }

  return [onde.join(' AND '), p];
}

/** Recompensas de várias conquistas de uma vez, já com o nome do item resolvido. */
async function recompensasDe(ids: number[]): Promise<Record<number, Dict[]>> {
  const unicos = [...new Set(ids.map(int).filter((i) => i > 0))];
  if (unicos.length === 0) return {};

  // A lista do IN é montada com inteiros (como no PHP) — não há string de usuário aqui.
  const linhas = await all<Dict>(
    `SELECT r.id, r.achievement_id, r.kind, r.amount, r.item_id,
            i.name AS item_nome, i.category AS item_categoria
       FROM achievement_rewards r
       LEFT JOIN items i ON i.id = r.item_id
      WHERE r.achievement_id IN (${unicos.join(',')})
      ORDER BY r.achievement_id ASC, r.id ASC`,
  );

  const fora: Record<number, Dict[]> = {};
  for (const l of linhas) {
    (fora[int(l.achievement_id)] ??= []).push({
      id: int(l.id),
      kind: String(l.kind),
      amount: int(l.amount),
      item_id: l.item_id === null || l.item_id === undefined ? null : int(l.item_id),
      item_nome: l.item_nome === null || l.item_nome === undefined ? null : String(l.item_nome),
      item_categoria: l.item_categoria === null || l.item_categoria === undefined ? null : String(l.item_categoria),
    });
  }
  return fora;
}

/** Tipos certos e o estado derivado calculado UMA vez, aqui — a view não repete a conta. */
function normalizar(l: Dict): Dict {
  const desbloqueios = int(l.desbloqueios);
  const progresso = int(l.progresso);
  return {
    id: int(l.id),
    slug: String(l.slug),
    name: String(l.name),
    category: String(l.category),
    description: l.description === null || l.description === undefined ? '' : String(l.description),
    icon: l.icon === null || l.icon === undefined ? '' : String(l.icon),
    target: int(l.target),
    sort_order: int(l.sort_order),
    jogadores: int(l.jogadores),
    desbloqueios,
    progresso,
    n_recompensas: int(l.n_recompensas),
    estado: desbloqueios > 0 ? ESTADO_DESBLOQUEADA : progresso > 0 ? ESTADO_PROGRESSO : ESTADO_PARADA,
    // Conquista já conquistada por alguém não pode ser apagada: o histórico do jogador cairia
    // junto por CASCADE. A tela desarma o botão; o controller confere de novo antes de gravar.
    pode_excluir: desbloqueios === 0,
    recompensas: [],
  };
}

function colunas(d: Dict): Dict {
  return {
    slug: String(d.slug),
    name: String(d.name),
    category: String(d.category),
    description: (d.description ?? '') === '' ? null : String(d.description),
    icon: (d.icon ?? '') === '' ? null : String(d.icon),
    target: int(d.target),
    sort_order: int(d.sort_order),
  };
}

function stmtRecompensa(id: number, r: RecompensaNova): D1PreparedStatement {
  return prepare(
    `INSERT INTO achievement_rewards (achievement_id, kind, amount, item_id)
     VALUES (:a, :k, :m, :i)`,
    { a: id, k: String(r.kind), m: int(r.amount), i: r.item_id === null ? null : int(r.item_id) },
  );
}
