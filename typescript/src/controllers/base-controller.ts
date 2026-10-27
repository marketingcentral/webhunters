/**
 * BASE DE CAMPO (/base) — o App\Controllers\BaseController do PHP (PRD v3 §28).
 *
 * P0: a página explica o que uma base é e mostra o estado real do jogador — hoje "nenhuma
 * base ativa", porque a tabela player_bases só entra no P1 (não existe nas migrations do D1;
 * a consulta falha e cai no null, como no PHP sem a tabela).
 */
import * as DB from '../core/database';
import * as GR from '../core/game-rules';
import { dataMock, url } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import { base } from '../views/pages/base';
import { Controller } from './controller';

export class BaseController extends Controller {
  async index(_req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    const data = dataMock('base');
    const op = await this.operational();
    data.operational = op;
    data.base = await this.baseDoJogador(this.userId());
    data.is_survivor = op.state === GR.STATE_SURVIVOR;

    const page = this.page('base', 'Base de Campo', { back: { label: 'VOLTAR', href: url('inicio') } });
    return this.render(base, page, data);
  }

  /** Base de campo do jogador, se a tabela existir e houver uma. null = nenhuma ativa. */
  private async baseDoJogador(uid: number): Promise<Dict | null> {
    if (uid <= 0) return null;
    let b: Dict | null;
    try {
      b = await DB.one(
        `SELECT b.*, m.name AS region_name FROM player_bases b LEFT JOIN maps m ON m.id = b.map_id
          WHERE b.user_id = :u ORDER BY b.id DESC LIMIT 1`,
        { u: uid },
      );
    } catch {
      return null; // player_bases ainda não existe (P1)
    }
    if (b === null) return null;
    let mods: unknown = [];
    try {
      mods = JSON.parse(String(b.modules ?? '[]'));
    } catch {
      mods = [];
    }
    b.modules = Array.isArray(mods) ? mods : mods && typeof mods === 'object' ? Object.values(mods) : [];
    return b;
  }
}
