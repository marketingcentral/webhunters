<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;
use App\Repo\InventoryRepo;

final class InventoryController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid  = $this->userId();
        $tree = $uid > 0 ? InventoryRepo::tree($uid) : ['stash' => ['cols' => 10, 'rows' => 8, 'items' => []], 'slots' => [], 'weight' => 0, 'value' => 0];

        // dados de layout (textos, painéis de saúde/estatística) continuam vindo do mock;
        // o núcleo interativo (equipamento + stash) vem do banco
        $data = data_mock('inventario');
        $data['tree']       = $tree;
        $data['weight_max'] = 60;
        $data['player']     = $this->player();

        $page = $this->page('inventario', 'Inventário', [
            'bg'  => 'bg/inventario.jpg',
            'js'  => ['three/inventory-character.js'],
        ]);
        return $this->render('inventario', $page, $data);
    }

    /** GET /inventario/dados — árvore completa em JSON (recarrega a grade após uma ação). */
    public function tree(Request $request, array $params = []): Response
    {
        $uid = $this->userId();
        return Response::json(['ok' => true, 'tree' => $uid > 0 ? InventoryRepo::tree($uid) : null,
            'player' => $this->player()]);
    }

    /** POST /inventario/mover — {instance_id, container, parent_id?, slot_key?, x?, y?, rotated?} */
    public function move(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid = $this->userId();
        if ($uid <= 0) { return Response::json(['ok' => false, 'error' => 'Não autenticado.'], 401); }
        $id = (int)$request->input('instance_id', '0');
        // campos vazios chegam como "" (o cliente sempre envia a chave); "" deve virar null,
        // senão (int)"" = 0 e o UPDATE viola a chave estrangeira de parent_id.
        $opt = static function (?string $v): ?string {
            return ($v === null || $v === '') ? null : $v;
        };
        $parent = $opt($request->input('parent_id'));
        $x      = $opt($request->input('x'));
        $y      = $opt($request->input('y'));
        $dest = [
            'container' => (string)$request->input('container', 'stash'),
            'parent_id' => $parent !== null ? (int)$parent : null,
            'slot_key'  => $opt($request->input('slot_key')),
            'x'         => $x !== null ? (int)$x : null,
            'y'         => $y !== null ? (int)$y : null,
            'rotated'   => in_array((string)$request->input('rotated', '0'), ['1', 'true'], true),
        ];
        $res = InventoryRepo::move($uid, $id, $dest);
        return Response::json($res + ['tree' => InventoryRepo::tree($uid)], ($res['ok'] ?? false) ? 200 : 422);
    }

    /** POST /inventario/equipar — {instance_id, slot_key} (atalho para equipar/desequipar). */
    public function equip(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid = $this->userId();
        if ($uid <= 0) { return Response::json(['ok' => false, 'error' => 'Não autenticado.'], 401); }
        $id   = (int)$request->input('instance_id', '0');
        $slot = (string)$request->input('slot_key', '');
        if ($slot === 'stash') {
            InventoryRepo::sendToStash($uid, $id);
            $res = ['ok' => true];
        } else {
            $res = InventoryRepo::move($uid, $id, ['container' => 'equip', 'slot_key' => $slot]);
        }
        return Response::json($res + ['tree' => InventoryRepo::tree($uid)], ($res['ok'] ?? false) ? 200 : 422);
    }

    /** POST /inventario/acessorio — {instance_id, weapon_id, slot_key} instala/remove attachment. */
    public function attach(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $uid = $this->userId();
        if ($uid <= 0) { return Response::json(['ok' => false, 'error' => 'Não autenticado.'], 401); }
        $id     = (int)$request->input('instance_id', '0');
        $weapon = $request->input('weapon_id') !== null ? (int)$request->input('weapon_id') : null;
        $slot   = $request->input('slot_key');
        if ($weapon === null) {
            InventoryRepo::sendToStash($uid, $id);
            $res = ['ok' => true];
        } else {
            $res = InventoryRepo::installAttachment($uid, $id, $weapon, $slot);
        }
        return Response::json($res + ['tree' => InventoryRepo::tree($uid)], ($res['ok'] ?? false) ? 200 : 422);
    }
}
