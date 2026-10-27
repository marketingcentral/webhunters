/**
 * Página de divulgação na raiz (/) — o App\Controllers\LandingController do PHP.
 * Mostra o MESMO mapa da HUD (partials/map-stage) com os pontos do banco, visto como um
 * Hunter recém-chegado. Quem já está logado vai direto para /inicio.
 */
import { dataMock, isAuthenticated } from '../core/helpers';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import * as WorldMap from '../core/world-map';
import * as OperationalRepo from '../repo/operational-repo';
import { landing } from '../views/pages/landing';
import { Controller } from './controller';

export class LandingController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    if (isAuthenticated()) return this.redirect('inicio');

    const data = dataMock('landing');
    const mapa = dataMock('mapa'); // cromo do mapa: legenda, controles, rótulos, bússola
    const op = OperationalRepo.defaults();

    let regions = await WorldMap.regions(false);
    if (!regions.length) regions = WorldMap.fromMock(mapa.regions ?? [], false);
    const ativa = regions[0] ?? {};
    const slug = String(ativa.slug ?? 'floresta-verde');
    const mapId: number | null = ativa.map_id !== undefined && ativa.map_id !== null ? Number(ativa.map_id) : null;

    const bases = await WorldMap.bases(mapId);
    const markers =
      mapId !== null
        ? await WorldMap.markers(mapId, op, bases)
        : WorldMap.mockMarkers((mapa.markers ?? {})[slug] ?? [], op, bases);
    const extracoes = markers.filter((m) => (m.layer ?? '') === 'extracao').length;

    data.regions = regions;
    data.region = ativa;
    data.active_region = slug;
    data.markers = markers;
    data.bases = bases;
    data.map_image = String(ativa.world_image || mapa.map_image || 'bg/map-world.webp');
    data.map_alt = String(mapa.map_alt ?? 'Mapa do mundo');
    data.map_opacity = WorldMap.opacidade(ativa);
    for (const k of ['world_labels', 'controls', 'compass', 'legend', 'legend_all']) data[k] = mapa[k] ?? [];

    // Criaturas do catálogo canônico (o mesmo do bestiário logado e do mapa).
    data.criaturas = dataMock('criaturas');
    // Números do herói e da seção do mundo: contados do banco, não escritos.
    data.contagens = { regioes: regions.length, pontos: markers.length, bases: bases.length, extracoes };

    const page = this.page('landing', String(data.title), {
      bg: 'bg/home-hero-wide.jpg',
      description: String(data.description),
      three: { atmosphere: false, map: true },
    });
    return this.render(landing, page, data, 'landing');
  }
}
