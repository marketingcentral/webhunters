/**
 * Infraestrutura declarada do jogo: `server_regions`, `server_services` e `maintenance_windows`
 * — o App\Repo\ServersRepo do PHP (menos `rodape()`, que mora em repo/settings-repo.ts).
 *
 * Todo o SQL da tela /admin/servidores mora aqui — o controller só decide o que pedir e a view
 * só desenha.
 *
 * ATENÇÃO AO ALCANCE DA ESCRITA: estas três tabelas são lidas também pela tela /suporte do jogo
 * (SupportRepo) e pela caixa de mensagens (MessageRepo). Mudar o estado de um serviço aqui muda o
 * que TODO jogador vê — por isso cada gravação é auditada (no controller).
 *
 * O vínculo entre janela e região é por RÓTULO (`maintenance_windows.region` guarda o texto de
 * `server_regions.label`), e não por id:
 *   1. renomear uma região órfã as janelas dela  -> renomearRegiaoNasJanelas() acompanha o rótulo;
 *   2. apagar uma região deixaria janelas apontando para o nada -> janelasDaRegiao() bloqueia.
 *
 * DATAS: mesma convenção de repo/admin-repo.ts — a coluna DATETIME é lida como UTC (paraUnix) e
 * comparada com `datetime('now')` (UTC). Assim esta tela e o dashboard concordam sobre o que é
 * "janela ativa".
 */
import * as DB from '../core/database';
import type { Dict } from '../core/view';
import { agoraUnix, corta, formatarData, paraUnix } from './admin-repo';

/** Estados gravados na coluna `state` das duas tabelas de infraestrutura. */
export const ESTADOS: Record<string, string> = {
  ok: 'Operacional',
  warn: 'Instável',
  down: 'Fora do ar',
};

/** Situações DERIVADAS de uma janela — não existem no banco, saem da comparação com agora. */
export const SITUACOES: Record<string, string> = {
  ativa: 'Em andamento',
  agendada: 'Agendada',
  encerrada: 'Encerrada',
};

/** Janelas por página. A lista cresce para sempre (manutenção é recorrente). */
export const POR_PAGINA = 8;

/** FIELD(state,'down','warn','ok') do MySQL. */
const ORDEM_ESTADO = `CASE state WHEN 'down' THEN 1 WHEN 'warn' THEN 2 WHEN 'ok' THEN 3 ELSE 0 END`;

/**
 * Colunas pelas quais a lista de regiões pode ser ordenada. Lista fechada: o valor vem da URL
 * e entra CONCATENADO no ORDER BY. Fora desta lista, cai no padrão.
 */
const ORDEM_REGIOES: Record<string, string> = {
  ordem: 'sort_order, id',
  regiao: 'label',
  ping: 'ping_ms',
  jogadores: 'players',
  estado: `${ORDEM_ESTADO}, label`,
};

/** Mesma ideia para os serviços. */
const ORDEM_SERVICOS: Record<string, string> = {
  ordem: 'sort_order, id',
  servico: 'label',
  estado: `${ORDEM_ESTADO}, label`,
  janela: 'maint_starts_at IS NULL, maint_starts_at',
};

export interface Filtro {
  q?: string;
  estado?: string;
  ord?: string;
  dir?: string;
}

// =====================================================================================
//  REGIÕES
// =====================================================================================

/**
 * Regiões já com o estado EFETIVO — o mesmo que o dashboard mostra (regra copiada de
 * AdminRepo.statusServidores de propósito: as duas telas não podem divergir).
 */
export async function regioes(filtro: Filtro = {}): Promise<Dict[]> {
  const q = String(filtro.q ?? '').trim();
  const estado = String(filtro.estado ?? '');
  const ordem = ORDEM_REGIOES[String(filtro.ord ?? '')] ?? ORDEM_REGIOES.ordem!;
  const dir = String(filtro.dir ?? 'asc').toLowerCase() === 'desc' ? 'DESC' : 'ASC';

  const onde: string[] = [];
  const params: DB.Params = {};
  if (q !== '') {
    onde.push(`(label LIKE :q1 ESCAPE '\\' OR slug LIKE :q2 ESCAPE '\\')`);
    params.q1 = params.q2 = '%' + escLike(q) + '%';
  }
  if (estado in ESTADOS) {
    onde.push('state = :st');
    params.st = estado;
  }

  const sql =
    'SELECT id, slug, label, ping_ms, players, state, sort_order FROM server_regions' +
    (onde.length === 0 ? '' : ' WHERE ' + onde.join(' AND ')) +
    ' ORDER BY ' + ordem + ' ' + dir;

  const [linhas, janelas] = await Promise.all([DB.all<Dict>(sql, params), janelasPorRegiao()]);
  let pico = 0;
  for (const r of linhas) pico = Math.max(pico, Number(r.players) | 0);

  return linhas.map((r) => {
    const label = String(r.label);
    const manut = janelas[label] ?? null;
    let efetivo: string, rotulo: string, nota: string | null;
    if (manut !== null && manut.ativa) [efetivo, rotulo, nota] = ['manutencao', 'Em manutenção', 'Janela ativa até ' + formatarData(manut.quando, 'd/m H:i')];
    else if (String(r.state) === 'down') [efetivo, rotulo, nota] = ['fora', 'Fora do ar', null];
    else if (String(r.state) === 'warn') [efetivo, rotulo, nota] = ['instavel', 'Instável', null];
    else if (manut !== null) [efetivo, rotulo, nota] = ['agendada', 'Manut. agendada', 'Janela em ' + formatarData(manut.quando, 'd/m H:i')];
    else [efetivo, rotulo, nota] = ['online', 'Online', null];

    const jog = Number(r.players) | 0;
    return {
      id: Number(r.id) | 0,
      slug: String(r.slug),
      label,
      ping: Number(r.ping_ms) | 0,
      jogadores: jog,
      state: String(r.state),
      ordem: Number(r.sort_order) | 0,
      estado: efetivo,
      estado_rotulo: rotulo,
      nota,
      ocupacao: pico > 0 ? jog / pico : 0.0,
      janelas: manut?.n ?? 0,
    };
  });
}

/** Linha crua da região (o formulário edita o que está gravado). */
export async function regiao(id: number): Promise<Dict | null> {
  return id > 0 ? DB.one('SELECT * FROM server_regions WHERE id = :id LIMIT 1', { id }) : null;
}

/** Rótulos existentes — é a lista do seletor de região da janela. */
export async function rotulosRegioes(): Promise<string[]> {
  return (await DB.all<Dict>('SELECT label FROM server_regions ORDER BY sort_order, id')).map((r) => String(r.label));
}

// =====================================================================================
//  SERVIÇOS
// =====================================================================================

export async function servicos(filtro: Filtro = {}): Promise<Dict[]> {
  const q = String(filtro.q ?? '').trim();
  const estado = String(filtro.estado ?? '');
  const ordem = ORDEM_SERVICOS[String(filtro.ord ?? '')] ?? ORDEM_SERVICOS.ordem!;
  const dir = String(filtro.dir ?? 'asc').toLowerCase() === 'desc' ? 'DESC' : 'ASC';

  const onde: string[] = [];
  const params: DB.Params = {};
  if (q !== '') {
    onde.push(`(label LIKE :q1 ESCAPE '\\' OR slug LIKE :q2 ESCAPE '\\' OR maint_scope LIKE :q3 ESCAPE '\\')`);
    params.q1 = params.q2 = params.q3 = '%' + escLike(q) + '%';
  }
  if (estado in ESTADOS) {
    onde.push('state = :st');
    params.st = estado;
  }

  const sql =
    `SELECT id, slug, label, state, status_label, short_label, maint_scope,
            maint_starts_at, maint_minutes, sort_order
       FROM server_services` +
    (onde.length === 0 ? '' : ' WHERE ' + onde.join(' AND ')) +
    ' ORDER BY ' + ordem + ' ' + dir;

  const agora = agoraUnix();
  return (await DB.all<Dict>(sql, params)).map((r) => {
    const inicio = r.maint_starts_at !== null && r.maint_starts_at !== undefined ? paraUnix(r.maint_starts_at) : 0;
    const min = Number(r.maint_minutes ?? 0) | 0;
    const state = String(r.state);
    return {
      id: Number(r.id) | 0,
      slug: String(r.slug),
      label: String(r.label),
      state,
      estado: state === 'ok' ? 'online' : state === 'down' ? 'fora' : 'manutencao',
      rotulo: String(r.status_label),
      curto: String(r.short_label),
      escopo: String(r.maint_scope ?? '') || null,
      inicio,
      minutos: min > 0 ? min : null,
      ordem: Number(r.sort_order) | 0,
      // Só o serviço FORA do estado ok e com horário marcado vira contagem regressiva na
      // tela do jogador (SupportRepo.maintenance) — a coluna repete essa condição.
      no_contador: state !== 'ok' && inicio > 0,
      faltam: inicio > 0 ? inicio - agora : null,
    };
  });
}

export async function servico(id: number): Promise<Dict | null> {
  return id > 0 ? DB.one('SELECT * FROM server_services WHERE id = :id LIMIT 1', { id }) : null;
}

// =====================================================================================
//  JANELAS DE MANUTENÇÃO
// =====================================================================================

/**
 * Página da lista de janelas (paginada: o histórico só cresce). A situação é derivada do
 * relógio, então o filtro vira comparação com datetime('now') no SQL — é o que mantém o
 * total honesto.
 */
export async function janelas(filtro: { q?: string; situacao?: string; pagina?: number } = {}): Promise<Dict> {
  const q = String(filtro.q ?? '').trim();
  const situacao = String(filtro.situacao ?? '');
  let pagina = Math.max(1, Math.trunc(Number(filtro.pagina ?? 1)) || 1);

  const onde: string[] = [];
  const params: DB.Params = {};
  if (q !== '') {
    onde.push(`(region LIKE :q1 ESCAPE '\\' OR note LIKE :q2 ESCAPE '\\')`);
    params.q1 = params.q2 = '%' + escLike(q) + '%';
  }
  onde.push(
    situacao === 'ativa'
      ? `starts_at <= datetime('now') AND ends_at >= datetime('now')`
      : situacao === 'agendada'
        ? `starts_at > datetime('now')`
        : situacao === 'encerrada'
          ? `ends_at < datetime('now')`
          : '1 = 1',
  );

  const sqlOnde = ' WHERE ' + onde.join(' AND ');
  const total = Number(await DB.value('SELECT COUNT(*) FROM maintenance_windows' + sqlOnde, params)) | 0;
  const paginas = Math.max(1, Math.ceil(total / POR_PAGINA));
  pagina = Math.min(pagina, paginas);
  const offset = (pagina - 1) * POR_PAGINA;

  const [linhas, conhecidas] = await Promise.all([
    DB.all<Dict>(
      `SELECT id, starts_at, ends_at, region, note, news_slug
         FROM maintenance_windows${sqlOnde}
        ORDER BY starts_at DESC
        LIMIT ${POR_PAGINA} OFFSET ${offset}`,
      params,
    ),
    rotulosRegioes(),
  ]);

  const agora = agoraUnix();
  const saida = linhas.map((r) => {
    const ini = paraUnix(r.starts_at);
    const fim = paraUnix(r.ends_at);
    const regiao = String(r.region);
    return {
      id: Number(r.id) | 0,
      inicio: ini,
      fim,
      minutos: Math.round(Math.max(0, fim - ini) / 60),
      regiao,
      nota: String(r.note ?? '') || null,
      noticia: String(r.news_slug ?? '') || null,
      situacao: ini <= agora && agora <= fim ? 'ativa' : ini > agora ? 'agendada' : 'encerrada',
      // Região que não existe mais em server_regions: a janela continua avisando o jogador,
      // mas não pinta região nenhuma. Defeito silencioso — a tabela precisa gritar.
      orfa: !conhecidas.includes(regiao),
    };
  });

  return {
    linhas: saida,
    total,
    pagina,
    paginas,
    de: total === 0 ? 0 : offset + 1,
    ate: Math.min(total, offset + POR_PAGINA),
  };
}

export async function janela(id: number): Promise<Dict | null> {
  return id > 0 ? DB.one('SELECT * FROM maintenance_windows WHERE id = :id LIMIT 1', { id }) : null;
}

/** Janela do mesmo período e da mesma região (a conflitante, quando existir). */
export async function janelaSobreposta(regiao: string, inicio: string, fim: string, exceto = 0): Promise<Dict | null> {
  return DB.one(
    `SELECT id, starts_at, ends_at FROM maintenance_windows
      WHERE region = :r AND id <> :ex AND starts_at < :fim AND ends_at > :ini
      ORDER BY starts_at LIMIT 1`,
    { r: regiao, ex: exceto, ini: inicio, fim },
  );
}

/** Quantas janelas apontam para este rótulo de região — é o bloqueio da exclusão. */
export async function janelasDaRegiao(label: string): Promise<number> {
  return Number(await DB.value('SELECT COUNT(*) FROM maintenance_windows WHERE region = :r', { r: label })) | 0;
}

/** Notícias, para o seletor de `news_slug`. */
export async function noticias(): Promise<Array<{ slug: string; title: string }>> {
  return (await DB.all<Dict>('SELECT slug, title FROM news ORDER BY sort_order, id')).map((r) => ({
    slug: String(r.slug),
    title: String(r.title),
  }));
}

// =====================================================================================
//  ESCRITA
// =====================================================================================

/** Grava uma região (id = 0 cria). Devolve o id. */
export async function salvarRegiao(d: Dict, id = 0): Promise<number> {
  const campos = {
    slug: d.slug,
    label: d.label,
    ping_ms: d.ping_ms,
    players: d.players,
    state: d.state,
    sort_order: d.sort_order,
  };
  if (id <= 0) return DB.insert('server_regions', campos);
  await DB.run(
    `UPDATE server_regions
        SET slug = :slug, label = :label, ping_ms = :ping_ms, players = :players,
            state = :state, sort_order = :sort_order
      WHERE id = :id`,
    { ...campos, id },
  );
  return id;
}

/** Grava um serviço (id = 0 cria). */
export async function salvarServico(d: Dict, id = 0): Promise<number> {
  const campos = {
    slug: d.slug,
    label: d.label,
    state: d.state,
    status_label: d.status_label,
    short_label: d.short_label,
    maint_scope: d.maint_scope,
    maint_starts_at: d.maint_starts_at,
    maint_minutes: d.maint_minutes,
    sort_order: d.sort_order,
  };
  if (id <= 0) return DB.insert('server_services', campos);
  await DB.run(
    `UPDATE server_services
        SET slug = :slug, label = :label, state = :state, status_label = :status_label,
            short_label = :short_label, maint_scope = :maint_scope,
            maint_starts_at = :maint_starts_at, maint_minutes = :maint_minutes,
            sort_order = :sort_order
      WHERE id = :id`,
    { ...campos, id },
  );
  return id;
}

/** Grava uma janela (id = 0 cria). */
export async function salvarJanela(d: Dict, id = 0): Promise<number> {
  const campos = {
    starts_at: d.starts_at,
    ends_at: d.ends_at,
    region: d.region,
    note: d.note,
    news_slug: d.news_slug,
  };
  if (id <= 0) return DB.insert('maintenance_windows', campos);
  await DB.run(
    `UPDATE maintenance_windows
        SET starts_at = :starts_at, ends_at = :ends_at, region = :region,
            note = :note, news_slug = :news_slug
      WHERE id = :id`,
    { ...campos, id },
  );
  return id;
}

/** Tabela de cada tipo — escolhida por lista fechada, nunca concatenada do pedido. */
function tabelaDe(tipo: string, comJanela: boolean): string | null {
  if (tipo === 'regiao') return 'server_regions';
  if (tipo === 'servico') return 'server_services';
  if (tipo === 'janela' && comJanela) return 'maintenance_windows';
  return null;
}

/** Só o `state` de uma região ou de um serviço — o botão de estado da própria linha. */
export async function trocarEstado(tipo: string, id: number, state: string): Promise<boolean> {
  const tabela = tabelaDe(tipo, false);
  if (tabela === null || id <= 0 || !(state in ESTADOS)) return false;
  await DB.run(`UPDATE ${tabela} SET state = :st WHERE id = :id`, { st: state, id });
  return true;
}

/** Renomear a região traz as janelas junto (o rótulo é a chave delas). */
export async function renomearRegiaoNasJanelas(de: string, para: string): Promise<number> {
  if (de === para || de === '') return 0;
  return DB.changes(await DB.run('UPDATE maintenance_windows SET region = :para WHERE region = :de', { para, de }));
}

export async function excluir(tipo: string, id: number): Promise<boolean> {
  const tabela = tabelaDe(tipo, true);
  if (tabela === null || id <= 0) return false;
  return DB.changes(await DB.run(`DELETE FROM ${tabela} WHERE id = :id`, { id })) > 0;
}

/** O slug já pertence a outra linha da mesma tabela? (UNIQUE no banco; erro legível aqui.) */
export async function slugOcupado(tipo: string, slug: string, exceto = 0): Promise<boolean> {
  const tabela = tipo === 'regiao' ? 'server_regions' : 'server_services';
  return (Number(await DB.value(`SELECT COUNT(*) FROM ${tabela} WHERE slug = :s AND id <> :ex`, { s: slug, ex: exceto })) | 0) > 0;
}

/** O rótulo já pertence a outra região? (não é UNIQUE no banco, mas as janelas dependem dele) */
export async function rotuloOcupado(label: string, exceto = 0): Promise<boolean> {
  return (Number(await DB.value('SELECT COUNT(*) FROM server_regions WHERE label = :l AND id <> :ex', { l: label, ex: exceto })) | 0) > 0;
}

// =====================================================================================
//  RESUMO (a faixa de indicadores da tela)
// =====================================================================================

/** Os quatro números do topo. Todos contados do banco nesta requisição. */
export async function resumo(): Promise<Dict> {
  const [regs, srvs, proxima, janelasTotal] = await Promise.all([
    regioes(),
    servicos(),
    DB.one<Dict>(
      `SELECT starts_at, ends_at, region FROM maintenance_windows
        WHERE ends_at >= datetime('now') ORDER BY starts_at LIMIT 1`,
    ),
    DB.value('SELECT COUNT(*) FROM maintenance_windows'),
  ]);

  let regOnline = 0;
  let jogadores = 0;
  let somaPing = 0;
  for (const r of regs) {
    regOnline += r.estado === 'online' ? 1 : 0;
    jogadores += r.jogadores;
    somaPing += r.ping;
  }
  let srvOk = 0;
  for (const s of srvs) srvOk += s.state === 'ok' ? 1 : 0;

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
            inicio: paraUnix(proxima.starts_at),
            fim: paraUnix(proxima.ends_at),
            regiao: String(proxima.region),
            ativa: paraUnix(proxima.starts_at) <= agoraUnix(),
          },
    janelas_total: Number(janelasTotal) | 0,
  };
}

// =====================================================================================
//  APOIO
// =====================================================================================

const MAPA_ACENTOS: Record<string, string> = {
  á: 'a', à: 'a', ã: 'a', â: 'a', ä: 'a', é: 'e', ê: 'e', ë: 'e', í: 'i', ï: 'i',
  ó: 'o', ô: 'o', õ: 'o', ö: 'o', ú: 'u', ü: 'u', ç: 'c', ñ: 'n',
};

/** Slug a partir do rótulo (sem transliteração por locale, como no PHP). */
export function slugificar(texto: string, max = 24): string {
  let t = texto.trim().toLowerCase().replace(/[áàãâäéêëíïóôõöúüçñ]/g, (c) => MAPA_ACENTOS[c] ?? c);
  t = t.replace(/[^a-z0-9]+/gu, '-');
  t = t.replace(/^-+|-+$/g, '');
  return corta(t, max);
}

/**
 * Janela mais relevante por rótulo de região: a ATIVA vence a próxima agendada. `n` é a
 * contagem total, usada pela coluna e pelo bloqueio.
 */
async function janelasPorRegiao(): Promise<Record<string, { ativa: boolean; quando: number; n: number }>> {
  const agora = agoraUnix();
  const out: Record<string, { ativa: boolean; quando: number; n: number }> = {};
  const linhas = await DB.all<Dict>(
    `SELECT region, starts_at, ends_at FROM maintenance_windows
      WHERE ends_at >= datetime('now', '-1 day') ORDER BY starts_at ASC`,
  );
  for (const j of linhas) {
    const reg = String(j.region);
    const ini = paraUnix(j.starts_at);
    const fim = paraUnix(j.ends_at);
    const ativa = ini <= agora && agora <= fim;
    const n = (out[reg]?.n ?? 0) + 1;
    if (!out[reg] || (ativa && !out[reg]!.ativa)) out[reg] = { ativa, quando: ativa ? fim : ini, n };
    else out[reg]!.n = n;
  }
  return out;
}

/** `%` e `_` digitados na busca são literais, não curingas (o LIKE usa ESCAPE '\'). */
function escLike(s: string): string {
  return s.replace(/[\\%_]/g, (c) => '\\' + c);
}
