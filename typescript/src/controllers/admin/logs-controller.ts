/**
 * Tela Logs do Sistema do painel: /admin/logs — o App\Controllers\Admin\LogsController do PHP.
 *
 * Governa admin_audit_log (LEITURA) + users (só para dar nome a quem fez).
 *
 * ESTA TELA NÃO ESCREVE — E ISSO É A FUNCIONALIDADE. `salvar()` e `excluir()` existem porque
 * as rotas POST estão registradas, mas RECUSAM — e a própria recusa vira linha na trilha.
 */
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import { formatarData, paraUnix } from '../../repo/admin-repo';
import * as AuditRepo from '../../repo/audit-repo';
import { adminLogs } from '../../views/pages/admin-logs';
import { AdminController } from './admin-controller';

export class LogsController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    const filtro = AuditRepo.normalizar({
      q: req.query('q'),
      acao: req.query('acao'),
      quem: req.query('quem'),
      de: req.query('de'),
      ate: req.query('ate'),
      ord: req.query('ord'),
      dir: req.query('dir'),
      pag: req.query('pag'),
      log: req.query('log'),
    });

    // As leituras são independentes: em paralelo (cada uma é uma ida ao D1).
    const [lista, opcoes, detalhe] = await Promise.all([
      AuditRepo.pagina(filtro),
      AuditRepo.opcoes(),
      // O detalhe é um id na URL: o registro aberto vira endereço compartilhável.
      filtro.log > 0 ? AuditRepo.porId(filtro.log) : Promise.resolve(null),
    ]);
    // O resumo só é consultado quando há o que resumir.
    const resumo = lista.total > 0 ? await AuditRepo.resumo(filtro) : { acoes: [], contas: 0, primeiro: '', ultimo: '' };

    if (filtro.log > 0 && detalhe === null) {
      flash('error', String(dataMock('admin-logs')?.detalhe?.nao_achado ?? 'Registro não encontrado.'));
    }

    const cromo = dataMock('admin-logs') as Dict;
    const page = this.adminPage('logs', 'Logs do Sistema', String(cromo.micro?.somente_leitura ?? 'Somente leitura.'));

    return this.render(adminLogs, page, {
      cromo,
      filtro,
      lista,
      opcoes,
      resumo,
      detalhe,
      tem_filtro: AuditRepo.temFiltro(filtro),
      // A casca lê esta chave: os KPIs falam DESTA tela.
      admin_kpis: this.kpisDaTopbar(opcoes, lista, resumo),
    });
  }

  /** A trilha é apenas acrescida (por AdminRepo.registrar), nunca editada. */
  async salvar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    return this.recusar(req, 'editar');
  }

  /** Nem o dono do jogo apaga uma linha da trilha por aqui. */
  async excluir(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    return this.recusar(req, 'excluir');
  }

  /**
   * Recusa a escrita, registra a tentativa e devolve o operador para a tela. A guarda e o
   * CSRF vêm ANTES do registro: senão qualquer site poluiria a trilha com um form oculto.
   */
  private async recusar(req: AppRequest, tentativa: string): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    await this.auditar(
      'escrita_recusada',
      'admin_audit_log',
      req.input('id'),
      'Tentativa de ' + tentativa + ' na trilha de auditoria, recusada pela tela de logs.',
      { rota: req.path(), tentativa },
    );

    const cromo = dataMock('admin-logs') as Dict;
    flash('error', String(cromo.micro?.somente_leitura ?? 'A trilha de auditoria é somente leitura.'));
    return this.redirect('admin/logs');
  }

  /** Os três indicadores compactos da topbar, todos medidos nesta requisição. */
  private kpisDaTopbar(opcoes: { total: number }, lista: AuditRepo.PaginaAudit, resumo: { ultimo: string }): Dict[] {
    const ultimo = String(resumo.ultimo ?? '');
    return [
      { icon: 'receipt_long', label: 'Registros na trilha', value: lista.falha ? null : num(opcoes.total), tone: 'lime' },
      { icon: 'filter_alt', label: 'No recorte', value: lista.falha ? null : num(lista.total), tone: 'cyan' },
      { icon: 'schedule', label: 'Último registro', value: ultimo !== '' ? formatarData(paraUnix(ultimo), 'd/m H:i') : null, tone: 'amber' },
    ];
  }
}
