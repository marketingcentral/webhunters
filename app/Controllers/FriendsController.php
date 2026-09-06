<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repo\FriendsRepo;

/**
 * Tela AMIGOS. Listas, contadores e pedidos vêm da tabela `friends` conciliada por
 * current_user_id() (App\Repo\FriendsRepo); app/Data/amigos.php entra só com os textos
 * fixos da interface e como fallback quando o banco não está instalado.
 */
final class FriendsController extends Controller
{
    private const TABS = ['online', 'offline', 'pedidos'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $tab = $this->pick($params['aba'] ?? null, self::TABS, 'online');
        $data = $this->build((string)$tab);

        $page = $this->page('amigos', 'Amigos', ['nav' => 'amigos']);
        return $this->render('amigos', $page, $data);
    }

    /**
     * POST /amigos: envia convite e responde pedidos pendentes, gravando em `friends`.
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params = []): Response
    {
        $this->csrf($request);

        $uid    = $this->userId();
        $action = (string)$request->input('acao', 'convidar');
        $target = FriendsRepo::cleanName((string)$request->input('jogador', ''));
        $self   = (string)($this->player()['name'] ?? '');

        [$kind, $message] = match ($action) {
            'aceitar'  => FriendsRepo::accept($uid, $target),
            'recusar'  => FriendsRepo::decline($uid, $target),
            'cancelar' => FriendsRepo::cancel($uid, $target),
            default    => FriendsRepo::invite($uid, $target, $self),
        };

        flash($kind, $message);

        // volta para a aba em que a ação aconteceu
        $tab = $action === 'convidar' ? ($kind === 'ok' ? 'pedidos' : 'online') : 'pedidos';
        return $this->redirect('amigos/' . $tab);
    }

    /**
     * Monta o $data da view: textos de app/Data + listas do banco (com fallback estático).
     * @return array<string, mixed>
     */
    private function build(string $tab): array
    {
        $data = data_mock('amigos');
        $uid  = $this->userId();

        $online  = (array)($data['items'] ?? []);
        $offline = [];
        $pending = (array)($data['pending'] ?? []);
        $squad   = (array)($data['squad']['items'] ?? []);
        $fromDb  = false;

        try {
            // uid 0 = banco não instalado; aí sim cai no arquivo estático
            if ($uid > 0) {
                $social  = FriendsRepo::forUser($uid);
                $online  = $social['online'];
                $offline = $social['offline'];
                $pending = $social['pending'];
                $squad   = $social['squad'];
                $fromDb  = true;
            }
        } catch (\Throwable) {
            $fromDb = false; // banco indisponível — segue com o fallback estático
        }

        if (!$fromDb) {
            // fallback: separa as listas do arquivo estático do mesmo jeito que o repositório
            $all     = $online;
            $online  = array_values(array_filter($all, static fn(array $f): bool => ($f['status'] ?? '') !== 'offline'));
            $offline = array_values(array_filter($all, static fn(array $f): bool => ($f['status'] ?? '') === 'offline'));
        }

        $counts = ['online' => count($online), 'offline' => count($offline), 'pedidos' => count($pending)];

        $data['online'] = $counts['online'];
        $data['total']  = $counts['online'] + $counts['offline'];
        $data['tab']    = $tab;
        $data['items_online']  = $online;
        $data['items_offline'] = $offline;
        $data['pending']       = $pending;
        $data['squad']['items'] = $squad;
        $data['tabs'] = [
            ['slug' => 'online',  'label' => 'ONLINE',  'count' => $counts['online']],
            ['slug' => 'offline', 'label' => 'OFFLINE', 'count' => $counts['offline']],
            ['slug' => 'pedidos', 'label' => 'PEDIDOS', 'count' => $counts['pedidos']],
        ];
        // o "SEU ID" é do jogador logado, não um número congelado
        $data['add']['id_suffix'] = '#' . str_pad((string)max($uid, 0), 4, '0', STR_PAD_LEFT);

        return $data;
    }
}
