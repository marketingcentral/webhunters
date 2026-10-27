/**
 * Tela AMIGOS — o App\Controllers\FriendsController do PHP. Listas, contadores e pedidos vêm
 * da tabela `friends` conciliada por user_id (FriendsRepo); src/data/amigos.json entra só
 * com os textos fixos da interface e como fallback quando o banco falha.
 */
import { dataMock, flash } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as FriendsRepo from '../repo/friends-repo';
import { amigos } from '../views/pages/amigos';
import { Controller } from './controller';

const TABS = ['online', 'offline', 'pedidos'];

export class FriendsController extends Controller {
  async index(_req: AppRequest, params: Params): Promise<AppResponse> {
    const tab = this.pick(params.aba, TABS, 'online')!;
    const data = await this.build(tab);
    const page = this.page('amigos', 'Amigos', { nav: 'amigos' });
    return this.render(amigos, page, data);
  }

  /** POST /amigos: envia convite e responde pedidos pendentes, gravando em `friends`. */
  async store(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    const action = req.input('acao', 'convidar') ?? 'convidar';
    const target = FriendsRepo.cleanName(req.input('jogador', '') ?? '');
    const self = String((await this.player()).name ?? '');

    let r: FriendsRepo.Flash;
    switch (action) {
      case 'aceitar':
        r = await FriendsRepo.accept(uid, target);
        break;
      case 'recusar':
        r = await FriendsRepo.decline(uid, target);
        break;
      case 'cancelar':
        r = await FriendsRepo.cancel(uid, target);
        break;
      default:
        r = await FriendsRepo.invite(uid, target, self);
    }
    const [kind, message] = r;
    flash(kind, message);

    // volta para a aba em que a ação aconteceu
    const tab = action === 'convidar' ? (kind === 'ok' ? 'pedidos' : 'online') : 'pedidos';
    return this.redirect('amigos/' + tab);
  }

  /** Monta o $data da view: textos de src/data + listas do banco (com fallback estático). */
  private async build(tab: string): Promise<Dict> {
    const data = dataMock('amigos');
    const uid = this.userId();

    let online: Dict[] = Array.isArray(data.items) ? data.items : [];
    let offline: Dict[] = [];
    let pending: Dict[] = Array.isArray(data.pending) ? data.pending : [];
    let squad: Dict[] = Array.isArray(data.squad?.items) ? data.squad.items : [];
    let fromDb = false;

    try {
      // uid 0 = sem conta; aí sim cai no arquivo estático
      if (uid > 0) {
        const social = await FriendsRepo.forUser(uid);
        online = social.online;
        offline = social.offline;
        pending = social.pending;
        squad = social.squad;
        fromDb = true;
      }
    } catch (e) {
      console.error('[extracthunters] amigos:', e);
      fromDb = false; // banco indisponível — segue com o fallback estático
    }

    if (!fromDb) {
      // fallback: separa as listas do arquivo estático do mesmo jeito que o repositório
      const all = online;
      online = all.filter((f) => String(f.status ?? '') !== 'offline');
      offline = all.filter((f) => String(f.status ?? '') === 'offline');
    }

    const counts = { online: online.length, offline: offline.length, pedidos: pending.length };
    data.online = counts.online;
    data.total = counts.online + counts.offline;
    data.tab = tab;
    data.items_online = online;
    data.items_offline = offline;
    data.pending = pending;
    data.squad = { ...(data.squad ?? {}), items: squad };
    data.tabs = [
      { slug: 'online', label: 'ONLINE', count: counts.online },
      { slug: 'offline', label: 'OFFLINE', count: counts.offline },
      { slug: 'pedidos', label: 'PEDIDOS', count: counts.pedidos },
    ];
    // o "SEU ID" é do jogador logado, não um número congelado
    data.add = { ...(data.add ?? {}), id_suffix: '#' + String(Math.max(uid, 0)).padStart(4, '0') };
    return data;
  }
}
