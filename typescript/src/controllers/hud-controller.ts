/**
 * HUD IN-GAME (/hud) — o App\Controllers\HudController do PHP.
 *
 * Página de overlay carregada dentro do jogo pelo WBP_HuntersWeb: fundo transparente,
 * sem topbar/rodapé, sem captura de mouse. Mostra as duas armas equipadas (bandoleira e
 * coldre) com a munição do carregador instalado.
 *
 * A munição é do INVENTÁRIO, conciliada por user_id: quando o jogador atira na Unreal,
 * a Blueprint manda `Hud.Ammo` e o navegador chama POST /hud/municao, que grava em
 * `player_items.ammo_cur` do carregador. Recarregar a página mostra o valor real.
 */
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as InventoryRepo from '../repo/inventory-repo';
import { hud } from '../views/pages/hud';
import { Controller } from './controller';

/** Slots do inventário que aparecem no HUD, na ordem de exibição. */
const SLOTS = ['primary', 'pistol'];
const ROTULO: Record<string, string> = { primary: 'PRIMÁRIA', pistol: 'COLDRE' };

/** is_numeric() do PHP: inteiro/decimal/notação científica, com espaço em volta. */
function isNumeric(v: string | null): v is string {
  return v !== null && /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(v);
}

export class HudController extends Controller {
  async index(_req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const page = this.page('hud', 'HUD', { nav: '' });
    return this.render(hud, page, { guns: await this.guns() }, 'overlay');
  }

  /** GET /hud/dados — estado atual das armas, para o JS ressincronizar. */
  async data(_req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    return AppResponse.json({ ok: true, guns: await this.guns() });
  }

  /**
   * POST /hud/municao — {slot, current}. Chamado pelo navegador quando a Unreal avisa
   * que a munição mudou. Grava no carregador daquele slot e devolve o estado normalizado.
   */
  async ammo(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    this.csrf(req);
    const uid = this.userId();
    if (uid <= 0) return AppResponse.json({ ok: false, error: 'Sem sessão.' }, 401);
    const slot = req.input('slot', '') ?? '';
    if (!SLOTS.includes(slot)) return AppResponse.json({ ok: false, error: 'Slot inválido.' }, 422);
    // 'current' precisa ser um número de verdade: string vazia viraria 0 e zeraria
    // o carregador em silêncio.
    const bruto = req.input('current');
    if (!isNumeric(bruto)) return AppResponse.json({ ok: false, error: 'Munição inválida.' }, 422);

    const n = Math.trunc(Number(bruto)); // (int) do PHP
    const res = await InventoryRepo.setAmmo(uid, slot, Number.isFinite(n) ? n : 0);
    return AppResponse.json(res, res.ok ? 200 : 422);
  }

  /** As armas equipadas, prontas para a view/JSON. */
  private async guns(): Promise<Dict[]> {
    const uid = this.userId();
    if (uid <= 0) return [];
    const tree = await InventoryRepo.tree(uid);

    const out: Dict[] = [];
    for (const slot of SLOTS) {
      const arma = (tree.slots?.[slot]?.item ?? null) as Dict | null;
      if (arma === null) continue; // slot vazio não ocupa o HUD
      // a munição vive no carregador anexado; sem carregador a arma está descarregada
      const mag = ((arma.attachments ?? []) as Dict[]).find((a) => Boolean(a.mag_capacity)) ?? null;

      out.push({
        slot,
        label: ROTULO[slot] ?? slot.toUpperCase(),
        name: String(arma.short || arma.name || ''),
        image: String(arma.image ?? ''),
        caliber: String(arma.ammo_caliber ?? ''),
        current: mag ? Number(mag.ammo_cur ?? 0) | 0 : 0,
        capacity: mag ? Number(mag.mag_capacity ?? 0) | 0 : 0,
        has_mag: mag !== null,
        reserve: await InventoryRepo.reserveFor(uid, (arma.ammo_caliber as string | null) ?? null, mag ? Number(mag.id) : 0),
      });
    }
    return out;
  }
}
