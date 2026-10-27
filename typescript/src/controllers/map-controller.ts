/**
 * Tela /mapa e a preferência de opacidade — o App\Controllers\MapController do PHP.
 */
import * as GR from '../core/game-rules';
import { currentUserId, dataMock, flash, isAuthenticated } from '../core/helpers';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import * as WorldMap from '../core/world-map';
import * as PlayerSettings from '../repo/player-settings-repo';
import { mapa } from '../views/pages/mapa';
import { Controller } from './controller';

const PREF_OPACIDADE = 'mapa.opacidade';

const limite = (v: number) => Math.max(WorldMap.OPACIDADE_MIN, Math.min(1, v));

export class MapController extends Controller {
  async index(_req: AppRequest, params: Params): Promise<AppResponse> {
    const data = dataMock('mapa');
    const op = await this.operational();
    const survivor = op.state === GR.STATE_SURVIVOR;

    let regions = await WorldMap.regions(survivor);
    if (!regions.length) regions = WorldMap.fromMock(data.regions ?? [], survivor);
    const slugs = regions.map((r) => String(r.slug));
    const slug = this.pick(params.regiao, slugs, slugs[0] ?? 'floresta-verde')!;

    data.regions = regions;
    data.active_region = slug;
    data.region = regions.find((r) => r.slug === slug) ?? null;
    const activeMapId: number | null = data.region?.map_id ?? null;

    const bases = await WorldMap.bases(activeMapId);
    let markers = activeMapId !== null ? await WorldMap.markers(activeMapId, op, bases) : [];
    if (!markers.length && activeMapId === null) {
      markers = WorldMap.mockMarkers((data.markers ?? {})[slug] ?? [], op, bases);
    }

    // A arte do mundo vem do próprio mapa (maps.meta.world_image); o cromo é o fallback.
    const arte = String(data.region?.world_image ?? '');
    if (arte !== '') data.map_image = arte;

    // Quanto a arte recua: padrão do mapa (painel), sobreposto pela preferência da conta.
    const doMapa = data.region?.map_opacity ?? null;
    const padrao = doMapa !== null ? limite(Number(doMapa)) : WorldMap.OPACIDADE_PADRAO;
    const doJogador = (await PlayerSettings.forUser(currentUserId()))[PREF_OPACIDADE];
    data.map_opacity = doJogador !== undefined && doJogador !== '' && !Number.isNaN(Number(doJogador)) ? limite(Number(doJogador)) : padrao;
    data.map_opacity_padrao = padrao;
    data.map_opacity_min = WorldMap.OPACIDADE_MIN;

    data.markers = markers;
    data.bases = bases;
    data.faction_sites = WorldMap.factionSites(markers);
    data.operational = op;
    data.survivor = survivor;
    if (survivor) data.extractions_title = String(data.extractions_blocked_title ?? 'EXTRAÇÕES · BLOQUEADAS');

    const page = this.page('mapa', 'Mapa do Mundo', {
      bg: 'bg/forest-generic.jpg',
      three: { atmosphere: true, map: true },
    });
    return this.render(mapa, page, data);
  }

  async opacidade(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const uid = currentUserId();
    if (uid <= 0 || !isAuthenticated()) return this.resposta(req, false, 'Entre na sua conta para guardar essa preferência.');
    if (req.input('reset', '') === '1') {
      await PlayerSettings.reset(uid, [PREF_OPACIDADE]);
      return this.resposta(req, true, 'Opacidade do mapa de volta ao padrão.');
    }
    const bruto = req.input('valor', '') ?? '';
    if (bruto === '' || Number.isNaN(Number(bruto))) return this.resposta(req, false, 'Valor de opacidade inválido.');
    const valor = Math.round(limite(Number(bruto)) * 100) / 100;
    await PlayerSettings.save(uid, { [PREF_OPACIDADE]: String(valor) });
    return this.resposta(req, true, 'Opacidade do mapa guardada na sua conta.');
  }

  private resposta(req: AppRequest, ok: boolean, msg: string): AppResponse {
    if (req.isAjax() || req.wantsPartial()) return AppResponse.json({ ok, message: msg }, ok ? 200 : 422);
    flash(ok ? 'ok' : 'error', msg);
    const r = req.input('regiao', '') ?? '';
    return this.redirect('mapa' + (r !== '' ? '/' + r : ''));
  }
}
