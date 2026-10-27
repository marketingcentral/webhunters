/**
 * Tela SUPORTE — o App\Repo\SupportRepo do PHP. Catálogo (faq, support_categories,
 * server_regions, server_services) é igual para todos; os chamados (support_tickets)
 * são conciliados por user_id. Cada função devolve exatamente as chaves que a view
 * de suporte consome do app/Data/suporte.php.
 *
 * O cache estático do PHP (por processo/requisição) vira memo() do contexto da requisição.
 */
import { memo } from '../core/context';
import * as DB from '../core/database';
import { num } from '../core/helpers';
import type { Dict } from '../core/view';

/** Rótulo e tom de cada status de chamado (usados pelo painel MEUS CHAMADOS). */
const STATUS: Record<string, [string, string]> = {
  aberto: ['ABERTO', 'lime'],
  analise: ['EM ANÁLISE', 'amber'],
  resolvido: ['RESOLVIDO', 'lime'],
  fechado: ['FECHADO', 'muted'],
};

/** DATETIME do D1 ("Y-m-d H:i:s", UTC) -> milissegundos; NaN se não reconhecer. */
export function dataMs(s: unknown): number {
  const t = String(s ?? '').trim();
  if (t === '') return NaN;
  return Date.parse(t.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(t) ? '' : 'Z'));
}

export function installed(): Promise<boolean> {
  return memo('support.installed', async () => {
    try {
      await DB.value('SELECT 1 FROM support_categories LIMIT 1');
      return true;
    } catch {
      return false;
    }
  });
}

export function faq(): Promise<Array<{ q: string; a: string }>> {
  return memo('support.faq', async () =>
    (await DB.all('SELECT question, answer FROM faq ORDER BY sort_order, id')).map((r) => ({
      q: String(r.question),
      a: String(r.answer),
    })),
  );
}

export function categories(): Promise<Array<{ slug: string; label: string; sla: string }>> {
  return memo('support.categories', async () =>
    (await DB.all('SELECT slug, label, sla_hours FROM support_categories ORDER BY sort_order, id')).map((r) => ({
      slug: String(r.slug),
      label: String(r.label),
      sla: (Number(r.sla_hours) | 0) + 'H',
    })),
  );
}

/** Painel de status: regiões + serviços, com o selo geral derivado dos estados reais. */
export function status(): Promise<Dict> {
  return memo('support.status', async () => {
    const [regRows, svcRows] = await Promise.all([
      DB.all('SELECT label, ping_ms, players, state FROM server_regions ORDER BY sort_order, id'),
      DB.all('SELECT label, state, status_label, short_label FROM server_services ORDER BY sort_order, id'),
    ]);
    const regions = regRows.map((r) => ({
      label: String(r.label),
      ping: Number(r.ping_ms) | 0,
      players: num(Number(r.players) | 0),
      state: String(r.state),
    }));
    let degraded = 0;
    const services = svcRows.map((r) => {
      if (String(r.state) !== 'ok') degraded++;
      return {
        label: String(r.label),
        value: String(r.status_label),
        short: String(r.short_label),
        state: String(r.state),
      };
    });
    return {
      label: 'STATUS DOS SERVIDORES',
      value: degraded === 0 ? 'Todos os sistemas operacionais' : degraded + ' serviço(s) em manutenção',
      badge: degraded === 0 ? 'OPERACIONAL' : 'PARCIAL',
      ok: degraded === 0,
      updated: 'ATUALIZADO HÁ',
      regions,
      services_title: 'SERVIÇOS',
      services,
    };
  });
}

/**
 * Próxima janela de manutenção (serviço fora do estado ok com horário marcado).
 * `starts_in` em segundos, recalculado a cada requisição — é o que a HUD conta.
 */
export function maintenance(): Promise<Dict | null> {
  return memo('support.maintenance', async () => {
    const r = await DB.one(
      `SELECT label, maint_scope, maint_starts_at, maint_minutes
         FROM server_services
        WHERE state <> :ok AND maint_starts_at IS NOT NULL
        ORDER BY maint_starts_at LIMIT 1`,
      { ok: 'ok' },
    );
    if (r === null) return null;
    const ms = dataMs(r.maint_starts_at);
    const inicio = Number.isNaN(ms) ? 0 : Math.floor(ms / 1000);
    const dentro = inicio - Math.floor(Date.now() / 1000);
    return {
      title: 'MANUTENÇÃO PROGRAMADA',
      service: String(r.maint_scope || r.label),
      desc: 'Janela de ' + (Number(r.maint_minutes ?? 0) | 0) + ' minutos.',
      label: dentro > 0 ? 'INÍCIO EM' : 'EM ANDAMENTO HÁ',
      starts_in: Math.max(0, dentro),
    };
  });
}

/** Chamados do jogador, mais recentes primeiro. */
export async function ticketsFor(uid: number, limit = 4): Promise<Array<Record<string, string>>> {
  if (uid <= 0) return [];
  const rows = await DB.all(
    `SELECT protocol, subject, status, created_at
       FROM support_tickets WHERE user_id = :u
      ORDER BY created_at DESC, id DESC LIMIT ${Math.max(1, Math.min(20, limit | 0))}`,
    { u: uid },
  );
  return rows.map((r) => {
    const [label, tone] = STATUS[String(r.status)] ?? ['ABERTO', 'lime'];
    // date('d/m', strtotime(created_at)) — formatado a partir da própria string
    const m = /^\d{4}-(\d{2})-(\d{2})/.exec(String(r.created_at ?? ''));
    const hoje = new Date();
    const data = m ? `${m[2]}/${m[1]}` : `${String(hoje.getUTCDate()).padStart(2, '0')}/${String(hoje.getUTCMonth() + 1).padStart(2, '0')}`;
    return {
      id: '#' + (Number(r.protocol) | 0),
      subject: String(r.subject),
      state: label,
      tone,
      date: data,
    };
  });
}

/** Próximo protocolo livre — mostrado na linha de identificação antes do envio. */
export async function nextProtocol(): Promise<number> {
  const max = Number((await DB.value('SELECT MAX(protocol) FROM support_tickets')) ?? 0) | 0;
  return Math.max(48000, max + 1);
}

/** Abre um chamado para o jogador. Devolve o protocolo gravado. */
export async function open(uid: number, v: Dict): Promise<number> {
  const protocol = await nextProtocol();
  await DB.insert('support_tickets', {
    user_id: uid,
    protocol,
    subject: String(v.subject),
    category: String(v.category),
    priority: String(v.priority ?? 'NORMAL'),
    message: String(v.message),
    attach_logs: v.logs ? 1 : 0,
    status: 'aberto',
  });
  return protocol;
}

/** SLA (em horas) do chamado mais recente do jogador; 0 quando não há nenhum. */
export async function latestSlaFor(uid: number): Promise<number> {
  if (uid <= 0) return 0;
  const cat = String(
    (await DB.value('SELECT category FROM support_tickets WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 1', { u: uid })) ?? '',
  );
  return cat === '' ? 0 : slaHours(cat);
}

/** SLA (em horas) da categoria, para compor a mensagem de sucesso. */
export async function slaHours(slug: string): Promise<number> {
  for (const c of await categories()) {
    if (c.slug === slug) return parseInt(c.sla, 10) || 0; // (int)'12H' = 12
  }
  return 24;
}
