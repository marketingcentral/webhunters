/**
 * Catálogo de missões para o PAINEL — o App\Repo\MissionRepo do PHP: `missions` +
 * `mission_rewards`, com o contador de `player_missions` colado.
 *
 * Existe separado do repositório do lado do jogador porque as duas telas querem coisas
 * opostas: o jogo lê UMA missão já traduzida para o cartão da HUD; o painel lê TODAS cruas,
 * com quanta gente está em campo com cada uma, para poder editar e excluir sem quebrar ninguém.
 *
 * O contador é a peça central desta tela. `player_missions` cai por ON DELETE CASCADE: apagar
 * uma missão que está em campo apaga o progresso de quem a aceitou, em silêncio e sem volta.
 * Por isso `ativas` viaja junto de cada linha da lista — e não só na hora de confirmar.
 *
 * Vocabulário: origem, facção, faixa de reputação e teto de esquadrão vêm de GameRules (é
 * regra de jogo). Tipo, dificuldade, atividade humana e espécie de recompensa vêm de
 * dataMock('admin-missoes'), unidos ao DISTINCT da tabela — ver vocabulario().
 *
 * DIFERENÇAS PARA O MySQL (D1/SQLite):
 *   - LIKE: o SQLite não tem `\` como escape padrão — vai `ESCAPE '\'` explícito. E o LIKE do
 *     SQLite ignora caixa só em ASCII (o utf8mb4_unicode_ci do MySQL ignorava caixa E acento):
 *     buscar "dificil" não acha "DIFÍCIL" aqui.
 *   - ORDER BY name: COLLATE NOCASE para chegar perto da collation do MySQL (acentos ainda
 *     ordenam pelo código, não pelo alfabeto).
 *   - Database::transaction() de salvar() vira um batch() só (ver salvar()).
 */
import { memo } from '../core/context';
import { all, batch, insertStmt, one, prepare, run, value } from '../core/database';
import * as GameRules from '../core/game-rules';
import { dataMock, num } from '../core/helpers';
import type { Dict } from '../core/view';

/** Linhas por página da listagem. Cabe inteiro em 1366×768 sem a página rolar. */
export const POR_PAGINA = 12;

/**
 * Estados de `player_missions` que significam "o jogador está com ela EM CAMPO".
 * A coluna aceita PT e EN: contar só um dos dois faria a tela liberar uma exclusão que
 * destrói progresso real.
 */
const EM_CAMPO = ['ativa', 'active'];

/** Colunas de ordenação permitidas -> ORDER BY. Nada vindo da URL entra em SQL cru. */
const ORDENS: Record<string, string> = {
  ordem: 'm.sort_order ASC, m.id ASC',
  nome: 'm.name COLLATE NOCASE ASC',
  nivel: 'm.level_req DESC, m.name COLLATE NOCASE ASC',
  ativas: 'ativas DESC, m.sort_order ASC',
  recente: 'm.id DESC',
};

/** Campos de `missions` que o formulário do painel escreve. */
const CAMPOS = [
  'slug', 'name', 'giver', 'region', 'type', 'difficulty', 'duration_min', 'players_max',
  'level_req', 'objective', 'target', 'image', 'locked', 'sort_order', 'origin', 'act',
  'requires_faction', 'requires_tier', 'requires_rep', 'squad_min', 'squad_max',
  'human_activity',
];

/** Colunas que unir()/sugestoes() podem consultar (o nome entra no SQL, então é lista fechada). */
const COLUNAS_DISTINCT = ['type', 'origin', 'difficulty', 'human_activity', 'requires_faction', 'requires_tier', 'region', 'giver'];

export interface Recompensa {
  id?: number;
  kind: string;
  amount: number;
  item_id: number | null;
  item?: string | null;
}

export interface Uso {
  em_campo: number;
  total: number;
  por_status: Record<string, number>;
}

export interface Lista {
  linhas: Dict[];
  total: number;
  total_geral: number;
  pagina: number;
  paginas: number;
}

// ====================================================================== vocabulário

/**
 * Todas as listas de valor da tela, num lugar só.
 *
 * Cada lista é a do vocabulário canônico UNIDA aos valores que já existem na tabela.
 * A união não é zelo: `missions.type` pode guardar um valor que nenhuma lista conhece. Sem
 * ela, abrir e salvar essa missão trocaria o tipo dela em silêncio. O que veio só do banco é
 * marcado com `orfao` — a tela mostra isso.
 *
 * O `static $cache` do PHP vira memo() (cache da requisição).
 */
export function vocabulario(): Promise<Record<string, Record<string, Dict>>> {
  return memo('admin-mission.vocab', async () => {
    const txt = (dataMock('admin-missoes') ?? {}) as Dict;

    // Origens são regra de jogo: vêm de GameRules, nunca do arquivo de texto do painel.
    const origens: Record<string, Dict> = {};
    for (const [slug, o] of Object.entries(GameRules.ORIGINS)) {
      origens[slug] = { label: String(o.label), tom: tom(String(o.color)) };
    }

    // Facção exigida: as duas facções do jogo mais o coringa "qualquer contrato".
    const faccoes: Record<string, Dict> = { any: { label: 'QUALQUER CONTRATO', tom: 'muted' } };
    for (const [slug, f] of Object.entries(GameRules.FACTIONS)) {
      faccoes[slug] = { label: String(f.label), tom: tom(String(f.color)) };
    }

    // Faixas de reputação, na ordem crescente em que GameRules as declara.
    const faixas: Record<string, Dict> = {};
    for (const t of GameRules.REP_TIERS) {
      faixas[String(t.label)] = {
        label: String(t.label),
        tom: 'muted',
        nota: 'a partir de ' + num(Number(t.min) | 0) + ' de reputação',
      };
    }

    // As consultas DISTINCT são independentes: em paralelo.
    const [tipos, origensU, dificuldades, atividades, faccoesU, faixasU] = await Promise.all([
      unir({ ...((txt.tipos ?? {}) as Record<string, Dict>) }, 'type'),
      unir(origens, 'origin'),
      unir({ ...((txt.dificuldades ?? {}) as Record<string, Dict>) }, 'difficulty'),
      unir({ ...((txt.atividade_humana ?? {}) as Record<string, Dict>) }, 'human_activity'),
      unir(faccoes, 'requires_faction'),
      unir(faixas, 'requires_tier'),
    ]);

    return {
      tipos,
      origens: origensU,
      dificuldades,
      atividades,
      faccoes: faccoesU,
      faixas: faixasU,
      recompensas: { ...((txt.recompensas ?? {}) as Record<string, Dict>) },
    };
  });
}

/** Cor do GameRules -> tom dos primitivos do painel (.adm-badge--*). */
function tom(cor: string): string {
  switch (cor) {
    case 'lime': return 'ok';
    case 'cyan': return 'info';
    case 'amber': return 'warn';
    case 'red': return 'danger';
    case 'purple': return 'purple';
    case 'gold': return 'gold';
    default: return 'muted';
  }
}

/** Junta a lista canônica com o que a coluna realmente guarda hoje. */
async function unir(canon: Record<string, Dict>, coluna: string): Promise<Record<string, Dict>> {
  if (!COLUNAS_DISTINCT.includes(coluna)) return canon;
  let vistos: Dict[];
  try {
    vistos = await all(`SELECT DISTINCT "${coluna}" AS v FROM missions WHERE "${coluna}" IS NOT NULL AND "${coluna}" <> ''`);
  } catch {
    return canon; // banco fora do ar: a lista canônica ainda serve para desenhar
  }
  for (const r of vistos) {
    const v = String(r.v);
    if (!Object.prototype.hasOwnProperty.call(canon, v)) {
      canon[v] = { label: v.toUpperCase(), tom: 'muted', orfao: true };
    }
  }
  return canon;
}

// ========================================================================== listagem

/** Uma página da lista, já com contadores. */
export async function listar(f: Dict): Promise<Lista> {
  const [where, bind] = filtro(f);
  const ordem = ORDENS[String(f.ordem ?? '')] ?? ORDENS.ordem!;

  const total = Number((await value(`SELECT COUNT(*) FROM missions m WHERE ${where}`, bind)) ?? 0) | 0;

  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  const pagina = Math.max(1, Math.min(paginas, Number(f.pagina ?? 1) | 0));
  const offset = (pagina - 1) * POR_PAGINA;

  // LIMIT/OFFSET entram como inteiros já saneados (mesma regra do PHP).
  const sql = `SELECT m.*,
                      COALESCE(pm.ativas, 0)     AS ativas,
                      COALESCE(pm.jogadores, 0)  AS jogadores,
                      COALESCE(mr.n, 0)          AS recompensas
                 FROM missions m
                 LEFT JOIN (SELECT mission_id,
                                   SUM(status IN (${emCampoSql()})) AS ativas,
                                   COUNT(*) AS jogadores
                              FROM player_missions GROUP BY mission_id) pm ON pm.mission_id = m.id
                 LEFT JOIN (SELECT mission_id, COUNT(*) AS n
                              FROM mission_rewards GROUP BY mission_id) mr ON mr.mission_id = m.id
                WHERE ${where}
                ORDER BY ${ordem}
                LIMIT ${POR_PAGINA} OFFSET ${offset}`;

  const [linhas, geral] = await Promise.all([
    all(sql, bind),
    value('SELECT COUNT(*) FROM missions'),
  ]);
  return {
    linhas: linhas as Dict[],
    total,
    total_geral: Number(geral ?? 0) | 0,
    pagina,
    paginas,
  };
}

/**
 * WHERE + binds de um conjunto de filtros. Sempre devolve uma condição verdadeira,
 * para as consultas poderem concatenar sem `if`.
 */
function filtro(f: Dict): [string, Dict] {
  const cond = ['1=1'];
  const bind: Dict = {};

  const q = String(f.q ?? '').trim();
  if (q !== '') {
    // Um placeholder por coluna, como no PHP (lá era exigência do driver; aqui só fidelidade).
    const alvos = ['m.name', 'm.slug', 'm.giver', 'm.region', 'm.objective'];
    const termo = '%' + q.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
    const ors = alvos.map((coluna, i) => {
      bind['q' + i] = termo;
      // ESCAPE explícito: no MySQL a `\` já era o escape padrão do LIKE; no SQLite não há padrão.
      return `${coluna} LIKE :q${i} ESCAPE '\\'`;
    });
    cond.push('(' + ors.join(' OR ') + ')');
  }
  for (const [chave, coluna] of [['tipo', 'type'], ['origem', 'origin'], ['dificuldade', 'difficulty']] as const) {
    const v = String(f[chave] ?? '').trim();
    if (v !== '') {
      cond.push(`m."${coluna}" = :${chave}`);
      bind[chave] = v;
    }
  }
  switch (String(f.estado ?? '')) {
    case 'publicada':
      cond.push('m.locked = 0');
      break;
    case 'bloqueada':
      cond.push('m.locked = 1');
      break;
    case 'em_uso':
      cond.push(`EXISTS (SELECT 1 FROM player_missions p WHERE p.mission_id = m.id
                          AND p.status IN (${emCampoSql()}))`);
      break;
    case 'sem_recompensa':
      cond.push('NOT EXISTS (SELECT 1 FROM mission_rewards r WHERE r.mission_id = m.id)');
      break;
  }
  return [cond.join(' AND '), bind];
}

/** Lista de estados "em campo" pronta para entrar no SQL (literais fixos deste arquivo). */
function emCampoSql(): string {
  return "'" + EM_CAMPO.join("','") + "'";
}

/** Os números do topo da tela. */
export async function resumo(): Promise<Dict> {
  const [r, campo] = await Promise.all([
    one<Dict>(
      `SELECT COUNT(*) AS total,
              SUM(m.locked = 0) AS publicadas,
              SUM(m.locked = 1) AS bloqueadas,
              SUM(EXISTS (SELECT 1 FROM mission_rewards r WHERE r.mission_id = m.id) = 0) AS sem_recompensa
         FROM missions m`,
    ),
    one<Dict>(
      `SELECT COUNT(DISTINCT mission_id) AS missoes, COUNT(*) AS jogadores
         FROM player_missions WHERE status IN (${emCampoSql()})`,
    ),
  ]);
  const a = r ?? {};
  const c = campo ?? {};
  return {
    total: Number(a.total ?? 0) | 0,
    publicadas: Number(a.publicadas ?? 0) | 0,
    bloqueadas: Number(a.bloqueadas ?? 0) | 0,
    sem_recompensa: Number(a.sem_recompensa ?? 0) | 0,
    em_campo: Number(c.missoes ?? 0) | 0,
    jogadores: Number(c.jogadores ?? 0) | 0,
  };
}

/**
 * Recompensas de várias missões de uma vez (a lista mostra os selos).
 * Uma consulta para a página inteira — não uma por linha.
 */
export async function recompensasDe(ids: number[]): Promise<Record<number, Recompensa[]>> {
  const unicos = [...new Set(ids.map((i) => Math.trunc(Number(i)) || 0).filter((i) => i !== 0))];
  if (unicos.length === 0) return {};
  // Placeholders numerados: o conteúdo do IN é gerado por nós, os valores continuam bindados.
  const bind: Dict = {};
  const ph = unicos.map((id, i) => {
    bind['m' + i] = id;
    return ':m' + i;
  });
  const linhas = await all<Dict>(
    `SELECT r.mission_id, r.id, r.kind, r.amount, r.item_id, i.name AS item
       FROM mission_rewards r
       LEFT JOIN items i ON i.id = r.item_id
      WHERE r.mission_id IN (${ph.join(',')})
      ORDER BY r.id`,
    bind,
  );
  const out: Record<number, Recompensa[]> = {};
  for (const l of linhas) {
    const m = Number(l.mission_id) | 0;
    (out[m] ??= []).push({
      id: Number(l.id) | 0,
      kind: String(l.kind),
      amount: Number(l.amount) | 0,
      item_id: l.item_id !== null && l.item_id !== undefined ? Number(l.item_id) | 0 : null,
      item: l.item !== null && l.item !== undefined ? String(l.item) : null,
    });
  }
  return out;
}

// ============================================================================ leitura

export async function porId(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  return one<Dict>('SELECT * FROM missions WHERE id = :id LIMIT 1', { id });
}

/** O slug já pertence a OUTRA missão? (é UNIQUE no banco; a tela avisa antes do erro 500) */
export async function slugEmUso(slug: string, exceto = 0): Promise<boolean> {
  return (await one('SELECT id FROM missions WHERE slug = :s AND id <> :e LIMIT 1', { s: slug, e: exceto })) !== null;
}

/** Quantos jogadores estão com esta missão, e em que situação. */
export async function usoPorJogadores(id: number): Promise<Uso> {
  const linhas = await all<Dict>(
    'SELECT status, COUNT(*) AS n FROM player_missions WHERE mission_id = :m GROUP BY status',
    { m: id },
  );
  const por: Record<string, number> = {};
  let emCampo = 0;
  let total = 0;
  for (const l of linhas) {
    const st = String(l.status);
    const n = Number(l.n) | 0;
    por[st] = n;
    total += n;
    if (EM_CAMPO.includes(st)) emCampo += n;
  }
  return { em_campo: emCampo, total, por_status: por };
}

/** Catálogo de itens para o select de recompensa. */
export async function itens(): Promise<Dict[]> {
  return memo('admin-mission.itens', () => all<Dict>('SELECT id, name, category FROM items ORDER BY category, name'));
}

/**
 * Missões prontas para virar PONTO NO MAPA, na paleta do editor.
 *
 * O editor não cria nem edita missão: ele fixa no terreno uma que já existe. Por isso a
 * consulta traz só o que identifica a missão na paleta e o que o marcador vai herdar —
 * nome, contratante, região e origem.
 */
export async function paraMapa(): Promise<Array<{ id: number; name: string; giver: string; region: string; origin: string; type: string }>> {
  let rows: Dict[];
  try {
    rows = await all<Dict>('SELECT id, name, giver, region, origin, type FROM missions ORDER BY act, sort_order, name');
  } catch {
    // Banco sem as colunas do patch v3 (`origin`/`act`): a paleta ainda funciona, só sem o
    // selo de origem. Degradar é melhor do que derrubar o editor inteiro.
    rows = await all<Dict>('SELECT id, name, giver, region, type FROM missions ORDER BY name');
  }
  return rows.map((r) => ({
    id: Number(r.id) | 0,
    name: String(r.name),
    giver: String(r.giver ?? ''),
    region: String(r.region ?? ''),
    origin: String(r.origin ?? ''),
    type: String(r.type ?? ''),
  }));
}

/** Valores já usados em `region` e `giver`, para o datalist do formulário. */
export async function sugestoes(): Promise<{ regioes: string[]; contratantes: string[] }> {
  const col = async (c: string): Promise<string[]> =>
    (await all<Dict>(`SELECT DISTINCT "${c}" AS v FROM missions WHERE "${c}" IS NOT NULL AND "${c}" <> '' ORDER BY v`)).map((r) =>
      String(r.v),
    );
  const [regioes, contratantes] = await Promise.all([col('region'), col('giver')]);
  return { regioes, contratantes };
}

// ============================================================================ escrita

/**
 * Grava a missão e o conjunto INTEIRO de recompensas, atômico.
 *
 * As recompensas são substituídas, não mescladas: o formulário mostra todas de uma vez,
 * então o que voltou dele É o conjunto.
 *
 * O Database::transaction() do PHP vira UM batch() do D1 (que roda numa transação). Como o
 * id da missão nova só existe depois do INSERT, as recompensas de uma missão NOVA são
 * inseridas com `INSERT ... SELECT id FROM missions WHERE slug = :slug` — o slug é UNIQUE e
 * acabou de ser gravado no mesmo lote, então o lote inteiro continua atômico. O id novo sai
 * do meta.last_row_id do primeiro statement.
 *
 * @return id da missão (novo ou o mesmo)
 */
export async function salvar(dados: Dict, recompensas: Recompensa[], id: number | null = null): Promise<number> {
  const campos: Dict = {};
  for (const c of CAMPOS) if (c in dados) campos[c] = dados[c];

  if (id !== null && id > 0) {
    const sets = Object.keys(campos).map((c) => `"${c}" = :${c}`);
    const stmts = [
      prepare(`UPDATE missions SET ${sets.join(', ')} WHERE id = :id`, { ...campos, id }),
      prepare('DELETE FROM mission_rewards WHERE mission_id = :m', { m: id }),
      ...recompensas.map((r) =>
        insertStmt('mission_rewards', { mission_id: id, kind: r.kind, amount: r.amount, item_id: r.item_id }),
      ),
    ];
    await batch(stmts);
    return id;
  }

  const stmts = [
    insertStmt('missions', campos),
    ...recompensas.map((r) =>
      prepare(
        `INSERT INTO mission_rewards (mission_id, kind, amount, item_id)
         SELECT id, :kind, :amount, :item_id FROM missions WHERE slug = :slug`,
        { kind: r.kind, amount: r.amount, item_id: r.item_id, slug: campos.slug },
      ),
    ),
  ];
  const res = await batch(stmts);
  return Number(res[0]?.meta.last_row_id ?? 0);
}

/**
 * Apaga a missão. `mission_rewards` cai por CASCADE — é filha, não referência.
 *
 * NÃO checa `player_missions` aqui de propósito: quem decide isso é o controller, que é
 * quem tem como explicar o bloqueio ao operador. O repositório não inventa política.
 */
export async function excluir(id: number): Promise<void> {
  await run('DELETE FROM missions WHERE id = :id', { id });
}
