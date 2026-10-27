/**
 * Tela principal do painel: /admin — o App\Controllers\Admin\DashboardController do PHP.
 *
 * O controller não calcula NADA: escolhe a janela do gráfico, pede os blocos ao AdminRepo e
 * entrega para a view. O único trabalho de verdade daqui é decidir quais ATALHOS viram link
 * (rota conferida contra a tabela de rotas em runtime — quando outro lote registrar
 * /admin/itens, o atalho acende sozinho), e montar os KPIs/alertas da topbar.
 */
import { dataMock, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as AdminRepo from '../../repo/admin-repo';
import { adminDashboard } from '../../views/pages/admin-dashboard';
import { AdminController } from './admin-controller';

/** Janelas aceitas em ?dias=N. Fora da lista, cai para 7. */
const JANELAS = [7, 14, 30];

export class DashboardController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    let dias = Number.parseInt(req.query('dias') ?? '0', 10) || 0;
    if (!JANELAS.includes(dias)) dias = JANELAS[0]!;

    const cromo = dataMock('admin-dashboard') as Dict;
    // "Ver tudo" para tela que ainda não existe não vira link.
    cromo.links = this.marcarLinks((cromo.links ?? {}) as Dict);

    // As consultas são independentes: em paralelo (cada uma é uma ida ao D1).
    const [servidores, kpis, serie, ranking, receita, eventos, atividade, sistema] = await Promise.all([
      AdminRepo.statusServidores(),
      AdminRepo.kpis(),
      AdminRepo.serieOnline(dias),
      AdminRepo.topJogadores(5),
      AdminRepo.receita(30),
      // Três eventos e quatro entradas: é o que cabe INTEIRO nos dois cartões (sem rolagem).
      AdminRepo.eventosAtivos(3),
      AdminRepo.atividadeRecente(4),
      AdminRepo.statusSistema(),
    ]);

    const page = this.adminPage('dashboard', 'Painel Admin', 'Visão geral do seu jogo');
    return this.render(adminDashboard, page, {
      cromo,
      admin_conta: this.admin(),
      dias,
      kpis,
      serie,
      servidores,
      ranking,
      receita,
      eventos,
      atividade,
      sistema,
      acoes: this.atalhos((cromo.acoes ?? []) as Dict[]),
      // A casca do painel (views/layouts/admin.ts) lê estas duas chaves.
      admin_kpis: this.kpisDaTopbar(kpis, servidores),
      admin_alerts: await this.alertas(servidores),
    });
  }

  /** Marca cada atalho com `disponivel`, conferindo a rota contra a tabela de rotas. */
  private atalhos(acoes: Dict[]): Dict[] {
    return acoes.map((a) => ({ ...a, disponivel: this.rotaExiste(String(a.rota ?? '')) }));
  }

  /** Marca cada link de rodapé com `disponivel` (sem rota, a view não desenha o link). */
  private marcarLinks(links: Dict): Dict {
    const out: Dict = {};
    for (const [k, l] of Object.entries(links)) out[k] = { ...(l as Dict), disponivel: this.rotaExiste(String((l as Dict).rota ?? '')) };
    return out;
  }

  /** Os três indicadores compactos da topbar (o terceiro é "serviços no ar", não uptime inventado). */
  private kpisDaTopbar(kpis: Dict[], servidores: Dict): Dict[] {
    const por: Record<string, Dict> = {};
    for (const k of kpis) por[String(k.chave)] = k;
    const online = por.online ?? null;
    const receita = por.receita ?? null;
    const servicos = (servidores.servicos ?? []) as Dict[];
    const noAr = servicos.filter((s) => (s.estado ?? '') === 'online').length;
    const total = servicos.length;
    return [
      { icon: 'group', label: 'Jogadores online', value: online !== null ? num(Number(online.valor) | 0) : null, sub: online?.sub ?? null, tone: 'lime' },
      { icon: 'payments', label: 'Receita (mês)', value: receita !== null ? 'R$ ' + num(Math.trunc(Number(receita.valor)) / 100, 2) : null, tone: 'purple' },
      { icon: 'dns', label: 'Serviços no ar', value: total > 0 ? noAr + '/' + total : null, tone: total > 0 && noAr === total ? 'cyan' : 'amber' },
    ];
  }

  /**
   * O sino só toca por fato registrado: serviço fora do padrão, região com janela de
   * manutenção e falhas de login no painel (deste IP) nas últimas 24 h.
   */
  private async alertas(servidores: Dict): Promise<Dict[]> {
    const alertas: Dict[] = [];
    for (const s of (servidores.servicos ?? []) as Dict[]) {
      if ((s.estado ?? 'online') === 'online') continue;
      alertas.push({
        icon: s.estado === 'fora' ? 'error' : 'build',
        tone: s.estado === 'fora' ? 'red' : 'amber',
        text: s.label + ': ' + s.rotulo,
        meta: s.escopo ?? null,
      });
    }
    for (const r of (servidores.regioes ?? []) as Dict[]) {
      if ((r.nota ?? null) === null) continue;
      alertas.push({
        icon: 'schedule',
        tone: ['manutencao', 'agendada'].includes(String(r.estado)) ? 'amber' : 'cyan',
        text: r.label + ': ' + r.nota,
        meta: null,
      });
    }
    const falhas = await AdminRepo.falhasRecentes(this.ip(), 24 * 60);
    if (falhas.n > 0) {
      alertas.push({
        icon: 'shield_lock',
        tone: 'red',
        text: falhas.n + ' falha(s) de login no painel neste IP nas últimas 24 h',
        meta: 'Última: ' + AdminRepo.formatarData(falhas.ultima, 'd/m H:i'),
      });
    }
    return alertas;
  }
}
