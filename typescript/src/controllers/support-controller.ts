/**
 * SUPORTE e RELATÓRIO DE BUG — o App\Controllers\SupportController do PHP.
 *
 * Datas: as colunas created_at/closed_at do D1 são UTC (datetime('now')); as telas formatam
 * a partir da própria string, como o resto do port (o PHP gravava no fuso do servidor).
 */
import * as DB from '../core/database';
import { dataMock, flash, num } from '../core/helpers';
import { HttpException } from '../core/http-exception';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as SupportRepo from '../repo/support-repo';
import { relatorioDeBug } from '../views/pages/relatorio-de-bug';
import { suporte } from '../views/pages/suporte';
import { Controller } from './controller';

/** Histórico de relatórios do jogador (Fase 2: tudo por user_id). */
const BUG_TABLE = 'player_bug_reports';

/** status guardado no banco => rótulo + cor do chip na view */
const BUG_STATUS: Record<string, [string, string]> = {
  recebido: ['EM FILA', 'cyan'],
  analise: ['EM ANÁLISE', 'amber'],
  corrigido: ['CORRIGIDO', 'lime'],
  duplicado: ['DUPLICADO', 'muted'],
  recusado: ['RECUSADO', 'red'],
};

// ------------------------------------------------------------- strings multibyte (mb_*)
const chars = (s: string): string[] => Array.from(s);
const mbLen = (s: string): number => chars(s).length;
const mbSub = (s: string, ini: number, len?: number): string => chars(s).slice(ini, len === undefined ? undefined : ini + len).join('');

/** rtrim($s, $lista) */
function rtrim(s: string, lista: string): string {
  let fim = s.length;
  while (fim > 0 && lista.includes(s[fim - 1]!)) fim--;
  return s.slice(0, fim);
}

/** Date -> "Y-m-d H:i:s" (UTC, o formato das colunas do D1). */
function sqlData(d: Date): string {
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

/** "Y-m-d H:i:s" -> "d/m/Y" ou "d/m/Y · H:i" (date() do PHP a partir da string). */
function dataBr(s: unknown, comHora: boolean): string {
  let m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s ?? ''));
  if (!m) m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(sqlData(new Date())); // strtotime() falhou: time()
  const [, y, mo, d, h, mi] = m!;
  return comHora ? `${d}/${mo}/${y} · ${h}:${mi}` : `${d}/${mo}/${y}`;
}

/** random_int(1000, 9999) */
const aleatorio = (): number => 1000 + Math.floor(Math.random() * 9000);

export class SupportController extends Controller {
  /** GET/POST /suporte */
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const uid = this.userId();
    const data = await this.supportData(uid);
    const errors: Record<string, string> = {};
    const values: Dict = { subject: '', category: '', message: '', priority: '', logs: true };

    if (req.isPost()) {
      this.csrf(req);
      values.subject = mbSub((req.input('subject', '') ?? '').trim(), 0, 120);
      values.category = req.input('category', '') ?? '';
      values.message = mbSub((req.input('message', '') ?? '').replace(/\r\n/g, '\n').trim(), 0, 2000);
      values.priority = req.input('priority', 'NORMAL') ?? 'NORMAL';
      values.logs = req.input('logs', '') === '1';

      const cats = ((data.categories ?? []) as Dict[]).map((c) => c.slug);
      const prios = ((data.form?.priorities ?? ['NORMAL']) as unknown[]).map((p) => String(p));
      if (!prios.includes(values.priority)) values.priority = String(data.form?.priority ?? 'NORMAL');
      if (mbLen(values.subject) < 4) errors.subject = 'Descreva o assunto com ao menos 4 caracteres.';
      if (!cats.includes(values.category)) errors.category = 'Selecione uma categoria.';
      if (mbLen(values.message) < 20) errors.message = 'Conte mais detalhes (mínimo de 20 caracteres).';
      if (Object.keys(errors).length === 0 && uid <= 0) {
        errors.message = 'Não foi possível registrar o chamado agora. Tente novamente em instantes.';
      }
      if (Object.keys(errors).length === 0) {
        // O stepper ASSUNTO é a própria categoria; o título do chamado sai da
        // primeira linha da descrição, para MEUS CHAMADOS não repetir a categoria.
        const protocol = await SupportRepo.open(uid, { ...values, subject: this.ticketSummary(values) });
        const sla = await SupportRepo.slaHours(values.category);
        flash('ok', 'Chamado #' + protocol + ' aberto. Responderemos em até ' + sla + 'h.');
        return this.redirect('suporte');
      }
    }

    const temErro = Object.keys(errors).length > 0;
    const page = this.page('suporte', 'Suporte');
    return this.render(suporte, page, { ...data, errors, values }, 'hud', temErro ? 422 : 200);
  }

  /**
   * Título curto do chamado: primeira frase/linha da descrição (até 42 caracteres),
   * caindo para o rótulo da categoria quando a descrição não rende um título.
   */
  private ticketSummary(values: Dict): string {
    const msg = String(values.message ?? '').trim();
    let line = (msg.split(/[\n\r]|(?<=[.!?])\s/u)[0] ?? '').trim();
    if (mbLen(line) > 42) {
      const cut = mbSub(line, 0, 42);
      const sp = chars(cut).lastIndexOf(' ');
      line = rtrim(sp > 22 ? mbSub(cut, 0, sp) : cut, ' ,;:-') + '…';
    }
    line = rtrim(line, '.');
    return mbLen(line) >= 4 ? line : String(values.subject ?? 'Chamado');
  }

  /**
   * Monta a tela SUPORTE a partir do banco: FAQ, categorias de chamado, telemetria dos
   * servidores, janela de manutenção e os chamados DO JOGADOR (conciliados por user_id).
   * Os rótulos fixos do formulário seguem em suporte.json. Sem banco, cai inteira para ele.
   */
  private async supportData(uid: number): Promise<Dict> {
    const base: Dict = dataMock('suporte');
    try {
      if (!(await SupportRepo.installed())) return base;
      const [cats, faq, status, tickets, maint, sla, proximo] = await Promise.all([
        SupportRepo.categories(),
        SupportRepo.faq(),
        SupportRepo.status(),
        SupportRepo.ticketsFor(uid, 3), // 3 cabem inteiros no painel; o resto em VER TODOS
        SupportRepo.maintenance(),
        SupportRepo.latestSlaFor(uid),
        SupportRepo.nextProtocol(),
      ]);
      if (cats.length) base.categories = cats;
      if (faq.length) base.faq = faq;
      base.status = status;
      base.tickets = tickets;
      if (maint !== null) base.maintenance = maint;
      else delete base.maintenance;

      // O painel de sucesso só aparece logo após um envio: o prazo citado ali é o SLA
      // real da categoria do chamado recém-gravado, não um número fixo.
      if (sla > 0 && Array.isArray(base.success?.steps) && base.success.steps.length) {
        base.success.steps = (base.success.steps as unknown[]).map((x) => {
          const st = String(x);
          return st.startsWith('Prazo médio de resposta') ? 'Prazo médio de resposta: ' + sla + ' horas.' : st;
        });
      }

      // PROTOCOLO na linha de identificação = o próximo número que será gravado.
      base.ticket_meta = ((base.ticket_meta ?? []) as Dict[]).map((m) =>
        (m.label ?? '') === 'PROTOCOLO' ? { ...m, value: '#' + proximo } : m,
      );
    } catch (e) {
      console.error('[extracthunters] suporte:', e);
      return dataMock('suporte'); // banco indisponível — HUD segue navegável
    }
    return base;
  }

  /** GET/POST /relatorio-de-bug */
  async bug(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    let data: Dict = dataMock('relatorio-de-bug');
    const uid = this.userId();
    const player = await this.player();
    const errors: Record<string, string> = {};
    const values: Record<string, string> = { title: '', area: '', severity: '', description: '', steps: '' };
    let attach = true;

    if (req.isPost()) {
      this.csrf(req);
      for (const k of Object.keys(values)) {
        const bruto = (req.input(k, '') ?? '').replace(/\r\n/g, '\n');
        values[k] = mbSub(bruto, 0, k === 'steps' || k === 'description' ? 3000 : 200).trim();
      }
      attach = req.input('attach_logs', '') === '1';

      const areas = ((data.areas ?? []) as Dict[]).map((a) => a.slug);
      const sev = ((data.severities ?? []) as Dict[]).map((s) => s.slug);
      if (mbLen(values.title!) < 6) errors.title = 'Dê um título com ao menos 6 caracteres.';
      if (!areas.includes(values.area)) errors.area = 'Selecione a área afetada.';
      if (!sev.includes(values.severity)) errors.severity = 'Selecione a gravidade.';
      if (mbLen(values.steps!) < 20) errors.steps = 'Descreva os passos para reproduzir (mínimo de 20 caracteres).';
      if (Object.keys(errors).length === 0) {
        const code = await this.storeBugReport(uid, values, attach);
        flash('ok', 'Relatório ' + code + ' enviado. Obrigado por ajudar a melhorar o jogo.');
        return this.redirect('relatorio-de-bug');
      }
    }

    data = await this.bugSidebar(data, req, uid, player);

    const page = this.page('relatorio-de-bug', 'Relatório de Bug');
    return this.render(relatorioDeBug, page, { ...data, errors, values, attach }, 'hud', Object.keys(errors).length ? 422 : 200);
  }

  /**
   * GET /relatorio-de-bug/{code} — detalhe de UM relatório do jogador logado.
   * O código chega em minúsculas na URL (bug-4812) e é conciliado por user_id + code.
   */
  async bugDetail(req: AppRequest, p: Params = {}): Promise<AppResponse> {
    const code = String(p.code ?? '').trim().toUpperCase();
    const uid = this.userId();
    let data: Dict = dataMock('relatorio-de-bug');
    const detail = await this.bugReport(uid, code, data);
    if (detail === null) throw new HttpException(404, 'Relatório não encontrado');

    data.detail = detail;
    data = await this.bugSidebar(data, req, uid, await this.player());

    const page = this.page('relatorio-de-bug', 'Relatório ' + String(detail.code));
    return this.render(relatorioDeBug, page, { ...data, errors: {}, values: {}, attach: Boolean(detail.attach_raw) }, 'hud');
  }

  /**
   * Coluna direita da tela (idêntica no formulário e no detalhe): histórico do jogador,
   * dados reais da sessão e o SLA calculado dos relatórios já fechados.
   */
  private async bugSidebar(data: Dict, req: AppRequest, uid: number, player: Dict): Promise<Dict> {
    data.recent = await this.bugReports(uid, (data.recent_seed ?? []) as Dict[]);
    data.system = this.sessionRows(req, uid, player);
    data.recent_sla = (await this.bugSla(uid)) ?? String(data.recent_sla ?? '—');
    return data;
  }

  // ---------------------------------------------------------------- relatórios (banco)

  /** Grava o relatório do jogador e devolve o código (BUG-XXXX). */
  private async storeBugReport(uid: number, values: Record<string, string>, attach: boolean): Promise<string> {
    if (uid <= 0) return 'BUG-' + aleatorio();
    try {
      const next = Number(
        await DB.value(`SELECT COALESCE(MAX(CAST(substr(code, 5) AS INTEGER)), 4900) + 1 FROM ${BUG_TABLE}`),
      ) | 0;
      const code = 'BUG-' + String(next).padStart(4, '0');
      await DB.insert(BUG_TABLE, {
        user_id: uid,
        code,
        title: values.title,
        area: values.area,
        severity: values.severity,
        description: values.description,
        steps: values.steps,
        attach_logs: attach ? 1 : 0,
        status: 'recebido',
      });
      return code;
    } catch (e) {
      console.error('[extracthunters] relatorio-de-bug:', e);
      return 'BUG-' + aleatorio();
    }
  }

  /**
   * Relatórios do jogador (mais recentes primeiro), no formato que a view espera.
   * Na primeira visita o histórico de exemplo do catálogo (`recent_seed`) é gravado para
   * aquele user_id — a partir daí tudo vem do banco.
   */
  private async bugReports(uid: number, seed: Dict[]): Promise<Array<Record<string, string>>> {
    if (uid <= 0) return [];
    let rows: Dict[];
    try {
      const has = Number(await DB.value(`SELECT COUNT(*) FROM ${BUG_TABLE} WHERE user_id = :u`, { u: uid })) | 0;
      if (has === 0 && seed.length) await this.seedBugReports(uid, seed);
      rows = await DB.all(
        `SELECT code, title, status, created_at FROM ${BUG_TABLE}
          WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 12`,
        { u: uid },
      );
    } catch (e) {
      console.error('[extracthunters] relatorio-de-bug:', e);
      return [];
    }
    return rows.map((r) => {
      const [label, cls] = BUG_STATUS[String(r.status)] ?? ['EM FILA', 'cyan'];
      return {
        id: String(r.code),
        title: String(r.title),
        status: label,
        cls,
        date: dataBr(r.created_at, false),
      };
    });
  }

  /**
   * UM relatório do jogador (user_id + code), já traduzido para a view.
   * Devolve null quando o código não existe para aquele jogador — a rota vira 404.
   */
  private async bugReport(uid: number, code: string, catalog: Dict): Promise<Dict | null> {
    if (uid <= 0 || code === '') return null;
    let r: Dict | null;
    try {
      r = await DB.one(
        `SELECT code, title, area, severity, description, steps, attach_logs, status, created_at, closed_at
           FROM ${BUG_TABLE} WHERE user_id = :u AND code = :c LIMIT 1`,
        { u: uid, c: code },
      );
    } catch {
      return null;
    }
    if (r === null) return null;

    const areas: Record<string, Dict> = {};
    for (const a of (catalog.areas ?? []) as Dict[]) areas[String(a.slug)] = a;
    const sevs: Record<string, Dict> = {};
    for (const s of (catalog.severities ?? []) as Dict[]) sevs[String(s.slug)] = s;
    const area = areas[String(r.area)] ?? { label: String(r.area).toUpperCase(), icon: 'help' };
    const sev = sevs[String(r.severity)] ?? { label: String(r.severity).toUpperCase(), cls: 'muted', icon: 'warning' };
    const [stLabel, stCls] = BUG_STATUS[String(r.status)] ?? ['EM FILA', 'cyan'];

    return {
      code: String(r.code),
      title: String(r.title),
      status: stLabel,
      status_cls: stCls,
      area_label: String(area.label),
      area_icon: String(area.icon ?? 'help'),
      severity: String(sev.label),
      severity_cls: String(sev.cls ?? 'muted'),
      severity_icon: String(sev.icon ?? 'warning'),
      opened: dataBr(r.created_at, true),
      closed: r.closed_at !== null && r.closed_at !== undefined ? dataBr(r.closed_at, true) : null,
      attach_raw: (Number(r.attach_logs) | 0) === 1,
      description: String(r.description ?? '').trim(),
      steps: String(r.steps ?? '').trim(),
    };
  }

  /** Popula o histórico inicial daquele jogador a partir do catálogo estático. */
  private async seedBugReports(uid: number, seed: Dict[]): Promise<void> {
    for (const s of seed) {
      const days = Number(s.days_ago ?? 0) | 0;
      const status = String(s.status ?? 'recebido');
      const quando = new Date(Date.now() - days * 86400_000);
      const fechado = ['corrigido', 'duplicado', 'recusado'].includes(status)
        ? sqlData(new Date(quando.getTime() + (12 + (days % 24)) * 3600_000))
        : null;
      try {
        await DB.insert(BUG_TABLE, {
          user_id: uid,
          code: String(s.id),
          title: String(s.title),
          area: String(s.area ?? 'outro'),
          severity: String(s.severity ?? 'media'),
          description: String(s.description ?? ''),
          steps: String(s.steps ?? ''),
          attach_logs: 1,
          status,
          created_at: sqlData(quando),
          closed_at: fechado,
        });
      } catch {
        // código duplicado / banco ocupado: segue sem quebrar a tela
      }
    }
  }

  /** Tempo médio de resposta do jogador (relatórios já fechados). */
  private async bugSla(uid: number): Promise<string | null> {
    if (uid <= 0) return null;
    let h: unknown;
    try {
      // TIMESTAMPDIFF(HOUR, a, b): horas inteiras (trunca), linha a linha
      h = await DB.value(
        `SELECT AVG((CAST(strftime('%s', closed_at) AS INTEGER) - CAST(strftime('%s', created_at) AS INTEGER)) / 3600)
           FROM ${BUG_TABLE} WHERE user_id = :u AND closed_at IS NOT NULL`,
        { u: uid },
      );
    } catch {
      return null;
    }
    return h === null || h === undefined ? null : num(Math.round(Number(h))) + ' h';
  }

  // ------------------------------------------------------------- dados da sessão

  /** Painel DADOS DA SESSÃO: identidade real do jogador (banco) + ambiente real da requisição. */
  private sessionRows(req: AppRequest, uid: number, player: Dict): Array<Record<string, string>> {
    const server: Dict = dataMock('server');
    const build = String(dataMock('relatorio-de-bug').tag ?? 'BUILD 2417');
    const pid = uid > 0
      ? `EH-${String(uid).padStart(6, '0')}-${mbSub(String(player.username ?? player.name ?? 'HNT'), 0, 14).toUpperCase()}`
      : 'EH-CONVIDADO';

    return [
      { label: 'VERSÃO DO JOGO', value: '0.8.1 (' + build.toLowerCase() + ')', icon: 'terminal' },
      { label: 'SERVIDOR', value: String(server.region ?? '—') + ' · ' + num(Number(server.ping_ms ?? 0) | 0) + ' ms', icon: 'dns' },
      { label: 'PLATAFORMA', value: this.platform(req.userAgent()), icon: 'desktop_windows' },
      { label: 'JOGADOR', value: String(player.name ?? '—') + ' · NÍVEL ' + num(Number(player.level ?? 0) | 0), icon: 'person' },
      { label: 'ID DO JOGADOR', value: pid, icon: 'badge' },
    ];
  }

  /** Sistema operacional + navegador reais da sessão (a partir do User-Agent). */
  private platform(ua: string): string {
    const sistemas: Array<[string, string]> = [
      ['Windows NT 10.0', 'Windows 10/11'],
      ['Windows NT', 'Windows'],
      ['Mac OS X', 'macOS'],
      ['Android', 'Android'],
      ['Linux', 'Linux'],
    ];
    const navegadores: Array<[string, string]> = [
      ['Edg/', 'Edge'], ['OPR/', 'Opera'], ['Firefox/', 'Firefox'], ['Chrome/', 'Chrome'], ['Safari/', 'Safari'],
    ];
    const os = sistemas.find(([agulha]) => ua.includes(agulha))?.[1] ?? 'Desconhecido';
    const br = navegadores.find(([agulha]) => ua.includes(agulha))?.[1] ?? 'Navegador';
    return os + ' / ' + br;
  }
}
