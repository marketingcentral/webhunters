/**
 * Contas com acesso ao painel administrativo e a trilha de auditoria (`admin_audit_log`)
 * — o App\Repo\AdminRepo do PHP, inteiro (login/guarda do painel + blocos do dashboard).
 *
 * Três donos usam a mesma regra: o login do painel (controllers/admin/auth-controller.ts), a
 * guarda de rota (controllers/admin/admin-controller.ts) e a tela "Logs do Sistema".
 *
 * DATAS: o SQLite grava `datetime('now')` em UTC; o MySQL gravava no fuso do servidor. Aqui
 * toda string DATETIME é lida como UTC (`paraUnix`) e formatada de volta em UTC
 * (`formatarData`) — ou seja, a tela mostra o relógio que está gravado na coluna, como o PHP
 * fazia com strtotime()/date() no mesmo fuso. Ver README ("Datas").
 */
import * as DB from '../core/database';
import { REGION_CAPACITY } from '../core/game-rules';
import { num } from '../core/helpers';
import type { Dict } from '../core/view';
import * as UserRepo from './user-repo';

/** Papéis que abrem o painel. Qualquer outro valor de users.role é jogador comum. */
export const PAPEIS = ['admin', 'owner'];

// ------------------------------------------------------------------------- utilitários

/** mb_substr($s, 0, $n): corta por caractere, não por unidade UTF-16. */
export function corta(s: string, n: number): string {
  const a = Array.from(s);
  return a.length > n ? a.slice(0, n).join('') : s;
}

/** strtotime() de uma coluna DATETIME/DATE (lida como UTC). 0 quando vazia/inválida. */
export function paraUnix(s: unknown): number {
  const v = String(s ?? '').trim();
  if (v === '') return 0;
  const iso = /^\d{4}-\d{2}-\d{2}$/.test(v) ? v + 'T00:00:00Z' : v.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(v) ? '' : 'Z');
  const t = Date.parse(iso);
  return Number.isNaN(t) ? 0 : Math.floor(t / 1000);
}

/** Unix agora (time()). */
export function agoraUnix(): number {
  return Math.floor(Date.now() / 1000);
}

/**
 * date($mascara, $ts) com o subconjunto que o painel usa: d m Y y H i s. Em UTC (ver topo).
 * date('c') → mascara 'c' (ISO 8601 com +00:00).
 */
export function formatarData(ts: number, mascara: string): string {
  const d = new Date(ts * 1000);
  if (mascara === 'c') return d.toISOString().replace(/\.\d{3}Z$/, '+00:00');
  const p2 = (n: number) => String(n).padStart(2, '0');
  const mapa: Record<string, string> = {
    d: p2(d.getUTCDate()),
    m: p2(d.getUTCMonth() + 1),
    Y: String(d.getUTCFullYear()),
    y: p2(d.getUTCFullYear() % 100),
    H: p2(d.getUTCHours()),
    i: p2(d.getUTCMinutes()),
    s: p2(d.getUTCSeconds()),
  };
  return mascara.replace(/[dmYyHis]/g, (c) => mapa[c]!);
}

// ------------------------------------------------------------------- contas e auditoria

/** Conta por id (sem o hash). */
export async function porId(id: number): Promise<Dict | null> {
  if (id <= 0) return null;
  return DB.one('SELECT id, username, email, role, status, last_login, last_ip FROM users WHERE id = :id LIMIT 1', { id });
}

/** Conta por nome de usuário OU e-mail, com o password_hash (quem chama confere a senha). */
export function porLogin(login: string): Promise<Dict | null> {
  return UserRepo.findByLogin(login);
}

/** Papel administrativo E conta ativa: banido/suspenso não entra nem sendo owner. */
export function podeEntrar(conta: Dict | null | undefined): boolean {
  if (!conta) return false;
  return PAPEIS.includes(String(conta.role ?? 'player')) && String(conta.status ?? 'active') === 'active';
}

/** Rótulo do papel para a topbar do painel. */
export function rotuloPapel(role: string): string {
  return role === 'owner' ? 'Dono do jogo' : 'Administrador';
}

/**
 * Uma linha na trilha de auditoria. NUNCA lança: falha de log não pode virar falha de
 * logout (o operador ficaria preso dentro do painel). Falhou, vai para o console.
 */
export async function registrar(
  adminId: number | null,
  acao: string,
  entidade = 'sistema',
  entidadeId: string | null = null,
  resumo = '',
  meta: Dict = {},
  ip: string | null = null,
): Promise<void> {
  try {
    await DB.insert('admin_audit_log', {
      admin_id: adminId !== null && adminId > 0 ? adminId : null,
      action: corta(acao, 64),
      entity: corta(entidade, 48),
      entity_id: entidadeId !== null ? corta(entidadeId, 64) : null,
      summary: corta(resumo, 255),
      // JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES — o JSON.stringify já não escapa nenhum dos dois
      meta: Object.keys(meta).length === 0 ? null : JSON.stringify(meta),
      ip: ip !== null ? corta(ip, 45) : null,
    });
  } catch (e) {
    console.error(`[extracthunters] auditoria (${acao}):`, e);
  }
}

/**
 * Quantas falhas de login o IP acumulou na janela e quando foi a última (unix, 0 = nenhuma).
 * O contador mora no log de auditoria porque é o único que sobrevive a apagar o cookie.
 */
export async function falhasRecentes(ip: string, janelaMinutos = 15): Promise<{ n: number; ultima: number }> {
  const janela = Math.max(1, Math.min(1440, Math.trunc(janelaMinutos)));
  let linha: Dict | null;
  try {
    linha = await DB.one(
      `SELECT COUNT(*) AS n, MAX(created_at) AS ultima
         FROM admin_audit_log
        WHERE "action" = :acao AND ip = :ip
          AND created_at > datetime('now', '-${janela} minutes')`,
      { acao: 'login_falhou', ip },
    );
  } catch (e) {
    console.error('[extracthunters] falhasRecentes:', e);
    return { n: 0, ultima: 0 };
  }
  return { n: Number(linha?.n ?? 0) | 0, ultima: paraUnix(linha?.ultima) };
}

/** Carimba o acesso do administrador (último login e último IP). */
export async function marcarAcesso(id: number, ip: string): Promise<void> {
  try {
    await DB.run(`UPDATE users SET last_login = datetime('now'), last_ip = :ip WHERE id = :id`, { ip: corta(ip, 45), id });
  } catch (e) {
    console.error('[extracthunters] marcarAcesso:', e);
  }
}

// =====================================================================================
//  DASHBOARD (/admin)
//
//  Todo método daqui para baixo devolve DADO, nunca HTML. Toda métrica carrega a sua
//  procedência em 'fonte' (a REGRA DA HONESTIDADE do PHP):
//    FONTE_VIVO    consulta direta nas tabelas do jogo;
//    FONTE_SERIE   admin_metrics_daily (hoje semente de demonstração);
//    FONTE_SEMENTE coluna real com conteúdo de demonstração (server_regions.players);
//    FONTE_AUSENTE não existe origem: valor null e 'origem' diz de onde virá.
// =====================================================================================

export const FONTE_VIVO = 'vivo';
export const FONTE_SERIE = 'serie';
export const FONTE_SEMENTE = 'semente';
export const FONTE_AUSENTE = 'ausente';

/** Janela que conta como "online agora" (presença aproximada pelo último login). */
const PRESENCA_MIN = 15;

/** Teto de latência usado só para desenhar o anel de rede (não é SLA). */
const PING_TETO_MS = 300;

/** Os quatro cartões da faixa de boas-vindas. */
export async function kpis(): Promise<Dict[]> {
  return Promise.all([kpiOnline(), kpiNovos(), kpiSessao(), kpiReceitaMes()]);
}

function contarOnline(): Promise<unknown> {
  return valor(
    `SELECT COUNT(*) FROM users
      WHERE status = :st AND last_login IS NOT NULL
        AND last_login >= datetime('now', '-${PRESENCA_MIN} minutes')`,
    { st: 'active' },
  );
}

async function kpiOnline(): Promise<Dict> {
  const agora = Number(await contarOnline()) | 0;
  const teto = await capacidadeTotal();
  return {
    chave: 'online',
    rotulo: 'Jogadores online',
    icone: 'group',
    tom: 'lime',
    valor: agora,
    formato: 'inteiro',
    sub: teto > 0 ? '/ ' + teto : null,
    delta: null,
    delta_rotulo: 'sem série de presença',
    fonte: FONTE_VIVO,
    origem: 'users.last_login nos últimos ' + PRESENCA_MIN + ' min',
    nota: 'Presença aproximada pelo último login — o esquema não tem tabela de sessão.',
  };
}

async function kpiNovos(): Promise<Dict> {
  const linha = await linhaUma(
    `SELECT
       SUM(created_at >= datetime('now', '-24 hours'))                                       AS agora,
       SUM(created_at >= datetime('now', '-48 hours') AND created_at < datetime('now', '-24 hours')) AS antes
     FROM users`,
  );
  const agora = Number(linha?.agora ?? 0) | 0;
  const antes = Number(linha?.antes ?? 0) | 0;
  return {
    chave: 'novos',
    rotulo: 'Novos (24 h)',
    icone: 'person_add',
    tom: 'amber',
    valor: agora,
    formato: 'inteiro',
    sub: null,
    delta: variacao(agora, antes),
    delta_rotulo: 'vs 24 h antes',
    fonte: FONTE_VIVO,
    origem: 'COUNT(users.created_at) nas últimas 24 h',
    nota: null,
  };
}

async function kpiSessao(): Promise<Dict> {
  // Média ponderada pelas sessões do dia; COALESCE porque a divisão vira NULL sem sessão.
  const sql = (de: number, ate: number) =>
    `SELECT COALESCE(SUM(avg_session_sec * sessions) * 1.0 / NULLIF(SUM(sessions), 0), 0)
       FROM admin_metrics_daily
      WHERE "day" BETWEEN date('now', '-${de} days') AND date('now', '-${ate} days')`;
  const agora = Number(await valor(sql(6, 0))) || 0;
  const antes = Number(await valor(sql(13, 7))) || 0;
  return {
    chave: 'sessao',
    rotulo: 'Sessão média',
    icone: 'schedule',
    tom: 'cyan',
    valor: agora > 0 ? Math.round(agora) : null,
    formato: 'duracao',
    sub: null,
    delta: variacao(agora, antes),
    delta_rotulo: 'vs 7 dias antes',
    fonte: FONTE_SERIE,
    origem: 'admin_metrics_daily.avg_session_sec (7 dias, ponderado por sessions)',
    nota: null,
  };
}

/** 'YYYY-MM-DD' de um Date em UTC. */
function ymd(d: Date): string {
  return d.toISOString().slice(0, 10);
}

/** DATE_SUB(d, INTERVAL 1 MONTH) do MySQL: o dia é limitado ao fim do mês anterior (31/03 → 28/02). */
function menosUmMes(d: Date): Date {
  const ano = d.getUTCMonth() === 0 ? d.getUTCFullYear() - 1 : d.getUTCFullYear();
  const mes = (d.getUTCMonth() + 11) % 12;
  const ultimo = new Date(Date.UTC(ano, mes + 1, 0)).getUTCDate();
  return new Date(Date.UTC(ano, mes, Math.min(d.getUTCDate(), ultimo)));
}

async function kpiReceitaMes(): Promise<Dict> {
  // Mês corrente contra o MESMO NÚMERO DE DIAS do mês anterior. As quatro datas saem daqui
  // com a mesma aritmética do DATE_SUB do MySQL (o '-1 month' do SQLite normaliza 31/03 para 03/03).
  const hoje = new Date();
  const hojeD = new Date(Date.UTC(hoje.getUTCFullYear(), hoje.getUTCMonth(), hoje.getUTCDate()));
  const dom = hojeD.getUTCDate();
  const iniMes = new Date(hojeD.getTime() - (dom - 1) * 86400_000);
  const fimAnt = menosUmMes(hojeD);
  const iniAnt = new Date(fimAnt.getTime() - (dom - 1) * 86400_000);
  const linha = await linhaUma(
    `SELECT
       COALESCE(SUM(CASE WHEN "day" >= :ini AND "day" <= :hoje THEN revenue_cents END), 0) AS agora,
       COALESCE(SUM(CASE WHEN "day" >= :iniAnt AND "day" <= :fimAnt THEN revenue_cents END), 0) AS antes
     FROM admin_metrics_daily`,
    { ini: ymd(iniMes), hoje: ymd(hojeD), iniAnt: ymd(iniAnt), fimAnt: ymd(fimAnt) },
  );
  const agora = Number(linha?.agora ?? 0) | 0;
  const antes = Number(linha?.antes ?? 0) | 0;
  return {
    chave: 'receita',
    rotulo: 'Receita (mês)',
    icone: 'payments',
    tom: 'purple',
    valor: agora,
    formato: 'dinheiro',
    sub: null,
    delta: variacao(agora, antes),
    delta_rotulo: 'vs mês anterior',
    fonte: FONTE_SERIE,
    origem: 'admin_metrics_daily.revenue_cents do mês corrente',
    nota: null,
  };
}

/** 'YYYY-MM-DD' → 'dd/mm' (o date('d/m', strtotime($dia)) do PHP). */
function rotuloDia(dia: string): string {
  return formatarData(paraUnix(dia), 'd/m');
}

/** Série diária de pico simultâneo para o gráfico de linha. */
export async function serieOnline(dias = 7): Promise<Dict> {
  dias = janela(dias);
  const linhas = await linhasTodas(
    `SELECT "day", players_online
       FROM admin_metrics_daily
      WHERE "day" > date('now', '-${dias} days')
        AND "day" <= date('now')
      ORDER BY "day" ASC`,
  );
  const pontos = linhas.map((l) => ({ dia: String(l.day), rotulo: rotuloDia(String(l.day)), valor: Number(l.players_online) | 0 }));
  const valores = pontos.map((p) => p.valor);
  return {
    pontos,
    min: valores.length ? Math.min(...valores) : 0,
    max: valores.length ? Math.max(...valores) : 0,
    media: valores.length ? Math.round(valores.reduce((a, b) => a + b, 0) / valores.length) : 0,
    dias,
    fonte: FONTE_SERIE,
    origem: 'admin_metrics_daily.players_online',
  };
}

/** Ranking por nível (empate: XP, depois nome — a ordem não dança entre carregamentos). */
export async function topJogadores(n = 5): Promise<Dict[]> {
  n = Math.max(1, Math.min(20, Math.trunc(n)));
  const linhas = await linhasTodas(
    `SELECT p.user_id, p.display_name, p.level, p.xp, p.avatar, p.player_rank,
            u.username, u.status
       FROM players p
       JOIN users u ON u.id = p.user_id
      ORDER BY p.level DESC, p.xp DESC, p.display_name ASC
      LIMIT ${n}`,
  );
  return linhas.map((l, i) => ({
    posicao: i + 1,
    user_id: Number(l.user_id) | 0,
    nome: String(l.display_name || l.username || ''),
    nivel: Number(l.level) | 0,
    xp: Number(l.xp) | 0,
    avatar: String(l.avatar ?? ''),
    patente: String(l.player_rank ?? ''),
    ativo: String(l.status ?? 'active') === 'active',
  }));
}

/** Receita da janela + barra por dia + a contraprova real (compras em moeda do jogo). */
export async function receita(dias = 30): Promise<Dict> {
  dias = janela(dias);
  const linhas = await linhasTodas(
    `SELECT "day", revenue_cents
       FROM admin_metrics_daily
      WHERE "day" > date('now', '-${dias} days')
        AND "day" <= date('now')
      ORDER BY "day" ASC`,
  );
  let total = 0;
  const barras = linhas.map((l) => {
    const cents = Math.trunc(Number(l.revenue_cents));
    total += cents;
    return { dia: String(l.day), rotulo: rotuloDia(String(l.day)), centavos: cents };
  });
  const anterior =
    Number(
      await valor(
        `SELECT COALESCE(SUM(revenue_cents), 0)
           FROM admin_metrics_daily
          WHERE "day" > date('now', '-${dias * 2} days')
            AND "day" <= date('now', '-${dias} days')`,
      ),
    ) | 0;
  const compras = await linhaUma(
    `SELECT COUNT(*) AS n, COALESCE(SUM(price * quantity), 0) AS creditos
       FROM player_purchases
      WHERE bought_at >= datetime('now', '-${dias} days')`,
  );
  const cents = barras.map((b) => b.centavos);
  return {
    barras,
    total_centavos: total,
    max_centavos: cents.length ? Math.max(...cents) : 0,
    delta: variacao(total, anterior),
    dias,
    compras: { n: Number(compras?.n ?? 0) | 0, creditos: Math.trunc(Number(compras?.creditos ?? 0)) },
    fonte: FONTE_SERIE,
    origem: 'admin_metrics_daily.revenue_cents; compras de player_purchases',
  };
}

/** Temporada, evento de temporada e janelas de manutenção, na mesma lista (estado pela data). */
export async function eventosAtivos(n = 6): Promise<Dict[]> {
  n = Math.max(1, Math.min(20, Math.trunc(n)));
  const agora = agoraUnix();
  const itens: Dict[] = [];

  const temporada = await linhaUma(
    `SELECT tag, pass_name, pass_image, event_tag, event_title, event_image,
            event_ends_at, starts_at, ends_at
       FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1`,
  );
  if (temporada !== null) {
    itens.push({
      titulo: (String(temporada.tag) + ' · ' + String(temporada.pass_name)).trim(),
      tipo: 'Temporada',
      imagem: String(temporada.pass_image ?? ''),
      inicio: paraUnix(temporada.starts_at),
      fim: paraUnix(temporada.ends_at),
      nota: null,
      fonte: FONTE_VIVO,
    });
    if (temporada.event_title) {
      itens.push({
        titulo: String(temporada.event_title),
        tipo: String(temporada.event_tag || 'Evento'),
        imagem: String(temporada.event_image ?? ''),
        inicio: null,
        fim: paraUnix(temporada.event_ends_at),
        nota: null,
        fonte: FONTE_VIVO,
      });
    }
  }

  const janelas = await linhasTodas(
    `SELECT mw.starts_at, mw.ends_at, mw.region, mw.note, nw.image
       FROM maintenance_windows mw
  LEFT JOIN news nw ON nw.slug = mw.news_slug
      ORDER BY ABS(julianday('now') - julianday(mw.starts_at)) ASC
      LIMIT ${n}`,
  );
  for (const j of janelas) {
    itens.push({
      titulo: 'Manutenção ' + formatarData(paraUnix(j.starts_at), 'd/m') + ' · ' + String(j.region).trim(),
      tipo: 'Janela de manutenção',
      imagem: String(j.image ?? ''),
      inicio: paraUnix(j.starts_at),
      fim: paraUnix(j.ends_at),
      nota: String(j.note ?? '') || null,
      fonte: FONTE_VIVO,
    });
  }

  for (const item of itens) {
    item.estado = item.fim <= agora ? 'encerrado' : item.inicio !== null && item.inicio > agora ? 'agendado' : 'ativo';
  }

  // No ar primeiro, depois o que vem aí, encerrado por último.
  const ordem: Record<string, number> = { ativo: 0, agendado: 1, encerrado: 2 };
  itens.sort((a, b) => {
    const c = ordem[a.estado]! - ordem[b.estado]!;
    if (c !== 0) return c;
    return a.estado === 'encerrado' ? Math.sign(b.fim - a.fim) : Math.sign(a.fim - b.fim);
  });
  return itens.slice(0, n);
}

/**
 * Linha do tempo do painel e do jogo: cinco consultas curtas fundidas aqui (sem UNION), com
 * repetições vizinhas idênticas viradas em uma linha contada (`repeticoes`).
 */
export async function atividadeRecente(n = 6): Promise<Dict[]> {
  n = Math.max(1, Math.min(30, Math.trunc(n)));
  const [usuarios, bans, compras, partidas, auditoria] = await Promise.all([
    linhasTodas(`SELECT id, username, created_at FROM users ORDER BY created_at DESC LIMIT ${n}`),
    linhasTodas(
      `SELECT b.created_at, b.kind, b.reason, u.username
         FROM bans b LEFT JOIN users u ON u.id = b.user_id
        WHERE b.revoked_at IS NULL
        ORDER BY b.created_at DESC LIMIT ${n}`,
    ),
    linhasTodas(
      `SELECT pp.bought_at, pp.price, pp.quantity, pp.currency, i.name, u.username
         FROM player_purchases pp
         JOIN items i ON i.id = pp.item_id
         JOIN users u ON u.id = pp.user_id
        ORDER BY pp.bought_at DESC LIMIT ${n}`,
    ),
    linhasTodas(
      `SELECT pm.played_at, pm.result, pm.kills, pm.xp, u.username
         FROM player_matches pm JOIN users u ON u.id = pm.user_id
        ORDER BY pm.played_at DESC LIMIT ${n}`,
    ),
    linhasTodas(
      `SELECT a.created_at, a."action", a.summary, u.username
         FROM admin_audit_log a LEFT JOIN users u ON u.id = a.admin_id
        ORDER BY a.created_at DESC, a.id DESC LIMIT ${n}`,
    ),
  ]);

  const itens: Dict[] = [];
  for (const u of usuarios) {
    itens.push({
      quando: paraUnix(u.created_at),
      icone: 'person_add',
      tom: 'cyan',
      texto: 'Novo jogador registrado: ' + String(u.username),
      detalhe: null,
      origem: 'users',
    });
  }
  for (const b of bans) {
    itens.push({
      quando: paraUnix(b.created_at),
      icone: 'gavel',
      tom: 'red',
      texto: 'Jogador ' + String(b.username ?? 'removido') + ' banido (' + (String(b.kind) === 'perma' ? 'definitivo' : 'temporário') + ')',
      detalhe: String(b.reason ?? '') || null,
      origem: 'bans',
    });
  }
  for (const c of compras) {
    itens.push({
      quando: paraUnix(c.bought_at),
      icone: 'shopping_bag',
      tom: 'gold',
      texto: 'Compra na loja: ' + String(c.name),
      detalhe: num(Number(c.price) * (Number(c.quantity) | 0)) + ' ' + String(c.currency) + ' · ' + String(c.username),
      origem: 'player_purchases',
    });
  }
  for (const m of partidas) {
    itens.push({
      quando: paraUnix(m.played_at),
      icone: 'flag',
      tom: 'lime',
      texto: 'Operação de ' + String(m.username) + ': ' + String(m.result),
      detalhe: (Number(m.kills) | 0) + ' abates · ' + num(Number(m.xp)) + ' XP',
      origem: 'player_matches',
    });
  }
  for (const a of auditoria) {
    const acao = String(a.action);
    const [frase, entre] = partirResumo(String(a.summary || acao));
    const quem = 'Painel · ' + String(a.username ?? 'anônimo');
    const alerta = acao.includes('falhou') || acao.includes('bloqueio');
    itens.push({
      quando: paraUnix(a.created_at),
      icone: alerta ? 'shield_lock' : 'admin_panel_settings',
      tom: alerta ? 'amber' : 'muted',
      texto: frase,
      detalhe: entre !== '' ? quem + ' · ' + entre : quem,
      origem: 'admin_audit_log',
    });
  }

  // usort estável (PHP 8): mesma ordem relativa entre empates.
  itens.sort((a, b) => b.quando - a.quando);

  const fundido: Dict[] = [];
  for (const item of itens) {
    const ultimo = fundido[fundido.length - 1];
    if (ultimo && ultimo.texto === item.texto && ultimo.detalhe === item.detalhe) {
      ultimo.repeticoes++;
      continue;
    }
    item.repeticoes = 1;
    fundido.push(item);
  }
  return fundido.slice(0, n);
}

/**
 * Regiões e serviços da plataforma. A barra de ocupação compara cada região com a MAIS
 * CHEIA (o banco não guarda teto por região).
 */
export async function statusServidores(): Promise<Dict> {
  const [regioes, servicos, janelas] = await Promise.all([
    linhasTodas('SELECT slug, label, ping_ms, players, state FROM server_regions ORDER BY sort_order, id'),
    linhasTodas('SELECT slug, label, state, status_label, maint_scope FROM server_services ORDER BY sort_order, id'),
    linhasTodas(
      `SELECT region, starts_at, ends_at FROM maintenance_windows
        WHERE ends_at >= datetime('now', '-1 day') ORDER BY starts_at ASC`,
    ),
  ]);
  const agora = agoraUnix();

  const porRegiao: Record<string, { ativa: boolean; quando: number }> = {};
  for (const j of janelas) {
    const ini = paraUnix(j.starts_at);
    const fim = paraUnix(j.ends_at);
    const reg = String(j.region);
    const ativa = ini <= agora && agora <= fim;
    // A janela ATIVA sempre vence a próxima agendada na hora de rotular a região.
    if (!porRegiao[reg] || (ativa && !porRegiao[reg]!.ativa)) porRegiao[reg] = { ativa, quando: ativa ? fim : ini };
  }

  let pico = 0;
  for (const r of regioes) pico = Math.max(pico, Number(r.players) | 0);

  let totalJog = 0;
  let somaPing = 0;
  const saidaReg = regioes.map((r) => {
    const label = String(r.label);
    const jog = Number(r.players) | 0;
    totalJog += jog;
    somaPing += Number(r.ping_ms) | 0;
    const manut = porRegiao[label] ?? null;
    let estado: string, rotulo: string, nota: string | null;
    if (manut && manut.ativa) [estado, rotulo, nota] = ['manutencao', 'Em manutenção', 'Janela ativa até ' + formatarData(manut.quando, 'H:i')];
    else if (String(r.state) === 'down') [estado, rotulo, nota] = ['fora', 'Fora do ar', null];
    else if (String(r.state) === 'warn') [estado, rotulo, nota] = ['instavel', 'Instável', null];
    else if (manut) [estado, rotulo, nota] = ['agendada', 'Manut. agendada', 'Janela em ' + formatarData(manut.quando, 'd/m H:i')];
    else [estado, rotulo, nota] = ['online', 'Online', null];
    return {
      slug: String(r.slug),
      label,
      jogadores: jog,
      ping: Number(r.ping_ms) | 0,
      estado,
      estado_rotulo: rotulo,
      ocupacao: pico > 0 ? jog / pico : 0.0,
      nota,
    };
  });

  const saidaSrv = servicos.map((s) => ({
    slug: String(s.slug),
    label: String(s.label),
    estado: String(s.state) === 'ok' ? 'online' : String(s.state) === 'down' ? 'fora' : 'manutencao',
    rotulo: String(s.status_label),
    escopo: String(s.maint_scope ?? '') || null,
  }));

  return {
    regioes: saidaReg,
    servicos: saidaSrv,
    total: totalJog,
    pico,
    ping_medio: regioes.length === 0 ? 0 : Math.round(somaPing / regioes.length),
    fonte: FONTE_SEMENTE,
    origem: 'server_regions + server_services + maintenance_windows',
    escala:
      'Contadores e ping de server_regions são amostra de demonstração; a barra compara cada região com a mais cheia, porque o banco não guarda teto por região.',
  };
}

/**
 * Os quatro anéis e o rodapé de "Status do sistema". Sem CPU/memória/disco/uptime: nada
 * disso é coletado, e o rodapé declara o buraco em vez de preenchê-lo.
 */
export async function statusSistema(): Promise<Dict> {
  const [srv, teto, onlineBruto, ultimaBruta] = await Promise.all([
    statusServidores(),
    capacidadeTotal(),
    contarOnline(),
    valor('SELECT MAX(created_at) FROM admin_audit_log'),
  ]);
  const online = Number(onlineBruto) | 0;

  let srvOk = 0;
  for (const s of srv.servicos as Dict[]) srvOk += s.estado === 'online' ? 1 : 0;

  // Três baldes: região com janela AGENDADA ainda opera, mas não entra no verde.
  let regOk = 0;
  let regAviso = 0;
  let regFora = 0;
  for (const r of srv.regioes as Dict[]) {
    if (r.estado === 'online') regOk++;
    else if (r.estado === 'fora') regFora++;
    else regAviso++;
  }

  const nSrvReal = (srv.servicos as Dict[]).length;
  const nRegReal = (srv.regioes as Dict[]).length;
  const nSrv = Math.max(1, nSrvReal);
  const nReg = Math.max(1, nRegReal);
  const ping = Number(srv.ping_medio) | 0;
  const fatias = (q: number, tom: string) => Array.from({ length: q }, () => ({ fracao: 1 / nReg, tom }));

  const aneis: Dict[] = [
    {
      chave: 'ocupacao',
      rotulo: 'Ocupação',
      fracao: teto > 0 ? Math.min(1.0, online / teto) : 0.0,
      texto: teto > 0 ? online + '/' + teto : String(online),
      tom: 'lime',
      fonte: FONTE_VIVO,
      origem: 'users.last_login vs capacidade das regiões',
    },
    {
      chave: 'servicos',
      rotulo: 'Serviços no ar',
      fracao: srvOk / nSrv,
      texto: srvOk + '/' + nSrvReal,
      tom: srvOk === nSrv ? 'cyan' : 'amber',
      fonte: FONTE_VIVO,
      origem: 'server_services.state',
    },
    {
      chave: 'regioes',
      rotulo: 'Regiões online',
      fracao: (regOk + regAviso) / nReg,
      texto: regOk + '/' + nRegReal,
      tom: regOk === nReg ? 'lime' : 'amber',
      segmentos: [...fatias(regOk, 'lime'), ...fatias(regAviso, 'amber'), ...fatias(regFora, 'red')],
      fonte: FONTE_VIVO,
      origem: 'server_regions.state + maintenance_windows',
    },
    {
      chave: 'latencia',
      rotulo: 'Folga de rede',
      fracao: ping > 0 ? Math.max(0.0, 1 - Math.min(1.0, ping / PING_TETO_MS)) : 0.0,
      texto: ping > 0 ? ping + ' ms' : '—',
      tom: ping <= 120 ? 'lime' : ping <= 200 ? 'amber' : 'red',
      fonte: FONTE_VIVO,
      origem: 'AVG(server_regions.ping_ms), teto de leitura ' + PING_TETO_MS + ' ms',
    },
  ];

  const ultima = String(ultimaBruta ?? '');
  const rodape: Dict[] = [
    { rotulo: 'Uptime', valor: null, fonte: FONTE_AUSENTE, origem: 'virá de um coletor de health-check do host; nada é gravado hoje' },
    {
      rotulo: 'Capacidade máx.',
      valor: teto > 0 ? num(teto) + ' operadores' : null,
      fonte: teto > 0 ? FONTE_VIVO : FONTE_AUSENTE,
      origem: 'admin_settings.region_capacity_total × COUNT(server_regions)',
    },
    {
      rotulo: 'Última ação',
      valor: ultima !== '' ? formatarData(paraUnix(ultima), 'd/m H:i') : null,
      fonte: ultima !== '' ? FONTE_VIVO : FONTE_AUSENTE,
      origem: 'MAX(admin_audit_log.created_at)',
    },
  ];
  return { aneis, rodape };
}

// ------------------------------------------------------------------ apoio do dashboard

/** Separa "Frase. (aposto)" em ['Frase.', 'aposto']; sem parêntese, [frase, '']. */
function partirResumo(resumo: string): [string, string] {
  resumo = resumo.trim();
  const m = /^(.*?)\s*\(([^()]*)\)\s*\.?$/u.exec(resumo);
  if (!m) return [resumo, ''];
  const frase = m[1]!.trim().replace(/\.+$/, '');
  return [frase + '.', m[2]!.trim()];
}

/** Teto humano da plataforma: o parâmetro por região × as regiões reais. */
async function capacidadeTotal(): Promise<number> {
  const [porRegiao, regioes] = await Promise.all([
    parametro('region_capacity_total', REGION_CAPACITY.total),
    valor('SELECT COUNT(*) FROM server_regions'),
  ]);
  return porRegiao * (Number(regioes) | 0);
}

/** Parâmetro de admin_settings, com a constante do código como padrão. */
async function parametro(chave: string, padrao: number): Promise<number> {
  const v = await valor('SELECT "value" FROM admin_settings WHERE "key" = :k', { k: chave });
  return v === null || v === undefined || v === '' ? padrao : Number.parseInt(String(v), 10) || 0;
}

/** Variação fracionária entre dois períodos; null quando não há base. */
function variacao(atual: number, anterior: number): number | null {
  if (anterior <= 0) return null;
  return (atual - anterior) / anterior;
}

/** Janela de dias aceita pelos gráficos (o valor vem da query string). */
function janela(dias: number): number {
  return Math.max(2, Math.min(90, Math.trunc(dias)));
}

// As três embalagens abaixo existem para o dashboard NUNCA derrubar o painel: tabela que
// ainda não existe vira lista vazia, e só o console sabe do problema.

async function linhasTodas(sql: string, p: DB.Params = {}): Promise<Dict[]> {
  try {
    return await DB.all<Dict>(sql, p);
  } catch (e) {
    console.error('[extracthunters] dashboard:', e);
    return [];
  }
}

async function linhaUma(sql: string, p: DB.Params = {}): Promise<Dict | null> {
  try {
    return await DB.one<Dict>(sql, p);
  } catch (e) {
    console.error('[extracthunters] dashboard:', e);
    return null;
  }
}

async function valor(sql: string, p: DB.Params = {}): Promise<unknown> {
  try {
    return await DB.value(sql, p);
  } catch (e) {
    console.error('[extracthunters] dashboard:', e);
    return null;
  }
}
