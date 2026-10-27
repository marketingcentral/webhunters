/**
 * Base de TODA tela do painel administrativo — o App\Controllers\Admin\AdminController do PHP.
 *
 * COMO USAR NUMA TELA NOVA (src/controllers/admin/x-controller.ts):
 *
 *   export class JogadoresController extends AdminController {
 *     async index(req: AppRequest, _p: Params): Promise<AppResponse> {
 *       const b = await this.exigirAdmin(); if (b) return b;           // primeira linha, sempre
 *       const page = this.adminPage('jogadores', 'Jogadores', 'Contas, punições e progresso');
 *       return this.render(adminJogadores, page, { linhas: ... });     // layout 'admin' por padrão
 *     }
 *     async salvar(req: AppRequest): Promise<AppResponse> {
 *       const b = await this.exigirAdmin(); if (b) return b;           // em POST é a ÚNICA proteção
 *       this.csrf(req);
 *       ... grava ...
 *       await this.auditar('jogador_atualizado', 'user', id, 'Conta X atualizada.', { campos });
 *       flash('ok', '...'); return this.redirect('admin/jogadores');
 *     }
 *   }
 *   // rota no src/rotas/<lote>.ts:  ['GET', '/admin/jogadores', (r, p) => jog.index(r, p)]
 *
 * DIFERENÇA PARA O PHP: lá a sessão era resolvida no construtor (o Router criava um
 * controller por requisição). Aqui os controllers são instanciados UMA vez por isolate (em
 * src/rotas/*.ts) e servem várias requisições ao mesmo tempo, então NADA da requisição pode
 * ficar em campo da instância. A resolução vive no cache da requisição (ctx().cache), é feita
 * na primeira chamada de exigirAdmin() (que por isso é async) e admin()/adminId() leem dali.
 *
 * O que a base garante (igual ao PHP):
 *   - a conta é RECARREGADA DO BANCO a cada requisição (papel e situação mudam com a sessão aberta);
 *   - sem sessão administrativa -> 303 para /admin/login (com flash);
 *   - com sessão de jogador comum -> 403 (HttpException);
 *   - inatividade > INATIVIDADE_MIN encerra só a sessão do painel (o login do jogo continua);
 *   - render() é a rede de segurança das telas GET; ações POST gravam antes de renderizar,
 *     então nelas o exigirAdmin() no topo é a única proteção;
 *   - pedido com `X-EH-Lista: 1` (assets/js/admin/lista.js) recebe JSON {ok, titulo, regiao}
 *     com só o trecho entre <!--eh:lista--> e <!--/eh:lista--> (core/fragmento.ts).
 *
 * Escopo que a VIEW recebe (Scope de core/view.ts): s.page (com slug 'admin-<slug>', nav,
 * subtitle, actions opcional), s.data (o que a tela mandou + `admin` e `admin_nav`), s.flash,
 * s.csrf. Chaves de s.data lidas pela casca (views/layouts/admin.ts): admin_kpis, admin_alerts.
 */
import { ctx } from '../../core/context';
import * as Fragmento from '../../core/fragmento';
import { dataMock, flash, isAuthenticated, url } from '../../core/helpers';
import { HttpException } from '../../core/http-exception';
import { Router } from '../../core/router';
import { AppResponse } from '../../core/response';
import { APP_NAME } from '../../core/config';
import type { Dict, Page } from '../../core/view';
import * as AdminRepo from '../../repo/admin-repo';
import type { LayoutName } from '../../views/layouts/registry';
import { Controller, type ViewFn } from '../controller';

/** Estado do acesso desta requisição (guardado em ctx().cache). */
interface Acesso {
  /** Conta administrativa (id, username, email, role, status, last_login, last_ip) ou null. */
  conta: Dict | null;
  /** '' = liberado | 'anonimo' = sem sessão | 'proibido' = logado, mas sem acesso. */
  negado: '' | 'anonimo' | 'proibido';
}

const CHAVE = 'admin.acesso';

export abstract class AdminController extends Controller {
  /** Minutos de inatividade que encerram a sessão do painel (o login do jogo continua). */
  protected static readonly INATIVIDADE_MIN = 60;

  // -------------------------------------------------------------------------- a guarda

  /**
   * Devolve a resposta de bloqueio, ou null quando a sessão é válida.
   * Uso SEMPRE na primeira linha: `const b = await this.exigirAdmin(); if (b) return b;`
   * Sessão de jogador sem papel administrativo lança HttpException 403.
   */
  protected async exigirAdmin(): Promise<AppResponse | null> {
    const a = await this.acesso();
    if (a.conta !== null) return null;
    if (a.negado === 'proibido') throw new HttpException(403, 'Área restrita à administração.');
    flash('error', 'Faça login para acessar o painel administrativo.');
    return this.redirect('admin/login');
  }

  /** Conta administrativa logada. Só depois de exigirAdmin() — antes disso é bug da tela. */
  protected admin(): Dict {
    const a = ctx().cache.get(CHAVE) as Acesso | undefined;
    if (!a || a.conta === null) {
      throw new Error('admin() antes da guarda: chame exigirAdmin() no início da ação.');
    }
    return a.conta;
  }

  protected adminId(): number {
    return Number(this.admin().id) | 0;
  }

  /**
   * Uma linha na trilha de auditoria, assinada pelo administrador logado e pelo IP.
   * Toda ação do painel que MUDA alguma coisa passa por aqui (é o que "Logs do Sistema" lê).
   * Nunca lança (AdminRepo.registrar engole a falha).
   */
  protected async auditar(
    acao: string,
    entidade = 'sistema',
    entidadeId: string | number | null = null,
    resumo = '',
    meta: Dict = {},
  ): Promise<void> {
    const a = ctx().cache.get(CHAVE) as Acesso | undefined;
    await AdminRepo.registrar(
      a?.conta ? Number(a.conta.id) | 0 : null,
      acao,
      entidade,
      entidadeId !== null ? String(entidadeId) : null,
      resumo,
      meta,
      this.ip(),
    );
  }

  /**
   * Monta o Page do painel: o slug da VIEW ganha o prefixo `admin-` (nunca colide com uma
   * página do jogo) e o item da sidebar que acende é o slug puro (o de dataMock('admin_nav')).
   * CSS/JS da tela: assets/css/admin/admin-<slug>.css e assets/js/admin/admin-<slug>.js
   * (o layout carrega sozinho quando existem). `over` sobrescreve qualquer campo
   * (ex.: { actions: html`<a class="adm-btn">...` , css: ['admin/upload.css'] }).
   */
  protected adminPage(slug: string, titulo: string, subtitulo = '', over: Partial<Page> & Dict = {}): Page {
    const s = slug.toLowerCase().replace(/[^a-z0-9_-]/g, '') || 'dashboard';
    return this.page('admin-' + s, titulo, {
      slug: 'admin-' + s,
      nav: s,
      subtitle: subtitulo,
      back: null,
      three: { atmosphere: false },
      ...over,
    });
  }

  /**
   * Renderiza no layout 'admin' e injeta o que a casca precisa (bloco do administrador e
   * navegação lateral) — a tela só sobrescreve se mandar as próprias chaves `admin`/`admin_nav`.
   * Com `X-EH-Lista: 1` e layout 'admin', devolve o JSON do fragmento de lista.
   */
  protected override async render(view: ViewFn, page: Page, data: Dict = {}, layout: LayoutName = 'admin', status = 200): Promise<AppResponse> {
    const b = await this.exigirAdmin();
    if (b) return b;
    const d: Dict = { admin: this.blocoAdmin(), admin_nav: dataMock('admin_nav'), ...data };

    if (layout !== 'admin' || !Fragmento.pedido()) {
      return super.render(view, page, d, layout, status);
    }

    // Mesma tela, mesma view: o recorte sai do HTML completo (a casca não tem marcadores).
    // `Vary` é obrigatório: um proxy não pode servir o JSON para uma navegação normal.
    const cheio = await super.render(view, page, d, 'admin', status);
    const regiao = Fragmento.extrair(cheio.body);
    const t = String(page.title ?? '').trim();
    const titulo = (t !== '' ? t + ' · ' : '') + 'Painel · ' + APP_NAME;
    return new AppResponse(JSON.stringify({ ok: regiao !== null, titulo, regiao: regiao ?? '' }), status, {
      'Content-Type': 'application/json; charset=UTF-8',
      Vary: Fragmento.CABECALHO,
    });
  }

  /** Bloco do administrador lido por views/layouts/admin.ts. */
  protected blocoAdmin(): Dict {
    const u = this.admin();
    return {
      name: String(u.username),
      role: AdminRepo.rotuloPapel(String(u.role)),
      env: ctx().debug ? 'Ambiente local' : 'Produção',
      version: 'v1.0',
      logout: url('admin/sair'),
      menu: [],
    };
  }

  /** IP do cliente (CF-Connecting-IP). */
  protected ip(): string {
    return ctx().req.ip();
  }

  /** Router::existeGet — a rota ('admin/logs') já responde a GET? Para links que só acendem quando existem. */
  protected rotaExiste(rota: string): boolean {
    return Router.existeGet(rota);
  }

  // ---------------------------------------------------------------- resolução da sessão

  /** Decide o acesso desta requisição UMA vez (memoizado no cache da requisição). */
  private async acesso(): Promise<Acesso> {
    const c = ctx().cache;
    const pronto = c.get(CHAVE) as Acesso | undefined;
    if (pronto) return pronto;
    const a = await this.resolverSessao();
    c.set(CHAVE, a);
    return a;
  }

  private async resolverSessao(): Promise<Acesso> {
    const s = ctx().session;
    const id = Number(s.get('admin_id') ?? 0) | 0;
    if (id <= 0) {
      // Sessão de jogador aberta não é credencial de painel: vira 403, não redirect.
      return { conta: null, negado: isAuthenticated() ? 'proibido' : 'anonimo' };
    }

    const visto = Number(s.get('admin_seen') ?? 0) | 0;
    if (visto > 0 && AdminRepo.agoraUnix() - visto > AdminController.INATIVIDADE_MIN * 60) {
      await this.encerrar(id, 'sessao_expirada', 'Sessão do painel encerrada por inatividade.');
      flash('error', 'Sua sessão no painel expirou por inatividade.');
      return { conta: null, negado: 'anonimo' };
    }

    const conta = await AdminRepo.porId(id);
    if (!AdminRepo.podeEntrar(conta)) {
      // O papel caiu (ou a conta sumiu/foi banida) com a sessão aberta: derruba na hora.
      await this.encerrar(id, 'acesso_revogado', 'Sessão encerrada: a conta perdeu acesso ao painel.');
      return { conta: null, negado: 'proibido' };
    }

    // O PHP regravava a cada requisição; aqui no máximo uma vez por minuto (cada set() é uma
    // escrita da linha de sessão no D1). A inatividade continua medida com folga de 1 min.
    const agora = AdminRepo.agoraUnix();
    if (agora - visto >= 60) s.set('admin_seen', agora);
    return { conta, negado: '' };
  }

  private async encerrar(id: number, acao: string, resumo: string): Promise<void> {
    await AdminRepo.registrar(id, acao, 'admin', String(id), resumo, {}, this.ip());
    const s = ctx().session;
    s.delete('admin_id');
    s.delete('admin_seen');
  }
}
