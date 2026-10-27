/**
 * Tudo o que a tela /admin/eventos lê e escreve — o App\Repo\EventsRepo do PHP. Três catálogos
 * que o operador governa:
 *
 *   `challenges` + `challenge_bonuses` — os desafios da tela /desafios do jogo
 *   `news`                             — as notícias da HUD
 *   `system_notices`                   — os avisos do painel "MENSAGENS DO SISTEMA"
 *
 * Aqui vive TODO o SQL da tela. O controller decide o que perguntar, a view desenha; nenhum
 * dos dois monta consulta.
 *
 * Duas regras que valem para o arquivo inteiro (iguais ao PHP):
 * 1. LIMIT/OFFSET entram por interpolação de INTEIRO já saneado; todo o resto é parâmetro ligado.
 * 2. Nome de coluna em ORDER BY nunca vem cru da URL: a ordenação é escolhida dentro de um
 *    mapa fechado (ORDENS_*), e o que não estiver no mapa cai no padrão.
 *
 * DIFERENÇAS PARA O MySQL (D1/SQLite):
 *   - `FIELD(c.scope, ...)` vira CASE; `CHAR_LENGTH` vira `length` (que no SQLite conta
 *     caracteres de TEXT, não bytes); aspas duplas de literal viram simples.
 *   - LIKE: o SQLite não tem `\` como escape padrão — vai `ESCAPE '\'` explícito. E o LIKE do
 *     SQLite ignora caixa só em ASCII (o utf8mb4_unicode_ci ignorava caixa E acento).
 *   - Ordenação por texto (título, categoria): COLLATE NOCASE para chegar perto da collation
 *     do MySQL (acentos ainda ordenam pelo código).
 *   - `CURDATE()` do filtro "com data futura" era a data do servidor MySQL (São Paulo). O
 *     `date('now')` do SQLite é UTC — a data de hoje vai como parâmetro, calculada em SP
 *     (core/relogio.ts), para a virada do dia acontecer à mesma hora que no PHP.
 *   - `system_notices.created_at`: o DEFAULT do MySQL gravava a hora de SP; o
 *     `datetime('now')` do D1 gravaria UTC. O INSERT manda a hora de SP explícita (como
 *     repo/message-repo.ts), para o aviso novo ficar no mesmo fuso das linhas que vieram do MySQL.
 *   - `static` de cache: bonus() é memoizado na requisição (memo), porque a tela lê a mesma
 *     lista duas vezes (painel de bônus + escoposSemBonus).
 */
import { memo } from '../core/context';
import { all, insert, one, run, value } from '../core/database';
import { date } from '../core/fuso';
import { ymd } from '../core/relogio';
import type { Dict } from '../core/view';

/** Abas de desafio aceitas — as mesmas de ChallengeRepo::SCOPES, que é quem lê no jogo. */
export const ESCOPOS = ['diarios', 'semanais', 'temporada'];

/**
 * Cores de aviso que o JOGO sabe pintar (assets/css/pages/mensagens.css). Lista fechada: uma
 * cor fora dela sairia lime na HUD, e o painel prometeria um destaque que ninguém veria.
 */
export const CORES_AVISO = ['lime', 'gold', 'amber', 'red', 'cyan'];

/** Quantas linhas por página em qualquer uma das três abas. */
export const POR_PAGINA = 12;

/** Ordem canônica dos escopos (o FIELD() do MySQL). */
const ORDEM_ESCOPO = (col: string): string =>
  `CASE ${col} WHEN 'diarios' THEN 1 WHEN 'semanais' THEN 2 WHEN 'temporada' THEN 3 ELSE 0 END`;

/**
 * ORDER BY permitido em cada aba: chave da URL => expressão SQL (sem direção).
 * A chave `_id` de cada mapa é o desempate final, nunca oferecido na interface.
 * (No MySQL, FIELD() devolve 0 para valor fora da lista — o ELSE 0 do CASE é o mesmo.)
 */
const ORDENS_DESAFIOS: Record<string, string> = {
  ordem: ORDEM_ESCOPO('c.scope') + ', c.sort_order, c.id',
  nome: 'c.label COLLATE NOCASE',
  xp: 'c.xp',
  cred: 'c.credits',
  alvo: 'c.target',
  jog: 'jogadores',
  _id: 'c.id',
};
const ORDENS_NOTICIAS: Record<string, string> = {
  data: 'n.published IS NULL, n.published',
  ordem: 'n.sort_order, n.id',
  nome: 'n.title COLLATE NOCASE',
  cat: 'n.category IS NULL, n.category COLLATE NOCASE',
  _id: 'n.id',
};
const ORDENS_AVISOS: Record<string, string> = {
  criado: 'a.created_at',
  ordem: 'a.user_id IS NOT NULL, a.sort_order, a.id',
  nome: 'a.title COLLATE NOCASE',
  dest: 'a.user_id IS NOT NULL, u.username',
  _id: 'a.id',
};

/** Uma página de qualquer uma das três listas. */
export interface Lista {
  linhas: Dict[];
  total: number;
  paginas: number;
  pagina: number;
}

const int = (v: unknown): number => Number(v ?? 0) | 0;

// =====================================================================================
// CONTAGENS DE CABEÇALHO
// =====================================================================================

/** Os números das abas. Uma consulta, todas de COUNT. */
export async function resumo(): Promise<Record<string, number>> {
  const n =
    (await one<Dict>(
      `SELECT (SELECT COUNT(*) FROM challenges)                               AS desafios,
              (SELECT COUNT(*) FROM challenges WHERE highlight = 1)           AS destacados,
              (SELECT COUNT(*) FROM challenge_bonuses)                        AS bonus,
              (SELECT COUNT(*) FROM news)                                     AS noticias,
              (SELECT COUNT(*) FROM news WHERE published IS NOT NULL)         AS publicadas,
              (SELECT COUNT(*) FROM news WHERE published IS NULL)             AS rascunhos,
              (SELECT COUNT(*) FROM system_notices)                           AS avisos,
              (SELECT COUNT(*) FROM system_notices WHERE user_id IS NULL)     AS globais`,
    )) ?? {};

  return {
    desafios: int(n.desafios),
    destacados: int(n.destacados),
    bonus: int(n.bonus),
    noticias: int(n.noticias),
    publicadas: int(n.publicadas),
    rascunhos: int(n.rascunhos),
    avisos: int(n.avisos),
    globais: int(n.globais),
  };
}

// =====================================================================================
// DESAFIOS
// =====================================================================================

/** Página de desafios já filtrada, ordenada e contada (com `jogadores`/`concluidos`). */
export async function desafios(f: Dict = {}): Promise<Lista> {
  const [where, p] = filtroDesafios(f);
  const total = int(await value('SELECT COUNT(*) FROM challenges c' + where, p));
  const [pagina, paginas, offset] = janela(total, int(f.pagina ?? 1));

  const linhas = await all<Dict>(
    `SELECT c.id, c.slug, c.scope, c.season_id, c.label, c.description, c.icon,
            c.target, c.xp, c.credits, c.highlight, c.sort_order,
            s.tag AS temporada,
            (SELECT COUNT(*) FROM player_challenges pc WHERE pc.challenge_id = c.id) AS jogadores,
            (SELECT COUNT(*) FROM player_challenges pc
              WHERE pc.challenge_id = c.id AND pc.completed_at IS NOT NULL)          AS concluidos
       FROM challenges c
  LEFT JOIN seasons s ON s.id = c.season_id` +
      where +
      ordenar(ORDENS_DESAFIOS, f, 'ordem') +
      ` LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    p,
  );

  return { linhas, total, paginas, pagina };
}

function filtroDesafios(f: Dict): [string, Dict] {
  const cond: string[] = [];
  const p: Dict = {};
  const q = String(f.q ?? '').trim();
  if (q !== '') {
    cond.push(`(c.label LIKE :q1 ESCAPE '\\' OR c.slug LIKE :q2 ESCAPE '\\' OR c.description LIKE :q3 ESCAPE '\\')`);
    p.q1 = p.q2 = p.q3 = '%' + escaparLike(q) + '%';
  }
  const escopo = String(f.escopo ?? '');
  if (ESCOPOS.includes(escopo)) {
    cond.push('c.scope = :escopo');
    p.escopo = escopo;
  } else if (escopo === 'destaque') {
    cond.push('c.highlight = 1');
  }
  return [cond.length === 0 ? '' : ' WHERE ' + cond.join(' AND '), p];
}

export function desafio(id: number): Promise<Dict | null> {
  return one<Dict>('SELECT * FROM challenges WHERE id = :id', { id });
}

/** Quantos jogadores têm progresso gravado neste desafio — é o que bloqueia a exclusão. */
export async function progressoDoDesafio(id: number): Promise<number> {
  return int(await value('SELECT COUNT(*) FROM player_challenges WHERE challenge_id = :id', { id }));
}

export async function slugDesafioEmUso(slug: string, exceto = 0): Promise<boolean> {
  return int(await value('SELECT COUNT(*) FROM challenges WHERE slug = :s AND id <> :id', { s: slug, id: exceto })) > 0;
}

/** @return id gravado */
export async function salvarDesafio(d: Dict, id = 0): Promise<number> {
  if (id > 0) {
    await run(
      `UPDATE challenges
          SET slug = :slug, scope = :scope, season_id = :season_id, label = :label,
              description = :description, icon = :icon, target = :target, xp = :xp,
              credits = :credits, highlight = :highlight, sort_order = :sort_order
        WHERE id = :id`,
      { ...d, id },
    );
    return id;
  }
  return insert('challenges', d);
}

export async function excluirDesafio(id: number): Promise<void> {
  await run('DELETE FROM challenges WHERE id = :id', { id });
}

// ---- bônus por escopo ---------------------------------------------------------------

/** Os bônus: um por aba do jogo (o escopo é a chave primária). Sem busca nem paginação. */
export function bonus(): Promise<Dict[]> {
  return memo('admin-events.bonus', () =>
    all<Dict>(
      `SELECT b.*, (SELECT COUNT(*) FROM challenges c WHERE c.scope = b.scope) AS desafios
         FROM challenge_bonuses b
        ORDER BY ${ORDEM_ESCOPO('b.scope')}, b.scope`,
    ),
  );
}

export function bonusDoEscopo(escopo: string): Promise<Dict | null> {
  return one<Dict>('SELECT * FROM challenge_bonuses WHERE scope = :s', { s: escopo });
}

export async function salvarBonus(d: Dict, escopoOriginal = ''): Promise<void> {
  if (escopoOriginal !== '' && (await bonusDoEscopo(escopoOriginal)) !== null) {
    // O escopo é a chave primária: editar não pode trocá-lo (a aba do jogo é ele).
    await run(
      `UPDATE challenge_bonuses
          SET label = :label, title = :title, description = :description,
              image = :image, xp = :xp, credits = :credits
        WHERE scope = :scope`,
      { ...d, scope: escopoOriginal },
    );
    return;
  }
  await insert('challenge_bonuses', d);
}

export async function excluirBonus(escopo: string): Promise<void> {
  await run('DELETE FROM challenge_bonuses WHERE scope = :s', { s: escopo });
}

/** Escopos que ainda NÃO têm bônus — é o que o formulário de criação pode oferecer. */
export async function escoposSemBonus(): Promise<string[]> {
  const tem = new Set((await bonus()).map((b) => String(b.scope)));
  return ESCOPOS.filter((e) => !tem.has(e));
}

// =====================================================================================
// NOTÍCIAS
// =====================================================================================

export async function noticias(f: Dict = {}): Promise<Lista> {
  const [where, p] = filtroNoticias(f);
  const total = int(await value('SELECT COUNT(*) FROM news n' + where, p));
  const [pagina, paginas, offset] = janela(total, int(f.pagina ?? 1));

  const linhas = await all<Dict>(
    `SELECT n.id, n.slug, n.title, n.category, n.excerpt, n.image, n.published, n.sort_order,
            length(COALESCE(n.body, '')) AS tamanho_corpo,
            (SELECT COUNT(*) FROM maintenance_windows mw WHERE mw.news_slug = n.slug) AS janelas
       FROM news n` +
      where +
      ordenar(ORDENS_NOTICIAS, f, 'data', 'DESC') +
      ` LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    p,
  );

  return { linhas, total, paginas, pagina };
}

function filtroNoticias(f: Dict): [string, Dict] {
  const cond: string[] = [];
  const p: Dict = {};
  const q = String(f.q ?? '').trim();
  if (q !== '') {
    cond.push(`(n.title LIKE :q1 ESCAPE '\\' OR n.slug LIKE :q2 ESCAPE '\\' OR n.excerpt LIKE :q3 ESCAPE '\\')`);
    p.q1 = p.q2 = p.q3 = '%' + escaparLike(q) + '%';
  }
  const estado = String(f.estado ?? '');
  if (estado === 'publicada') {
    cond.push('n.published IS NOT NULL');
  } else if (estado === 'rascunho') {
    cond.push('n.published IS NULL');
  } else if (estado === 'futura') {
    // CURDATE() do MySQL (São Paulo): a data de hoje vai calculada em SP, não o date('now') UTC.
    cond.push('n.published > :hoje');
    p.hoje = ymd();
  }
  const cat = String(f.cat ?? '').trim();
  if (cat !== '') {
    cond.push('n.category = :cat');
    p.cat = cat;
  }
  return [cond.length === 0 ? '' : ' WHERE ' + cond.join(' AND '), p];
}

/** Categorias que EXISTEM no banco — o filtro nunca oferece uma opção que dá zero. */
export async function categoriasDeNoticia(): Promise<string[]> {
  return (
    await all<Dict>(
      "SELECT DISTINCT category FROM news WHERE category IS NOT NULL AND category <> '' ORDER BY category COLLATE NOCASE",
    )
  ).map((r) => String(r.category));
}

export function noticia(id: number): Promise<Dict | null> {
  return one<Dict>('SELECT * FROM news WHERE id = :id', { id });
}

export async function slugNoticiaEmUso(slug: string, exceto = 0): Promise<boolean> {
  return int(await value('SELECT COUNT(*) FROM news WHERE slug = :s AND id <> :id', { s: slug, id: exceto })) > 0;
}

/** Janelas de manutenção que apontam para este slug — é o que bloqueia a exclusão. */
export async function janelasDaNoticia(slug: string): Promise<number> {
  return int(await value('SELECT COUNT(*) FROM maintenance_windows WHERE news_slug = :s', { s: slug }));
}

/** @return id gravado */
export async function salvarNoticia(d: Dict, id = 0): Promise<number> {
  if (id > 0) {
    await run(
      `UPDATE news
          SET slug = :slug, title = :title, category = :category, excerpt = :excerpt,
              body = :body, image = :image, published = :published, sort_order = :sort_order
        WHERE id = :id`,
      { ...d, id },
    );
    return id;
  }
  return insert('news', d);
}

export async function excluirNoticia(id: number): Promise<void> {
  await run('DELETE FROM news WHERE id = :id', { id });
}

// =====================================================================================
// AVISOS DO SISTEMA
// =====================================================================================

export async function avisos(f: Dict = {}): Promise<Lista> {
  const [where, p] = filtroAvisos(f);
  const total = int(await value('SELECT COUNT(*) FROM system_notices a LEFT JOIN users u ON u.id = a.user_id' + where, p));
  const [pagina, paginas, offset] = janela(total, int(f.pagina ?? 1));

  const linhas = await all<Dict>(
    `SELECT a.id, a.user_id, a.icon, a.color, a.title, a.body, a.cta_label, a.cta_href,
            a.fresh, a.created_at, a.sort_order, u.username
       FROM system_notices a
  LEFT JOIN users u ON u.id = a.user_id` +
      where +
      ordenar(ORDENS_AVISOS, f, 'criado', 'DESC') +
      ` LIMIT ${POR_PAGINA} OFFSET ${offset}`,
    p,
  );

  return { linhas, total, paginas, pagina };
}

function filtroAvisos(f: Dict): [string, Dict] {
  const cond: string[] = [];
  const p: Dict = {};
  const q = String(f.q ?? '').trim();
  if (q !== '') {
    cond.push(`(a.title LIKE :q1 ESCAPE '\\' OR a.body LIKE :q2 ESCAPE '\\' OR u.username LIKE :q3 ESCAPE '\\')`);
    p.q1 = p.q2 = p.q3 = '%' + escaparLike(q) + '%';
  }
  const destino = String(f.destino ?? '');
  if (destino === 'global') {
    cond.push('a.user_id IS NULL');
  } else if (destino === 'jogador') {
    cond.push('a.user_id IS NOT NULL');
  } else if (destino === 'novo') {
    cond.push('a.fresh = 1');
  }
  return [cond.length === 0 ? '' : ' WHERE ' + cond.join(' AND '), p];
}

export function aviso(id: number): Promise<Dict | null> {
  return one<Dict>(
    `SELECT a.*, u.username FROM system_notices a
  LEFT JOIN users u ON u.id = a.user_id
      WHERE a.id = :id`,
    { id },
  );
}

/** @return id gravado */
export async function salvarAviso(d: Dict, id = 0): Promise<number> {
  if (id > 0) {
    await run(
      `UPDATE system_notices
          SET user_id = :user_id, icon = :icon, color = :color, title = :title,
              body = :body, cta_label = :cta_label, cta_href = :cta_href,
              fresh = :fresh, sort_order = :sort_order
        WHERE id = :id`,
      { ...d, id },
    );
    return id;
  }
  // created_at explícito na hora de SP: era o DEFAULT CURRENT_TIMESTAMP do MySQL (ver topo).
  return insert('system_notices', { ...d, created_at: date('Y-m-d H:i:s') });
}

export async function excluirAviso(id: number): Promise<void> {
  await run('DELETE FROM system_notices WHERE id = :id', { id });
}

/** Resolve o destinatário digitado (username, sem diferenciar maiúsculas: a coluna é NOCASE). */
export function jogadorPorNome(username: string): Promise<Dict | null> {
  return one<Dict>('SELECT id, username, status FROM users WHERE username = :u LIMIT 1', { u: username });
}

/** Sugestões do campo de destinatário (`<datalist>`). O nome é sempre validado no servidor. */
export async function sugestoesDeJogador(limite = 50): Promise<string[]> {
  const n = Math.max(1, Math.min(200, limite | 0));
  return (
    await all<Dict>(`SELECT username FROM users ORDER BY last_login IS NULL, last_login DESC, id DESC LIMIT ${n}`)
  ).map((r) => String(r.username));
}

// =====================================================================================
// APOIO
// =====================================================================================

/** Temporadas para o seletor do desafio de escopo `temporada`. */
export function temporadas(): Promise<Dict[]> {
  return all<Dict>('SELECT id, tag, pass_name, is_current FROM seasons ORDER BY number DESC, id DESC');
}

export async function temporadaExiste(id: number): Promise<boolean> {
  return int(await value('SELECT COUNT(*) FROM seasons WHERE id = :id', { id })) > 0;
}

/** Página pedida x página que existe: [pagina, paginas, offset]. */
function janela(total: number, pedida: number): [number, number, number] {
  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  const pagina = Math.max(1, Math.min(paginas, pedida > 0 ? pedida : 1));
  return [pagina, paginas, (pagina - 1) * POR_PAGINA];
}

/** ORDER BY a partir de um mapa fechado; direção reduzida a ASC/DESC. */
function ordenar(mapa: Record<string, string>, f: Dict, padrao: string, dirPadrao = 'ASC'): string {
  let chave = String(f.ord ?? '');
  let dir: string;
  if (chave.startsWith('_') || !Object.prototype.hasOwnProperty.call(mapa, chave)) {
    chave = padrao;
    dir = dirPadrao;
  } else {
    dir = String(f.dir ?? dirPadrao).toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
  }
  // O id entra como desempate final (linhas de mesmo sort_order não trocam de página).
  return ' ORDER BY ' + mapa[chave] + ' ' + dir + ', ' + mapa._id + ' ASC';
}

/** `%` e `_` digitados na busca são LITERAIS, não curingas. */
function escaparLike(termo: string): string {
  return termo.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_');
}
