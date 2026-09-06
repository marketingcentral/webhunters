<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repo\InventoryRepo;

/**
 * HUD IN-GAME (/hud).
 *
 * Página de overlay carregada dentro do jogo pelo WBP_HuntersWeb: fundo transparente,
 * sem topbar/rodapé, sem captura de mouse. Mostra por enquanto só as duas armas
 * equipadas (bandoleira e coldre) com a munição do carregador instalado.
 *
 * A munição é do INVENTÁRIO, conciliada por user_id: quando o jogador atira na Unreal,
 * a Blueprint manda `Hud.Ammo` e o navegador chama POST /hud/municao, que grava em
 * `player_items.ammo_cur` do carregador. Recarregar a página mostra o valor real.
 */
final class HudController extends Controller
{
    /** Slots do inventário que aparecem no HUD, na ordem de exibição. */
    private const SLOTS = ['primary', 'pistol'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $page = $this->page('hud', 'HUD', ['nav' => '']);
        return $this->render('hud', $page, ['guns' => $this->guns()], 'overlay');
    }

    /** GET /hud/dados — estado atual das armas, para o JS ressincronizar. */
    public function data(Request $request, array $params = []): Response
    {
        return Response::json(['ok' => true, 'guns' => $this->guns()]);
    }

    /**
     * POST /hud/municao — {slot, current}. Chamado pelo navegador quando a Unreal avisa
     * que a munição mudou. Grava no carregador daquele slot e devolve o estado normalizado.
     */
    public function ammo(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid = $this->userId();
        if ($uid <= 0) {
            return Response::json(['ok' => false, 'error' => 'Sem sessão.'], 401);
        }
        $slot = (string)$request->input('slot', '');
        if (!in_array($slot, self::SLOTS, true)) {
            return Response::json(['ok' => false, 'error' => 'Slot inválido.'], 422);
        }
        // 'current' precisa ser um inteiro de verdade: string vazia viraria 0 e zeraria
        // o carregador em silêncio.
        $bruto = $request->input('current');
        if (!is_numeric($bruto)) {
            return Response::json(['ok' => false, 'error' => 'Munição inválida.'], 422);
        }

        $res = InventoryRepo::setAmmo($uid, $slot, (int)$bruto);
        return Response::json($res, $res['ok'] ? 200 : 422);
    }

    /**
     * As armas equipadas, prontas para a view/JSON.
     *
     * @return list<array<string,mixed>>
     */
    private function guns(): array
    {
        $uid = $this->userId();
        if ($uid <= 0) {
            return [];
        }
        $tree   = InventoryRepo::tree($uid);
        $rotulo = ['primary' => 'PRIMÁRIA', 'pistol' => 'COLDRE'];

        $out = [];
        foreach (self::SLOTS as $slot) {
            $arma = $tree['slots'][$slot]['item'] ?? null;
            if ($arma === null) {
                continue;                                    // slot vazio não ocupa o HUD
            }
            // a munição vive no carregador anexado; sem carregador a arma está descarregada
            $mag = null;
            foreach ((array)($arma['attachments'] ?? []) as $a) {
                if (!empty($a['mag_capacity'])) { $mag = $a; break; }
            }

            $out[] = [
                'slot'     => $slot,
                'label'    => $rotulo[$slot] ?? mb_strtoupper($slot),
                'name'     => (string)($arma['short'] ?: $arma['name']),
                'image'    => (string)($arma['image'] ?? ''),
                'caliber'  => (string)($arma['ammo_caliber'] ?? ''),
                'current'  => $mag ? (int)($mag['ammo_cur'] ?? 0) : 0,
                'capacity' => $mag ? (int)($mag['mag_capacity'] ?? 0) : 0,
                'has_mag'  => $mag !== null,
                'reserve'  => InventoryRepo::reserveFor($uid, $arma['ammo_caliber'] ?? null, $mag ? (int)$mag['id'] : 0),
            ];
        }
        return $out;
    }
}
