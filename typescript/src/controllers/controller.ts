/**
 * Controller base — o App\Controllers\Controller do PHP: monta o contrato $page (SPEC §4) e
 * renderiza pela view. Quando o pedido vem do spa.js (X-EH-Partial: 1) e o layout é 'hud',
 * troca o layout para 'partial', que devolve JSON — nenhum controller precisa saber disso.
 */
import { ctx, memo } from '../core/context';
import { currentUserId, dataMock, flashAll, isAuthenticated, url } from '../core/helpers';
import { HttpException } from '../core/http-exception';
import type { SafeHtml } from '../core/html';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import { checkCsrf, csrfToken } from '../core/security';
import { setCurrentPage, type Dict, type Page, type Scope } from '../core/view';
import * as OperationalRepo from '../repo/operational-repo';
import * as PlayerRepo from '../repo/player-repo';
import * as Settings from '../repo/settings-repo';
import { LAYOUTS, type LayoutName } from '../views/layouts/registry';

/** Fundo padrão por slug (SPEC §9). */
const BG: Record<string, string> = {
  login: 'bg/login.jpg', 'criar-conta': 'bg/login.jpg', 'recuperar-senha': 'bg/login.jpg',
  inicio: 'bg/home-hero.jpg', jogar: 'bg/forest-generic.jpg', lobby: 'bg/lobby.jpg',
  configuracoes: 'bg/settings.jpg', inventario: 'bg/inventario.jpg', saude: 'bg/saude.jpg',
  habilidades: 'bg/forest-generic.jpg', mapa: 'bg/forest-generic.jpg', conquistas: 'bg/conquistas.jpg',
  missoes: 'bg/forest-generic.jpg', mapas: 'bg/jogar-banner.jpg', desafios: 'bg/forest-generic.jpg',
  noticias: 'bg/menu.jpg', loja: 'bg/menu.jpg', amigos: 'bg/lobby.jpg', mensagens: 'bg/menu.jpg',
  perfil: 'bg/home-hero.jpg', 'safe-zone': 'bg/lobby.jpg', suporte: 'bg/forest-generic.jpg',
  'relatorio-de-bug': 'bg/forest-generic.jpg', faccoes: 'bg/forest-generic.jpg', base: 'bg/inventario.jpg',
  '404': 'bg/forest-generic.jpg',
};

/** Que item da topbar acende para telas que não estão nela. */
const NAV_OF: Record<string, string> = {
  jogar: 'inicio', lobby: 'inicio', mapas: 'mapa', desafios: 'missoes', noticias: 'inicio',
  faccoes: 'missoes', base: 'inventario', 'safe-zone': 'inicio',
};

export type ViewFn = (s: Scope) => SafeHtml | Promise<SafeHtml>;

export abstract class Controller {
  protected page(slug: string, title: string, over: Partial<Page> & Dict = {}): Page {
    const back = slug === 'inicio' ? { label: 'SAIR', href: null } : { label: 'VOLTAR', href: url('inicio') };
    const defaults: Page = {
      slug,
      title,
      bg: BG[slug] ?? 'bg/forest-generic.jpg',
      bg_pos: 'center',
      nav: NAV_OF[slug] ?? slug,
      back,
      css: [],
      js: [],
      three: { atmosphere: true, hero: false, map: false },
      description: '',
    };
    const page = { ...defaults, ...over } as Page;
    page.three = { ...defaults.three, ...(over.three ?? {}) };
    return page;
  }

  protected async render(view: ViewFn, page: Page, data: Dict = {}, layout: LayoutName = 'hud', status = 200): Promise<AppResponse> {
    const parcial = layout === 'hud' && ctx().req.wantsPartial();
    setCurrentPage(page);
    const scope: Scope = {
      page,
      player: await this.player(),
      nav: dataMock('nav'),
      server: { ...dataMock('server'), ...(await Settings.rodape()) },
      data,
      flash: flashAll(),
      csrf: csrfToken(),
    };
    const content = await view(scope);
    const body = await LAYOUTS[parcial ? 'partial' : layout](scope, content);
    if (parcial) {
      // Vary: um cache não pode servir o JSON para uma navegação normal.
      return new AppResponse(body, status, { 'Content-Type': 'application/json; charset=UTF-8', Vary: 'X-EH-Partial' });
    }
    return AppResponse.html(body, status);
  }

  /** Jogador atual; sem banco (ou sem login) cai no mock estático, mantendo a HUD navegável. */
  protected player(): Promise<Dict> {
    return memo('controller.player', async () => {
      let player: Dict | null = null;
      try {
        const uid = currentUserId();
        if (uid > 0) player = await PlayerRepo.forUser(uid);
      } catch (e) {
        console.error('[extracthunters] player:', e);
      }
      player ??= dataMock('player');
      // Pré-visualização de estado operacional (?estado=), SÓ em localhost.
      if (ctx().debug) {
        const estado = ctx().req.query('estado');
        if (estado) {
          player!.operational = OperationalRepo.preview(
            (player!.operational as OperationalRepo.Operational) ?? OperationalRepo.defaults(),
            estado,
          );
        }
      }
      return player!;
    });
  }

  protected async operational(): Promise<OperationalRepo.Operational> {
    const op = (await this.player()).operational;
    return op && typeof op === 'object' ? (op as OperationalRepo.Operational) : OperationalRepo.defaults();
  }

  protected userId(): number {
    return currentUserId();
  }

  protected isLogged(): boolean {
    return isAuthenticated();
  }

  protected redirect(path: string, q: Record<string, string | number> = {}): AppResponse {
    return AppResponse.redirect(url(path, q));
  }

  /** Parâmetro opcional de rota contra whitelist: null se ausente, 404 se desconhecido. */
  protected pick(value: string | undefined, allowed: string[], def: string | null = null): string | null {
    if (value === undefined || value === '') return def;
    if (!allowed.includes(value)) throw new HttpException(404, 'Página não encontrada');
    return value;
  }

  protected csrf(req: AppRequest): void {
    checkCsrf(req);
  }
}
