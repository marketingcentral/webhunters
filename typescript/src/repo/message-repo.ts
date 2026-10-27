/**
 * Caixa de mensagens conciliada por user_id (message_threads / message_entries /
 * system_notices) mais a próxima janela de manutenção (catálogo maintenance_windows) —
 * o App\Repo\MessageRepo do PHP.
 *
 * Devolve exatamente as chaves que views/pages/mensagens.ts consome.
 *
 * Datas: como no PHP, os DATETIME daqui são horário de parede de São Paulo (o catálogo veio
 * assim do MySQL e a resposta do jogador é gravada assim) — ver core/fuso.ts.
 */
import * as DB from '../core/database';
import { agoraTs, date, strtotime } from '../core/fuso';
import type { Dict } from '../core/view';

const MAX_BODY = 200;

/** mb_substr($s, 0, $n) — conta code points, não unidades UTF-16. */
function mbSub(s: string, n: number): string {
  return Array.from(s).slice(0, n).join('');
}

// ---------------------------------------------------------------- leitura

/** Conversas do jogador com os balões já formatados. */
export async function threads(userId: number): Promise<Dict[]> {
  const rows = await DB.all(
    `SELECT t.*, MAX(m.sent_at) AS last_at
       FROM message_threads t
       LEFT JOIN message_entries m ON m.thread_id = t.id
      WHERE t.user_id = :u
      GROUP BY t.id
      ORDER BY last_at DESC, t.id DESC`,
    { u: userId },
  );
  if (rows.length === 0) return [];

  const ids = rows.map((r) => Number(r.id));
  const params: Record<string, number> = {};
  ids.forEach((id, i) => (params['t' + i] = id));
  const msgs = await DB.all(
    `SELECT thread_id, author, is_self, body, color, sent_at
       FROM message_entries WHERE thread_id IN (${ids.map((_, i) => ':t' + i).join(',')}) ORDER BY sent_at, id`,
    params,
  );

  const byThread = new Map<number, Dict[]>();
  const lastDay = new Map<number, string>();
  for (const m of msgs) {
    const tid = Number(m.thread_id);
    const day = String(m.sent_at).slice(0, 10);
    const self = Number(m.is_self) === 1;
    const line: Dict = {
      author: self ? 'self' : String(m.author),
      self,
      text: String(m.body),
      time: date('H:i', strtotime(String(m.sent_at))),
      color: String(m.color),
    };
    if (lastDay.get(tid) !== day) {
      line.sep = daySeparator(day);
      lastDay.set(tid, day);
    }
    if (!byThread.has(tid)) byThread.set(tid, []);
    byThread.get(tid)!.push(line);
  }

  return rows.map((r) => {
    const tid = Number(r.id);
    const last = String(r.last_at ?? '');
    const linhas = byThread.get(tid) ?? [];
    return {
      id: String(r.slug),
      folder: String(r.folder),
      from: String(r.peer_name),
      system: Number(r.is_system) === 1,
      subject: String(r.subject),
      preview: preview(linhas),
      time: listStamp(last),
      date: fullStamp(last),
      unread: Number(r.unread) === 1,
      avatar: r.peer_avatar ? String(r.peer_avatar) : null,
      icon: r.peer_icon ? String(r.peer_icon) : null,
      level: r.peer_level !== null && r.peer_level !== undefined ? Number(r.peer_level) | 0 : null,
      class: String(r.peer_class ?? ''),
      class_ms: String(r.peer_class_icon ?? 'shield'),
      status: String(r.peer_status ?? ''),
      status_kind: String(r.peer_status_kind ?? 'offline'),
      color: String(r.color),
      tag: r.tag ? String(r.tag) : null,
      thread: linhas,
    };
  });
}

/**
 * Contagem por pasta usando a MESMA regra do filtro da tela: "sistema" cruza as pastas
 * (toda conversa de sistema), as demais contam pela coluna folder.
 */
export async function folderCounts(userId: number): Promise<Record<string, number>> {
  const rows = await DB.all(
    'SELECT folder, is_system, COUNT(*) n FROM message_threads WHERE user_id = :u GROUP BY folder, is_system',
    { u: userId },
  );
  const out: Record<string, number> = { entrada: 0, sistema: 0, enviadas: 0 };
  for (const r of rows) {
    const f = String(r.folder);
    const n = Number(r.n) | 0;
    out[f] = (out[f] ?? 0) + n;
    if (Number(r.is_system) === 1) out.sistema! += n;
  }
  return out;
}

export async function unreadCount(userId: number): Promise<number> {
  return Number(await DB.value('SELECT COUNT(*) FROM message_threads WHERE user_id = :u AND unread = 1', { u: userId })) | 0;
}

/**
 * Avisos do painel lateral: os desta conta MAIS os globais (`user_id NULL`). Os globais
 * vêm primeiro em empate de ordem — é recado da operação (manutenção, evento).
 */
export async function notices(userId: number): Promise<Dict[]> {
  const rows = await DB.all(
    `SELECT * FROM system_notices
      WHERE user_id = :u OR user_id IS NULL
      ORDER BY sort_order, user_id IS NOT NULL, id`,
    { u: userId },
  );
  return rows.map((r) => ({
    icon: String(r.icon),
    color: String(r.color),
    title: String(r.title),
    text: String(r.body),
    time: relative(String(r.created_at)),
    cta: String(r.cta_label ?? 'ABRIR'),
    href: String(r.cta_href ?? 'inicio'),
    fresh: Number(r.fresh) === 1,
  }));
}

/**
 * Próxima janela de manutenção com o contador em segundos calculado agora.
 * Se todas já passaram, avança a última em semanas até cair no futuro.
 */
export async function maintenance(): Promise<Dict> {
  const agora = date('Y-m-d H:i:s');
  const row =
    (await DB.one('SELECT * FROM maintenance_windows WHERE starts_at > :agora ORDER BY starts_at LIMIT 1', { agora })) ??
    (await DB.one('SELECT * FROM maintenance_windows ORDER BY starts_at DESC LIMIT 1'));
  if (row === null) return {};
  let start = strtotime(String(row.starts_at));
  let end = strtotime(String(row.ends_at));
  if (Number.isNaN(start) || Number.isNaN(end)) return {};
  const now = agoraTs();
  while (start <= now) {
    start += 7 * 86400;
    end += 7 * 86400;
  }
  const slug = String(row.news_slug ?? '');
  return {
    title: 'PRÓXIMA MANUTENÇÃO',
    label: 'JANELA ' + date('d/m', start) + ' · ' + date('H', start) + 'H — ' + date('H', end) + 'H',
    note: 'Servidores da ' + String(row.region) + '. ' + String(row.note ?? ''),
    seconds: Math.max(0, start - now),
    href: slug !== '' ? 'noticias/' + slug : 'noticias',
    cta: 'LER AVISO COMPLETO',
  };
}

// --------------------------------------------------------------- mutações

/** Marca a conversa como lida. Devolve false se ela não for do jogador. */
export async function markRead(userId: number, slug: string): Promise<boolean> {
  if (!(await exists(userId, slug))) return false;
  await DB.run('UPDATE message_threads SET unread = 0 WHERE user_id = :u AND slug = :s', { u: userId, s: slug });
  return true;
}

/** Grava a resposta do jogador na conversa. Devolve o texto salvo ou null. */
export async function reply(userId: number, slug: string, text0: string): Promise<string | null> {
  const text = mbSub(text0.replace(/\s+/gu, ' '), MAX_BODY).trim();
  if (text === '') return null;
  const tid = await DB.value('SELECT id FROM message_threads WHERE user_id = :u AND slug = :s', { u: userId, s: slug });
  if (tid === null || tid === undefined) return null;
  // O catálogo semeia horários "de hoje" que podem estar à frente do relógio;
  // a resposta nunca pode cair no meio da conversa.
  const last = String((await DB.value('SELECT MAX(sent_at) FROM message_entries WHERE thread_id = :t', { t: Number(tid) })) ?? '');
  const lastTs = last !== '' ? strtotime(last) : NaN;
  const when = Math.max(agoraTs(), Number.isNaN(lastTs) ? 0 : lastTs + 60);

  await DB.batch([
    DB.insertStmt('message_entries', {
      thread_id: Number(tid),
      author: 'self',
      is_self: 1,
      body: text,
      color: 'lime',
      sent_at: date('Y-m-d H:i:s', when),
    }),
    DB.prepare('UPDATE message_threads SET unread = 0 WHERE id = :t', { t: Number(tid) }),
  ]);
  return text;
}

export async function exists(userId: number, slug: string): Promise<boolean> {
  return (Number(await DB.value('SELECT COUNT(*) FROM message_threads WHERE user_id = :u AND slug = :s', { u: userId, s: slug })) | 0) > 0;
}

// ---------------------------------------------------------------- formato

const hoje = (): string => date('Y-m-d');
const ontem = (): string => date('Y-m-d', agoraTs() - 86400);

/** "HOJE" | "ONTEM" | "27/05" para os separadores da conversa. */
function daySeparator(ymd: string): string {
  if (ymd === hoje()) return 'HOJE';
  if (ymd === ontem()) return 'ONTEM';
  return date('d/m', strtotime(ymd));
}

/** Coluna da direita na lista: hora quando é hoje/ontem, dia/mês antes disso. */
function listStamp(dt: string): string {
  if (dt === '') return '';
  const ts = strtotime(dt);
  const ymd = date('Y-m-d', ts);
  return ymd === hoje() || ymd === ontem() ? date('H:i', ts) : date('d/m', ts);
}

/** Cabeçalho do assunto: "Hoje, 19:44" | "Ontem, 22:41" | "27/05, 11:05". */
function fullStamp(dt: string): string {
  if (dt === '') return '';
  const ts = strtotime(dt);
  const ymd = date('Y-m-d', ts);
  if (ymd === hoje()) return 'Hoje, ' + date('H:i', ts);
  if (ymd === ontem()) return 'Ontem, ' + date('H:i', ts);
  return date('d/m', ts) + ', ' + date('H:i', ts);
}

/** "agora" | "há 12 min" | "há 2 h" | "hoje, 09:00" | "27/05" para os avisos. */
function relative(dt: string): string {
  const ts = strtotime(dt);
  const diff = agoraTs() - ts;
  if (diff < 60) return 'agora';
  if (diff < 3600) return 'há ' + Math.floor(diff / 60) + ' min';
  if (diff < 43200) return 'há ' + Math.floor(diff / 3600) + ' h';
  if (date('Y-m-d', ts) === hoje()) return 'hoje, ' + date('H:i', ts);
  return date('d/m', ts);
}

function preview(lines: Dict[]): string {
  const last = lines.length === 0 ? '' : String(lines[lines.length - 1]!.text);
  const cps = Array.from(last);
  return cps.length > 90 ? cps.slice(0, 89).join('') + '…' : last;
}
