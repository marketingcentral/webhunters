/**
 * Tela API do painel: /admin/api — o App\Controllers\Admin\ApiController do PHP.
 *
 * Governa `api_keys` (criar e revogar) + `api_request_log` (leitura) + a REFERÊNCIA dos
 * endpoints, montada por ApiDespachante.referencia() a partir dos recursos vivos (é a MESMA
 * tabela que o despachante usa para atender — documentação escrita à mão envelhece calada).
 *
 * O SEGREDO E O REDIRECT: criar é POST e termina em redirect (recarregar não cria segunda
 * chave). O segredo só existe naquela requisição, então viaja UMA vez pela sessão do
 * operador (`api_chave_nova`) e é consumido na primeira leitura. Nunca vai para a querystring
 * nem para o banco (lá só há o hash).
 */
import * as ApiDespachante from '../../api/api-controller';
import * as Escopos from '../../api/escopos';
import { ctx } from '../../core/context';
import { dataMock, flash, num } from '../../core/helpers';
import type { AppRequest } from '../../core/request';
import type { AppResponse } from '../../core/response';
import type { Params } from '../../core/router';
import type { Dict } from '../../core/view';
import * as ApiKeyRepo from '../../repo/api-key-repo';
import * as ApiLogRepo from '../../repo/api-log-repo';
import { adminApi } from '../../views/pages/admin-api';
import { AdminController } from './admin-controller';

/** sprintf só com %s, na ordem. */
function sprintf(fmt: string, ...args: string[]): string {
  let i = 0;
  return fmt.replace(/%s/g, () => args[i++] ?? '');
}

export class ApiController extends AdminController {
  async index(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;

    let estado = req.query('estado', '') ?? '';
    estado = Object.prototype.hasOwnProperty.call(ApiKeyRepo.ESTADOS, estado) ? estado : '';

    const inteiro = (v: string | null) => Number.parseInt(v ?? '', 10) || 0;
    const statusQ = req.query('status', '') ?? '';
    const filtroLog = {
      chave: Math.max(0, inteiro(req.query('chave', '0'))),
      status: ['ok', 'recusado', 'erro'].includes(statusQ) ? statusQ : '',
      pag: Math.max(1, inteiro(req.query('lpag', '1'))),
    };

    // Consultas independentes: em paralelo (cada uma é uma ida ao D1).
    const [chaves, contagens, log, resumo] = await Promise.all([
      ApiKeyRepo.pagina(Math.max(1, inteiro(req.query('pag', '1'))), estado),
      ApiKeyRepo.contagens(),
      ApiLogRepo.pagina(filtroLog),
      ApiLogRepo.resumo24h(),
    ]);

    const cromo = dataMock('admin-api') as Dict;
    const page = this.adminPage('api', String(cromo.titulo ?? 'API'), String(cromo.subtitulo ?? ''));

    return this.render(adminApi, page, {
      cromo,
      chaves,
      contagens,
      estado,
      log,
      filtro_log: filtroLog,
      resumo,
      escopos: Escopos.agrupados(),
      referencia: ApiDespachante.referencia(),
      // A origem absoluta: o exemplo de `curl` só serve se puder ser copiado inteiro.
      origem: this.origem(req),
      limite_padrao: ApiKeyRepo.LIMITE_PADRAO,
      limite_max: ApiKeyRepo.LIMITE_MAX,
      // A chave recém-criada, consumida na primeira leitura (ver o cabeçalho).
      segredo: this.consumirSegredo(),
      // Erros e valores do formulário que não passou, para ele voltar preenchido.
      form: this.consumir('api_form'),
      admin_kpis: this.kpisDaTopbar(contagens, resumo),
    });
  }

  /** Cria uma chave. É a única ação do painel inteiro que devolve um segredo à tela. */
  async criar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const bruto = {
      name: req.input('name', '') ?? '',
      scopes: req.inputArray('scopes'),
      rate_limit_per_min: req.input('rate_limit_per_min', '') ?? '',
      note: req.input('note', '') ?? '',
      expires_at: req.input('expires_at', '') ?? '',
    };

    const v = ApiKeyRepo.validarNova(bruto);
    if (!v.ok) {
      // O que o operador digitou volta com ele (inclusive os escopos marcados).
      ctx().session.set('api_form', { erros: v.erros, valores: bruto });
      flash('error', 'Confira os campos destacados.');
      return this.redirect('admin/api');
    }

    const nova = await ApiKeyRepo.criar(v.dados, this.adminId());

    await this.auditar(
      'api_chave_criada',
      'api_keys',
      nova.id,
      sprintf('Criou a chave de API "%s" (%s).', v.dados.name, nova.prefix),
      // Os ESCOPOS vão para a trilha; a chave, nunca.
      { prefix: nova.prefix, scopes: v.dados.scopes, expires_at: v.dados.expires_at },
    );

    ctx().session.set('api_chave_nova', { chave: nova.chave, nome: v.dados.name, prefixo: nova.prefix });
    flash('ok', 'Chave criada. Copie agora — ela não será mostrada de novo.');
    return this.redirect('admin/api');
  }

  /** Revoga uma chave. Não apaga: a linha continua, marcada como revogada. */
  async revogar(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    this.csrf(req);

    const id = Number.parseInt(req.input('id', '0') ?? '0', 10) || 0;
    const chave = await ApiKeyRepo.porId(id);
    if (chave === null) {
      flash('error', 'Chave não encontrada.');
      return this.redirect('admin/api');
    }

    if (!(await ApiKeyRepo.revogar(id, this.adminId()))) {
      // A condição mora no próprio UPDATE: dois operadores ao mesmo tempo não se sobrescrevem.
      flash('error', 'Esta chave já estava revogada.');
      return this.redirect('admin/api');
    }

    await this.auditar(
      'api_chave_revogada',
      'api_keys',
      id,
      sprintf('Revogou a chave de API "%s" (%s).', chave.nome, chave.prefixo),
      { prefix: chave.prefixo, usos: chave.usos },
    );

    flash('ok', 'Chave revogada. A próxima chamada com ela recebe 401.');
    return this.redirect('admin/api');
  }

  // ===================================================================== apoio

  /** Lê e APAGA o segredo da sessão: recarregar a página não mostra a chave de novo. */
  private consumirSegredo(): Dict | null {
    const s = this.consumir('api_chave_nova');
    return s === null ? null : { chave: String(s.chave ?? ''), nome: String(s.nome ?? ''), prefixo: String(s.prefixo ?? '') };
  }

  /** Esquema + host desta instalação (o Host vem do cliente: só o que parece host entra). */
  private origem(req: AppRequest): string {
    let host = req.host() || 'localhost';
    if (!/^[A-Za-z0-9.-]{1,190}(:\d{1,5})?$/.test(host)) host = 'localhost';
    return (req.isHttps() ? 'https' : 'http') + '://' + host;
  }

  private consumir(chave: string): Dict | null {
    const s = ctx().session;
    const v = s.get(chave);
    s.delete(chave);
    return v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : null;
  }

  /** Os três indicadores da topbar: chaves de pé, uso nas 24 h e chamadas RECUSADAS. */
  private kpisDaTopbar(c: { ativas: number }, r: { total: number; recusadas: number }): Dict[] {
    return [
      { icon: 'key', label: 'Chaves ativas', value: num(c.ativas), tone: 'lime' },
      { icon: 'swap_horiz', label: 'Chamadas (24 h)', value: num(r.total), tone: 'cyan' },
      { icon: 'block', label: 'Recusadas (24 h)', value: num(r.recusadas), tone: r.recusadas > 0 ? 'amber' : 'lime' },
    ];
  }
}
