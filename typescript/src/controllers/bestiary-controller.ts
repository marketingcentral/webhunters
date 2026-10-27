/**
 * Tela /bestiario — o App\Controllers\BestiaryController do PHP.
 * app/Data/criaturas.php (-> src/data/criaturas.json) é a fonte única do catálogo.
 */
import { creatureImg, dataMock } from '../core/helpers';
import type { AppRequest } from '../core/request';
import { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as BestiaryRepo from '../repo/bestiary-repo';
import { bestiario } from '../views/pages/bestiario';
import { Controller } from './controller';

const REGIOES: Record<string, string> = {
  'floresta-verde': 'FLORESTA VERDE',
  'pantano-cinzento': 'PÂNTANO CINZENTO',
  'montanhas-gelidas': 'MONTANHAS GÉLIDAS',
  'deserto-abandonado': 'DESERTO ABANDONADO',
  'zona-industrial': 'ZONA INDUSTRIAL',
};

export class BestiaryController extends Controller {
  async index(req: AppRequest, params: Params): Promise<AppResponse> {
    const cat = dataMock('criaturas');
    const grupos: Record<string, Dict> = cat.grupos ?? {};
    const grupo = this.pick(params.grupo, Object.keys(grupos), 'todas')!;
    const liberar = Boolean(cat.liberar_tudo ?? false);
    const reg = await BestiaryRepo.registros(this.userId());
    const abord: Record<string, Dict> = cat.abordagens ?? {};

    const lista: Dict[] = [];
    const porGrupo: Record<string, number> = {};
    let registradas = 0;
    for (const c of (cat.lista ?? []) as Dict[]) {
      const slug = String(c.slug);
      const r = reg[slug] ?? null;
      const nivel = BestiaryRepo.nivel(r, liberar);
      if (nivel !== 'desconhecido') registradas++;
      const g = String(c.grupo);
      porGrupo[g] = (porGrupo[g] ?? 0) + 1;
      // `$c + [...]` do PHP: as chaves da ficha vencem as acrescentadas
      lista.push({
        nivel,
        img: creatureImg(slug),
        registro: r ?? { sightings: 0, kills: 0, studied: false, first_seen_at: null },
        lidar: abord[String(c.abordagem ?? '')] ?? { nome: '', cor: 'lime', ms: 'help', texto: '' },
        ...c,
      });
    }

    // A criatura aberta: ?c=<slug> (link compartilhável), senão a primeira do filtro.
    const visiveis = grupo === 'todas' ? lista : lista.filter((c) => c.grupo === grupo);
    const pedido = req.query('c', '') ?? '';
    const selecionada = visiveis.some((c) => c.slug === pedido) ? pedido : String(visiveis[0]?.slug ?? '');

    const data = {
      grupos,
      grupo,
      por_grupo: porGrupo,
      lista: visiveis,
      total: lista.length,
      registradas,
      selecionada,
      niveis: cat.niveis ?? {},
      liberar,
      regioes: REGIOES,
      estudo_abates: BestiaryRepo.ESTUDO_ABATES,
    };
    const page = this.page('bestiario', 'Bestiário', { bg: 'bg/forest-generic.jpg' });
    return this.render(bestiario, page, data);
  }

  async registrar(req: AppRequest, _p: Params): Promise<AppResponse> {
    this.csrf(req);
    const slugs = ((dataMock('criaturas').lista ?? []) as Dict[]).map((c) => String(c.slug));
    const ok = await BestiaryRepo.registrar(this.userId(), req.input('criatura', '') ?? '', req.input('evento', '') ?? '', slugs);
    return AppResponse.json({ ok }, ok ? 200 : 422);
  }
}
