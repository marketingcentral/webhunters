/**
 * Tela MENSAGENS — o App\Controllers\MessagesController do PHP. Tudo vem do banco
 * conciliado por user_id (MessageRepo):
 *   GET  /mensagens[/{conversa}]  lista + conversa aberta (rota com parâmetro)
 *   POST /mensagens/responder     grava a resposta no banco e volta para a conversa
 *   POST /mensagens/lida          marca como lida (JSON, chamado pelo clique na lista)
 */
import { flash } from '../core/helpers';
import { HttpException } from '../core/http-exception';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as MessageRepo from '../repo/message-repo';
import { mensagens } from '../views/pages/mensagens';
import { Controller } from './controller';

export class MessagesController extends Controller {
  async index(_req: AppRequest, params: Params): Promise<AppResponse> {
    const uid = this.userId();
    const items = await MessageRepo.threads(uid);

    let open = String(params.conversa ?? '');
    if (open !== '' && !(await MessageRepo.exists(uid, open))) {
      throw new HttpException(404, 'Conversa não encontrada');
    }
    if (open === '' && items.length > 0) open = String(items[0]!.id);

    // A conversa aberta pela rota conta como lida a partir de agora.
    if (open !== '') {
      await MessageRepo.markRead(uid, open);
      for (const it of items) {
        if (String(it.id) === open) {
          it.unread = false;
          it.active = true;
        }
      }
    }

    const [counts, unread, notices, maintenance] = await Promise.all([
      MessageRepo.folderCounts(uid),
      MessageRepo.unreadCount(uid),
      MessageRepo.notices(uid),
      MessageRepo.maintenance(),
    ]);
    const active = this.folderOf(items, open);

    const data = {
      title: 'MENSAGENS',
      subtitle: 'Central de comunicação da Resistência',
      unread,

      folders: [
        { slug: 'entrada', label: 'ENTRADA', count: counts.entrada ?? 0, icon: 'mail' },
        { slug: 'sistema', label: 'SISTEMA', count: counts.sistema ?? 0, icon: 'settings' },
        { slug: 'enviadas', label: 'ENVIADAS', count: counts.enviadas ?? 0, icon: 'send' },
      ],
      active_folder: active,
      search_ph: 'Buscar conversa ou operador...',
      list_title: 'CONVERSAS',
      // O botão não compõe nada: leva à lista de operadores, que é de onde uma conversa nasce.
      compose: 'PROCURAR OPERADORES',
      compose_href: 'amigos',
      empty_text: 'Nenhuma conversa nesta pasta.',
      empty_search: 'Nenhuma conversa corresponde à busca.',
      items,
      open,

      // Caixa sem nenhuma conversa: o painel central não pode ficar oco.
      empty_thread: {
        icon: 'forum',
        title: 'NENHUMA CONVERSA AINDA',
        text:
          'Sua caixa está limpa. Adicione operadores à lista de amigos ' +
          'para trocar mensagens — os comunicados da Resistência chegam por aqui.',
        cta: 'PROCURAR OPERADORES',
        href: 'amigos',
      },

      thread_actions: [
        { label: 'Ver perfil', icon: 'account_circle', href: 'perfil' },
        { label: 'Convidar para o lobby', icon: 'group', href: 'lobby' },
        { label: 'Reportar', icon: 'flag', href: 'suporte' },
      ],
      reply_ph: 'Escreva uma resposta...',
      reply_send: 'Enviar resposta',

      system_panel: {
        title: 'MENSAGENS DO SISTEMA',
        action: { label: 'NOTÍCIAS', href: 'noticias', icon: 'chevron_right' },
        items: notices,
        empty: {
          icon: 'inbox',
          title: 'SEM AVISOS',
          text: 'Nenhum comunicado do sistema no momento. Manutenções e eventos aparecem aqui.',
          cta: 'VER NOTÍCIAS',
          href: 'noticias',
        },
      },
      maintenance,
    };

    const page = this.page('mensagens', 'Mensagens', { nav: 'mensagens' });
    return this.render(mensagens, page, data);
  }

  /** POST /mensagens/responder — grava o balão do jogador na conversa aberta. */
  async reply(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const slug = (req.input('conversa', '') ?? '').trim();
    const text = req.input('mensagem', '') ?? '';

    if (slug === '' || !(await MessageRepo.exists(this.userId(), slug))) {
      flash('error', 'Conversa não encontrada.');
      return this.redirect('mensagens');
    }
    if ((await MessageRepo.reply(this.userId(), slug, text)) === null) {
      flash('error', 'Digite uma mensagem antes de enviar.');
      return this.redirect('mensagens/' + slug);
    }
    flash('ok', 'Resposta enviada.');
    return this.redirect('mensagens/' + slug);
  }

  /** POST /mensagens/lida — marca a conversa como lida (chamado ao trocar de thread). */
  async read(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    const slug = (req.input('conversa', '') ?? '').trim();

    if (slug === '' || !(await MessageRepo.markRead(uid, slug))) {
      return AppResponse.json({ ok: false, erro: 'Conversa não encontrada' }, 404);
    }
    const [unread, folders] = await Promise.all([MessageRepo.unreadCount(uid), MessageRepo.folderCounts(uid)]);
    return AppResponse.json({ ok: true, unread, folders });
  }

  /** Pasta que precisa estar ativa para a conversa aberta aparecer na lista. */
  private folderOf(items: Dict[], open: string): string {
    for (const it of items) {
      if (String(it.id) === open) return String(it.folder ?? 'entrada');
    }
    return 'entrada';
  }
}
