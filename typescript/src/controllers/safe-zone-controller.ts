/**
 * SAFE ZONE (/safe-zone) — o App\Controllers\SafeZoneController do PHP: o lado civil do mundo
 * persistente. Zonas e papel ativo vêm do banco (`safe_zones`, `player_safe_roles`); o catálogo
 * de papéis é regra e vive em GR.SAFE_ROLES.
 */
import * as GR from '../core/game-rules';
import { dataMock, flash, url } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as SafeZoneRepo from '../repo/safe-zone-repo';
import { safeZone } from '../views/pages/safe-zone';
import { Controller } from './controller';

export class SafeZoneController extends Controller {
  async index(_req: AppRequest, params: Params = {}): Promise<AppResponse> {
    const data = dataMock('safe-zone');
    const op = await this.operational();
    const level = Number((await this.player()).level ?? 0) | 0;

    const zones = await SafeZoneRepo.zones(op);
    const current = await SafeZoneRepo.current(this.userId());

    // Zona escolhida: a da URL, a do papel ativo, a base preferencial, ou a primeira aberta.
    const slugs = zones.map((z) => String(z.slug));
    // A zona do papel ativo só vale se ainda estiver liberada para este estado.
    const padrao =
      this.zonaSeLiberada(zones, (current?.zone as string) ?? null) ??
      this.zonaDaBase(zones, String(op.base_pref ?? '')) ??
      this.primeiraLiberada(zones) ??
      slugs[0] ??
      '';
    const slug = this.pick(params.zona, slugs, padrao);

    const zone = zones.find((z) => z.slug === slug) ?? null;

    data.zones = zones;
    data.zone = zone;
    data.active_zone = slug;
    data.roles = SafeZoneRepo.roles(op, zone, level, current);
    data.current = current;
    data.operational = op;
    data.survivor = (op.state ?? '') === GR.STATE_SURVIVOR;
    data.shift_min = GR.SAFE_SHIFT_MIN;
    // Como no PHP: `empty` vira bool e sobrescreve os textos do cromo — a view cai nos padrões.
    data.empty = zones.length === 0;

    const page = this.page('safe-zone', 'Safe Zone', {
      bg: 'bg/lobby.jpg',
      back: { label: 'VOLTAR', href: url('inicio') },
    });
    return this.render(safeZone, page, data);
  }

  /** POST /safe-zone/assumir — zona + papel. As regras são revalidadas no repositório. */
  async assume(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    this.csrf(req);
    const zona = String(req.input('zone', '') ?? '');
    const papel = String(req.input('role', '') ?? '');
    const op = await this.operational();
    const level = Number((await this.player()).level ?? 0) | 0;

    const res = await SafeZoneRepo.assume(this.userId(), zona, papel, op, level);
    if (!res.ok) {
      flash('error', String(res.error ?? 'Não foi possível assumir o papel.'));
      return this.redirect('safe-zone');
    }
    flash('ok', 'TURNO ABERTO: ' + String(res.role) + ' · ' + String(res.zone) + '. Encerra em ' + GR.SAFE_SHIFT_MIN + ' min.');
    return this.redirect('safe-zone');
  }

  /** POST /safe-zone/encerrar — fecha o turno atual. */
  async leave(req: AppRequest, _p: Params = {}): Promise<AppResponse> {
    this.csrf(req);
    if (await SafeZoneRepo.leave(this.userId())) {
      flash('ok', 'Turno encerrado. Você volta a estar disponível para operação.');
    } else {
      flash('error', 'Não foi possível encerrar o turno.');
    }
    return this.redirect('safe-zone');
  }

  // ------------------------------------------------------------------ apoio

  private zonaSeLiberada(zones: Dict[], slug: string | null): string | null {
    if (slug === null || slug === '') return null;
    const z = zones.find((x) => x.slug === slug);
    if (!z) return null;
    return !z.blocked ? slug : null;
  }

  private zonaDaBase(zones: Dict[], base: string): string | null {
    if (base === '') return null;
    const z = zones.find((x) => (x.base_slug ?? null) === base && !x.blocked);
    return z ? String(z.slug) : null;
  }

  private primeiraLiberada(zones: Dict[]): string | null {
    const z = zones.find((x) => !x.blocked);
    return z ? String(z.slug) : null;
  }
}
