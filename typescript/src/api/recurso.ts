/**
 * Base de todo recurso de /api/v1 — App\Api\Recurso do PHP — e a ÚNICA declaração das rotas
 * dele: o despachante lê `rotas()` para decidir ação e escopo, e a tela /admin/api lê
 * `referencia()` para desenhar a documentação. As duas nunca divergem.
 *
 * Forma de uma rota: [método, padrão relativo ('' = coleção, '{id}/markers'), ação, resumo, exemplo?].
 * O ESCOPO não é declarado por rota: sai do método (escopos.paraMetodo).
 */
import type { AppResponse } from '../core/response';
import type { Contexto } from './contexto';
import { paraMetodo } from './escopos';
import { PREFIXO, type Requisicao } from './requisicao';
import { erro } from './resposta';
import { LIMITE_MAX, LIMITE_PADRAO } from './tabela';

export type Rota = [string, string, string, string, string?];
export type Params = Record<string, string>;
export type Acao = (req: Requisicao, ctx: Contexto, params: Params) => Promise<AppResponse>;

export interface Casamento {
  rota?: Rota;
  params?: Params;
  metodos?: string[];
}

export interface RotaReferencia {
  metodo: string;
  caminho: string;
  escopo: string;
  resumo: string;
  exemplo: string;
}

export abstract class Recurso {
  /** Primeiro segmento depois de /api/v1. Tem de existir em escopos.RECURSOS. */
  abstract nome(): string;

  abstract rotas(): Rota[];

  /**
   * Acha a rota: { rota, params } | { metodos } (caminho existe, verbo não -> 405) | {} (404).
   * `segmentos` já vem sem o nome do recurso.
   */
  casar(segmentos: string[], metodo: string): Casamento {
    const metodosDoCaminho: string[] = [];
    for (const rota of this.rotas()) {
      const partes = rota[1] === '' ? [] : rota[1].split('/');
      if (partes.length !== segmentos.length) continue;
      const params: Params = {};
      let bate = true;
      for (let i = 0; i < partes.length; i++) {
        const m = /^\{([a-z_]+)\}$/.exec(partes[i]!);
        if (m) {
          params[m[1]!] = segmentos[i]!;
          continue;
        }
        if (partes[i] !== segmentos[i]) {
          bate = false;
          break;
        }
      }
      if (!bate) continue;
      if (rota[0].toUpperCase() !== metodo) {
        metodosDoCaminho.push(rota[0].toUpperCase());
        continue;
      }
      return { rota, params };
    }
    return metodosDoCaminho.length === 0 ? {} : { metodos: [...new Set(metodosDoCaminho)] };
  }

  /** Executa a ação da rota (método de mesmo nome nesta classe). O despachante já conferiu chave, escopo e limite. */
  executar(rota: Rota, params: Params, req: Requisicao, ctx: Contexto): Promise<AppResponse> {
    const fn = (this as unknown as Record<string, unknown>)[rota[2]];
    if (typeof fn !== 'function') {
      console.error(`[extracthunters] api: ação ausente ${this.constructor.name}::${rota[2]}`);
      return Promise.resolve(erro('erro_interno'));
    }
    return (fn as Acao).call(this, req, ctx, params);
  }

  /** A referência deste recurso, no formato que a tela do painel desenha. */
  referencia(): RotaReferencia[] {
    return this.rotas().map((rota) => ({
      metodo: rota[0].toUpperCase(),
      caminho: `${PREFIXO}/${this.nome()}${rota[1] === '' ? '' : '/' + rota[1]}`,
      escopo: paraMetodo(this.nome(), rota[0]),
      resumo: rota[3],
      exemplo: rota[4] ?? '',
    }));
  }

  // ================================================================= apoio das ações

  /** limit e offset que TODA listagem aceita, já presos à faixa. */
  protected pagina(req: Requisicao): [number, number] {
    return [req.consultaInt('limit', LIMITE_PADRAO, 1, LIMITE_MAX), req.consultaInt('offset', 0, 0, 1000000)];
  }

  /** Id de rota numérico positivo, ou 0 (vira 404: /players/abc e /players/999999 são o mesmo "não existe"). */
  protected id(params: Params, nome = 'id'): number {
    const v = params[nome] ?? '';
    return /^\d{1,10}$/.test(v) ? parseInt(v, 10) : 0;
  }

  protected naoEncontrado(oQue = 'Registro não encontrado.'): AppResponse {
    return erro('nao_encontrado', oQue);
  }
}
