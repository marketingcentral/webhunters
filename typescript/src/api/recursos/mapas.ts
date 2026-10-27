/**
 * /api/v1/maps — a coleção de mapas MAIS os marcadores de cada mapa (App\Api\MapasRecurso e
 * MarcadoresRecurso). Marcador é coleção ANINHADA: o mapa entra no WHERE de toda consulta.
 * O escopo continua `maps:read` / `maps:write`.
 */
import type { AppResponse } from '../../core/response';
import * as Catalogo from '../catalogo';
import type { Contexto } from '../contexto';
import type { Params, Rota } from '../recurso';
import type { Requisicao } from '../requisicao';
import { erro } from '../resposta';
import * as Tabela from '../tabela';
import { TabelaRecurso } from '../tabela-recurso';

/** A coleção filha. Nunca é registrada no despachante: só se chega a ela por MapasRecurso. */
class MarcadoresRecurso extends TabelaRecurso {
  constructor() {
    super('maps', Catalogo.marcadores(), 'marcadores', 'marcador');
  }
  protected override paiId(params: Params): number | null {
    const pai = parseInt(params.pai ?? '0', 10) || 0;
    return pai > 0 ? pai : null;
  }
  protected override colecaoUrl(params: Params): string {
    return `maps/${parseInt(params.pai ?? '0', 10) || 0}/markers`;
  }
}

export class MapasRecurso extends TabelaRecurso {
  private readonly marcadoresFilha = new MarcadoresRecurso();

  constructor() {
    super('maps', Catalogo.mapas(), 'mapas', 'mapa');
  }

  override rotas(): Rota[] {
    return [
      ...super.rotas(),
      ['GET', '{id}/markers', 'marcadores', 'Marcadores do mapa.', '?limit=200'],
      ['POST', '{id}/markers', 'marcadores', 'Cria um marcador no mapa.', ''],
      ['GET', '{id}/markers/{marcador}', 'marcadores', 'Um marcador pelo id.', ''],
      ['PATCH', '{id}/markers/{marcador}', 'marcadores', 'Altera um marcador.', ''],
      ['DELETE', '{id}/markers/{marcador}', 'marcadores', 'Apaga um marcador.', ''],
    ];
  }

  /** Toda rota de marcador cai aqui; a ação sai do método e da presença do id do marcador. */
  protected async marcadores(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const mapa = this.id(params);
    // Mapa conferido ANTES: lista vazia de mapa inexistente se leria como "sem marcadores".
    if (mapa <= 0 || (await Tabela.obter(this.decl, mapa)) === null) return this.naoEncontrado('Mapa não encontrado.');

    const temMarcador = (params.marcador ?? '') !== '';
    const m = req.metodo();
    const acao = m === 'GET' ? (temMarcador ? 'obter' : 'listar') : m === 'POST' ? 'criar' : m === 'PATCH' ? 'atualizar' : m === 'DELETE' ? 'excluir' : '';
    if (acao === '') return erro('metodo_invalido');

    // O id do marcador viaja como 'id' e o do mapa como 'pai' — o vocabulário de TabelaRecurso.
    return this.marcadoresFilha.operar(acao, req, ctx, { id: params.marcador ?? '', pai: String(mapa) });
  }
}
