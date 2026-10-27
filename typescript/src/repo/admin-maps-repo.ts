/**
 * Catálogo de mapas visto pelo PAINEL (`maps` + `map_markers`) — o App\Repo\MapsRepo do PHP.
 *
 * Existe separado da leitura do JOGO (src/core/world-map.ts, que porta o CatalogRepo) de
 * propósito: o jogo cacheia por requisição, devolve a linha já traduzida para o que a HUD
 * espera e nunca escreve. O painel precisa do oposto: linha CRUA (para o formulário mostrar
 * exatamente o que está gravado), contagem de marcadores, busca/ordenação/paginação e escrita.
 *
 * Nada aqui valida: validação é decisão de tela e mora no AdminMapsController, que precisa
 * dizer QUAL campo está errado. Aqui só existe SQL parametrizado.
 *
 * DIFERENÇAS PARA O MySQL (D1/SQLite):
 *   - LIKE: o SQLite não tem `\` como escape padrão — vai `ESCAPE '\'` explícito. E o LIKE do
 *     SQLite ignora caixa só em ASCII (o utf8mb4_unicode_ci do MySQL ignorava caixa E acento).
 *   - ORDER BY name: COLLATE NOCASE para chegar perto da collation do MySQL.
 *   - `x`/`y` de `map_markers` são REAL (no MySQL eram DECIMAL(6,4) e chegavam como string).
 *   - excluir(): os marcadores são apagados explicitamente no mesmo batch, além da FK com
 *     ON DELETE CASCADE — assim o resultado não depende de `PRAGMA foreign_keys` estar ligado.
 */
import { all, batch, insert, one, prepare, run, value } from '../core/database';
import type { Dict } from '../core/view';

/** `maps.kind` — os dois únicos aceitos pelo jogo (região do mundo x mapa de partida). */
export const TIPOS: Record<string, string> = { region: 'Região', match: 'Partida' };

/** Colunas que a listagem aceita ordenar. A chave vem da URL; o valor é SQL literal. */
const ORDENACOES: Record<string, string> = {
  ordem: 'm.sort_order %s, m.name COLLATE NOCASE ASC',
  nome: 'm.name COLLATE NOCASE %s',
  tipo: 'm.kind %s, m.sort_order ASC',
  nivel: 'm.level_min IS NULL, m.level_min %s, m.level_max %s',
  marcadores: 'marcadores %s, m.name COLLATE NOCASE ASC',
  estado: 'm.locked %s, m.name COLLATE NOCASE ASC',
};

/** Colunas de `maps` que o formulário do painel escreve. */
const CAMPOS = [
  'slug', 'name', 'kind', 'mode', 'players', 'level_min', 'level_max',
  'locked', 'image', 'description', 'unreal_level', 'meta', 'sort_order',
];

export interface Lista {
  linhas: Dict[];
  total: number;
  pagina: number;
  paginas: number;
  por_pagina: number;
}

export interface Resumo {
  total: number;
  regioes: number;
  partidas: number;
  bloqueados: number;
  sem_ue: number;
  marcadores: number;
}

export interface MapaEditor {
  id: number;
  slug: string;
  name: string;
  kind: string;
  marcadores: number;
  world_image: string;
}

const tem = (o: object, k: string): boolean => Object.prototype.hasOwnProperty.call(o, k);

/**
 * Página da listagem + total que casa com o filtro (para o rodapé e a paginação).
 *
 * A contagem de marcadores vem por subconsulta, e não por JOIN + GROUP BY, porque a tela
 * mostra mapa SEM marcador nenhum — que é justamente o caso que o operador precisa achar.
 *
 * `f`: busca, tipo, estado, ordem, dir, pagina, por_pagina (como no PHP).
 */
export async function listar(f: Dict = {}): Promise<Lista> {
  const onde: string[] = [];
  const params: Dict = {};

  const busca = String(f.busca ?? '').trim();
  if (busca !== '') {
    // Busca no que o operador tem na mão: nome, slug, modo e o level da Unreal.
    // Um placeholder POR COLUNA, como no PHP (lá era exigência do PDO nativo).
    const termo = '%' + busca.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_') + '%';
    const colunas = ['m.name', 'm.slug', 'm.unreal_level', 'm.mode'];
    const ors = colunas.map((coluna, i) => {
      params['q' + i] = termo;
      // ESCAPE explícito: no MySQL a `\` já era o escape padrão do LIKE; no SQLite não há padrão.
      return `${coluna} LIKE :q${i} ESCAPE '\\'`;
    });
    onde.push('(' + ors.join(' OR ') + ')');
  }

  const tipo = String(f.tipo ?? '');
  if (tem(TIPOS, tipo)) {
    onde.push('m.kind = :kind');
    params.kind = tipo;
  }

  const estado = String(f.estado ?? '');
  if (estado === 'aberto' || estado === 'bloqueado') {
    onde.push('m.locked = :locked');
    params.locked = estado === 'bloqueado' ? 1 : 0;
  }
  if (estado === 'sem-ue') {
    onde.push("(m.unreal_level IS NULL OR m.unreal_level = '')");
  }

  const sqlOnde = onde.length === 0 ? '' : ' WHERE ' + onde.join(' AND ');

  const total = Number((await value('SELECT COUNT(*) FROM maps m' + sqlOnde, params)) ?? 0) | 0;

  const porPagina = Math.max(5, Math.min(100, Number(f.por_pagina ?? 20) | 0));
  const paginas = Math.max(1, Math.ceil(total / porPagina));
  const pagina = Math.max(1, Math.min(paginas, Number(f.pagina ?? 1) | 0));
  const offset = (pagina - 1) * porPagina;

  const chave = String(f.ordem ?? 'ordem');
  const molde = ORDENACOES[chave] ?? ORDENACOES.ordem!;
  const dir = String(f.dir ?? 'asc').toLowerCase() === 'desc' ? 'DESC' : 'ASC';
  // ORDER BY e LIMIT/OFFSET entram como literal: só chave de whitelist e inteiro saneado.
  const ordem = molde.replace(/%s/g, dir);

  const linhas = await all(
    'SELECT m.*, (SELECT COUNT(*) FROM map_markers k WHERE k.map_id = m.id) AS marcadores'
      + ' FROM maps m' + sqlOnde
      + ' ORDER BY ' + ordem
      + ' LIMIT ' + porPagina + ' OFFSET ' + offset,
    params,
  );

  for (const l of linhas) {
    l.meta_arr = decodificar(l.meta);
  }

  return { linhas: linhas as Dict[], total, pagina, paginas, por_pagina: porPagina };
}

/** Totais do catálogo inteiro — não do filtro. É o que a topbar mostra. */
export async function resumo(): Promise<Resumo> {
  const [r, mk] = await Promise.all([
    one(
      `SELECT COUNT(*) AS total,
              SUM(kind = 'region') AS regioes,
              SUM(kind = 'match')  AS partidas,
              SUM(locked = 1)      AS bloqueados,
              SUM(unreal_level IS NULL OR unreal_level = '') AS sem_ue
         FROM maps`,
    ),
    value('SELECT COUNT(*) FROM map_markers'),
  ]);
  const x = r ?? {};
  return {
    total: Number(x.total ?? 0) | 0,
    regioes: Number(x.regioes ?? 0) | 0,
    partidas: Number(x.partidas ?? 0) | 0,
    bloqueados: Number(x.bloqueados ?? 0) | 0,
    sem_ue: Number(x.sem_ue ?? 0) | 0,
    marcadores: Number(mk ?? 0) | 0,
  };
}

/** Linha crua + `meta_arr` decodificado + `marcadores` (contagem); null se não existe. */
export async function porId(id: number): Promise<Dict | null> {
  const row = await one('SELECT * FROM maps WHERE id = :id', { id });
  if (row === null) return null;
  row.meta_arr = decodificar(row.meta);
  row.marcadores = Number((await value('SELECT COUNT(*) FROM map_markers WHERE map_id = :m', { m: id })) ?? 0) | 0;
  return row as Dict;
}

/** Slug já em uso por OUTRO mapa (o próprio não conta, senão ninguém salva sem renomear). */
export async function slugEmUso(slug: string, exceto = 0): Promise<boolean> {
  const n = Number((await value('SELECT COUNT(*) FROM maps WHERE slug = :s AND id <> :id', { s: slug, id: exceto })) ?? 0);
  return n > 0;
}

/** Cria o mapa; devolve o id novo. */
export async function criar(d: Dict): Promise<number> {
  return insert('maps', somenteCampos(d));
}

export async function atualizar(id: number, d: Dict): Promise<void> {
  const campos = somenteCampos(d);
  const chaves = Object.keys(campos);
  if (chaves.length === 0) return;
  const sets = chaves.map((c) => `"${c}" = :${c}`);
  await run('UPDATE maps SET ' + sets.join(', ') + ' WHERE id = :id', { ...campos, id });
}

/** Apaga o mapa. Os marcadores caem junto (FK ON DELETE CASCADE + DELETE explícito, ver topo). */
export async function excluir(id: number): Promise<void> {
  await batch([
    prepare('DELETE FROM map_markers WHERE map_id = :id', { id }),
    prepare('DELETE FROM maps WHERE id = :id', { id }),
  ]);
}

/**
 * O que impede este mapa de ser apagado, em número.
 *
 * Marcador NÃO entra aqui: um marcador não existe fora do seu mapa e cai junto. O que trava
 * são as referências de FORA: bases governamentais fincadas nele, partidas já jogadas
 * (histórico do jogador) e safe zones do patch v4.
 *
 * @return rótulo interno => quantidade (só o que for > 0)
 */
export async function vinculos(id: number): Promise<Record<string, number>> {
  const checagens: Array<[string, string]> = [
    ['bases', 'SELECT COUNT(*) FROM government_bases WHERE map_id = :id'],
    ['partidas', 'SELECT COUNT(*) FROM player_matches WHERE map_id = :id'],
    ['safe', 'SELECT COUNT(*) FROM safe_zones WHERE map_id = :id'],
  ];

  const out: Record<string, number> = {};
  for (const [chave, sql] of checagens) {
    let n: number;
    try {
      n = Number((await value(sql, { id })) ?? 0) | 0;
    } catch {
      // Tabela de patch ainda não aplicado neste banco: ausência não é vínculo.
      continue;
    }
    if (n > 0) out[chave] = n;
  }
  return out;
}

/**
 * Lista enxuta para o SELETOR do editor de mapa: só o necessário para escolher em qual
 * mapa se vai trabalhar, e quanta coisa já existe nele.
 */
export async function paraEditor(): Promise<MapaEditor[]> {
  const rows = await all(
    'SELECT m.id, m.slug, m.name, m.kind, m.meta,'
      + ' (SELECT COUNT(*) FROM map_markers k WHERE k.map_id = m.id) AS marcadores'
      + ' FROM maps m ORDER BY m.kind DESC, m.sort_order, m.name COLLATE NOCASE',
  );
  return rows.map((r) => {
    const meta = decodificar(r.meta);
    return {
      id: Number(r.id) | 0,
      slug: String(r.slug ?? ''),
      name: String(r.name ?? ''),
      kind: String(r.kind ?? ''),
      marcadores: Number(r.marcadores ?? 0) | 0,
      world_image: String(meta.world_image ?? ''),
    };
  });
}

// ------------------------------------------------------------------------ marcadores

/** Marcadores do mapa, com `meta_arr` decodificado e x/y numéricos. */
export async function marcadores(mapId: number): Promise<Dict[]> {
  const rows = await all('SELECT * FROM map_markers WHERE map_id = :m ORDER BY kind, label, id', { m: mapId });
  for (const r of rows) {
    r.meta_arr = decodificar(r.meta);
    r.x = Number(r.x);
    r.y = Number(r.y);
  }
  return rows as Dict[];
}

export async function marcador(id: number): Promise<Dict | null> {
  const row = await one('SELECT * FROM map_markers WHERE id = :id', { id });
  if (row === null) return null;
  row.meta_arr = decodificar(row.meta);
  return row as Dict;
}

/** `d`: kind, label, x, y, meta (meta já em JSON ou null). Devolve o id novo. */
export async function criarMarcador(mapId: number, d: Dict): Promise<number> {
  return insert('map_markers', {
    map_id: mapId,
    kind: d.kind,
    label: d.label,
    x: d.x,
    y: d.y,
    meta: d.meta,
  });
}

export async function atualizarMarcador(id: number, d: Dict): Promise<void> {
  await run(
    'UPDATE map_markers SET kind = :kind, label = :label, x = :x, y = :y, meta = :meta WHERE id = :id',
    { kind: d.kind, label: d.label, x: d.x, y: d.y, meta: d.meta, id },
  );
}

export async function excluirMarcador(id: number): Promise<void> {
  await run('DELETE FROM map_markers WHERE id = :id', { id });
}

/**
 * Tipos de marcador que EXISTEM no banco. O formulário mostra o catálogo canônico
 * (dataMock('admin-mapas')) unido a isto: um tipo criado por patch ou por outra ferramenta
 * não pode sumir do <select> só porque o painel não o conhecia.
 */
export async function tiposDeMarcadorEmUso(): Promise<string[]> {
  const rows = await all('SELECT DISTINCT kind FROM map_markers ORDER BY kind');
  return rows.map((r) => String(r.kind ?? ''));
}

// ------------------------------------------------------------------------ internos

function somenteCampos(d: Dict): Dict {
  const out: Dict = {};
  for (const c of CAMPOS) {
    if (tem(d, c)) out[c] = d[c];
  }
  return out;
}

/** JSON inválido ou nulo vira objeto vazio, nunca erro de tela. */
function decodificar(json: unknown): Dict {
  if (json !== null && typeof json === 'object') return json as Dict;
  if (json === null || json === undefined || json === '') return {};
  try {
    const v: unknown = JSON.parse(String(json));
    return v !== null && typeof v === 'object' ? (v as Dict) : {};
  } catch {
    return {};
  }
}
