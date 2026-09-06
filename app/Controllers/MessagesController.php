<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repo\MessageRepo;

/**
 * Tela MENSAGENS. Tudo vem do banco conciliado por user_id (MessageRepo):
 *   GET  /mensagens[/{conversa}]  lista + conversa aberta (rota com parâmetro)
 *   POST /mensagens/responder     grava a resposta no banco e volta para a conversa
 *   POST /mensagens/lida          marca como lida (JSON, chamado pelo clique na lista)
 */
final class MessagesController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid   = $this->userId();
        $items = MessageRepo::threads($uid);

        $open = (string)($params['conversa'] ?? '');
        if ($open !== '' && !MessageRepo::exists($uid, $open)) {
            throw new HttpException(404, 'Conversa não encontrada');
        }
        if ($open === '' && $items !== []) {
            $open = (string)$items[0]['id'];
        }

        // A conversa aberta pela rota conta como lida a partir de agora.
        if ($open !== '') {
            MessageRepo::markRead($uid, $open);
            foreach ($items as $i => $it) {
                if ((string)$it['id'] === $open) {
                    $items[$i]['unread'] = false;
                    $items[$i]['active'] = true;
                }
            }
        }

        $counts = MessageRepo::folderCounts($uid);
        $active = $this->folderOf($items, $open);

        $data = [
            'title'    => 'MENSAGENS',
            'subtitle' => 'Central de comunicação da Resistência',
            'unread'   => MessageRepo::unreadCount($uid),

            'folders' => [
                ['slug' => 'entrada',  'label' => 'ENTRADA',  'count' => $counts['entrada']  ?? 0, 'icon' => 'mail'],
                ['slug' => 'sistema',  'label' => 'SISTEMA',  'count' => $counts['sistema']  ?? 0, 'icon' => 'settings'],
                ['slug' => 'enviadas', 'label' => 'ENVIADAS', 'count' => $counts['enviadas'] ?? 0, 'icon' => 'send'],
            ],
            'active_folder' => $active,
            'search_ph'     => 'Buscar conversa ou operador...',
            'list_title'    => 'CONVERSAS',
            // O botão não compõe nada: ele leva à lista de operadores, que é de onde
            // uma conversa nasce. O rótulo diz exatamente para onde vai.
            'compose'       => 'PROCURAR OPERADORES',
            'compose_href'  => 'amigos',
            'empty_text'    => 'Nenhuma conversa nesta pasta.',
            'empty_search'  => 'Nenhuma conversa corresponde à busca.',
            'items'         => $items,
            'open'          => $open,

            // Caixa sem nenhuma conversa: o painel central não pode ficar oco.
            'empty_thread' => [
                'icon'  => 'forum',
                'title' => 'NENHUMA CONVERSA AINDA',
                'text'  => 'Sua caixa está limpa. Adicione operadores à lista de amigos '
                         . 'para trocar mensagens — os comunicados da Resistência chegam por aqui.',
                'cta'   => 'PROCURAR OPERADORES',
                'href'  => 'amigos',
            ],

            'thread_actions' => [
                ['label' => 'Ver perfil',            'icon' => 'account_circle', 'href' => 'perfil'],
                ['label' => 'Convidar para o lobby', 'icon' => 'group',          'href' => 'lobby'],
                ['label' => 'Reportar',              'icon' => 'flag',           'href' => 'suporte'],
            ],
            'reply_ph'   => 'Escreva uma resposta...',
            'reply_send' => 'Enviar resposta',

            'system_panel' => [
                'title'  => 'MENSAGENS DO SISTEMA',
                'action' => ['label' => 'NOTÍCIAS', 'href' => 'noticias', 'icon' => 'chevron_right'],
                'items'  => MessageRepo::notices($uid),
                'empty'  => [
                    'icon'  => 'inbox',
                    'title' => 'SEM AVISOS',
                    'text'  => 'Nenhum comunicado do sistema no momento. Manutenções e eventos aparecem aqui.',
                    'cta'   => 'VER NOTÍCIAS',
                    'href'  => 'noticias',
                ],
            ],
            'maintenance' => MessageRepo::maintenance(),
        ];

        $page = $this->page('mensagens', 'Mensagens', ['nav' => 'mensagens']);
        return $this->render('mensagens', $page, $data);
    }

    /** POST /mensagens/responder — grava o balão do jogador na conversa aberta. */
    public function reply(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $slug = trim((string)$request->input('conversa', ''));
        $text = (string)$request->input('mensagem', '');

        if ($slug === '' || !MessageRepo::exists($this->userId(), $slug)) {
            flash('error', 'Conversa não encontrada.');
            return $this->redirect('mensagens');
        }
        if (MessageRepo::reply($this->userId(), $slug, $text) === null) {
            flash('error', 'Digite uma mensagem antes de enviar.');
            return $this->redirect('mensagens/' . $slug);
        }

        flash('ok', 'Resposta enviada.');
        return $this->redirect('mensagens/' . $slug);
    }

    /** POST /mensagens/lida — marca a conversa como lida (chamado ao trocar de thread). */
    public function read(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid  = $this->userId();
        $slug = trim((string)$request->input('conversa', ''));

        if ($slug === '' || !MessageRepo::markRead($uid, $slug)) {
            return Response::json(['ok' => false, 'erro' => 'Conversa não encontrada'], 404);
        }
        $counts = MessageRepo::folderCounts($uid);
        return Response::json([
            'ok'      => true,
            'unread'  => MessageRepo::unreadCount($uid),
            'folders' => $counts,
        ]);
    }

    /** Pasta que precisa estar ativa para a conversa aberta aparecer na lista. */
    private function folderOf(array $items, string $open): string
    {
        foreach ($items as $it) {
            if ((string)$it['id'] === $open) {
                return (string)($it['folder'] ?? 'entrada');
            }
        }
        return 'entrada';
    }
}
