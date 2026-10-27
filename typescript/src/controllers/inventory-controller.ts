/**
 * Inventário e a API de mover/equipar/acessório — o App\Controllers\InventoryController do PHP.
 * As respostas JSON têm o mesmo formato: { ok, error?, tree } (assets/js/pages/inventario.js).
 */
import { dataMock } from '../core/helpers';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as InventoryRepo from '../repo/inventory-repo';
import { inventario } from '../views/pages/inventario';
import { Controller } from './controller';

/** "" chega quando o cliente manda a chave vazia: tem de virar null, não 0 (FK de parent_id). */
const opt = (v: string | null): string | null => (v === null || v === '' ? null : v);
const int = (v: string | null): number => parseInt(v ?? '0', 10) || 0;

export class InventoryController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    const uid = this.userId();
    const tree =
      uid > 0
        ? await InventoryRepo.tree(uid)
        : { stash: { cols: 10, rows: 8, items: [] }, slots: {}, weight: 0, value: 0 };
    // textos e painéis continuam no cromo; o núcleo interativo vem do banco
    const data = dataMock('inventario');
    data.tree = tree;
    data.weight_max = 60;
    data.player = await this.player();
    const page = this.page('inventario', 'Inventário', {
      bg: 'bg/inventario.jpg',
      js: ['three/inventory-character.js'],
    });
    return this.render(inventario, page, data);
  }

  async tree(_req: AppRequest, _p: Params): Promise<AppResponse> {
    const uid = this.userId();
    return AppResponse.json({ ok: true, tree: uid > 0 ? await InventoryRepo.tree(uid) : null, player: await this.player() });
  }

  private async responder(uid: number, res: Dict): Promise<AppResponse> {
    return AppResponse.json({ ...res, tree: await InventoryRepo.tree(uid) }, res.ok ? 200 : 422);
  }

  async move(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    if (uid <= 0) return AppResponse.json({ ok: false, error: 'Não autenticado.' }, 401);
    const parent = opt(req.input('parent_id'));
    const x = opt(req.input('x'));
    const y = opt(req.input('y'));
    const dest = {
      container: req.input('container', 'stash') ?? 'stash',
      parent_id: parent !== null ? int(parent) : null,
      slot_key: opt(req.input('slot_key')),
      x: x !== null ? int(x) : null,
      y: y !== null ? int(y) : null,
      rotated: ['1', 'true'].includes(req.input('rotated', '0') ?? '0'),
    };
    return this.responder(uid, await InventoryRepo.move(uid, int(req.input('instance_id', '0')), dest));
  }

  async equip(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    if (uid <= 0) return AppResponse.json({ ok: false, error: 'Não autenticado.' }, 401);
    const id = int(req.input('instance_id', '0'));
    const slot = req.input('slot_key', '') ?? '';
    let res: Dict;
    if (slot === 'stash') {
      await InventoryRepo.sendToStash(uid, id);
      res = { ok: true };
    } else {
      res = await InventoryRepo.move(uid, id, { container: 'equip', slot_key: slot });
    }
    return this.responder(uid, res);
  }

  async attach(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    if (uid <= 0) return AppResponse.json({ ok: false, error: 'Não autenticado.' }, 401);
    const id = int(req.input('instance_id', '0'));
    const weapon = req.input('weapon_id') !== null ? int(req.input('weapon_id')) : null;
    let res: Dict;
    if (weapon === null) {
      await InventoryRepo.sendToStash(uid, id);
      res = { ok: true };
    } else {
      res = await InventoryRepo.installAttachment(uid, id, weapon, req.input('slot_key'));
    }
    return this.responder(uid, res);
  }
}
