/**
 * /api/v1/events — três coleções sob um recurso só: desafios, notícias e avisos
 * (App\Api\EventosRecurso). Rotas LITERAIS por grupo, para a referência do painel listar os três.
 */
import type { AppResponse } from '../../core/response';
import * as Catalogo from '../catalogo';
import type { Contexto } from '../contexto';
import { Recurso, type Params, type Rota } from '../recurso';
import type { Requisicao } from '../requisicao';
import { erro } from '../resposta';
import { TabelaRecurso } from '../tabela-recurso';

/** [plural, singular] — o singular é o que a auditoria escreve. */
const ROTULOS: Record<string, [string, string]> = {
  challenges: ['desafios', 'desafio'],
  news: ['notícias', 'notícia'],
  notices: ['avisos do sistema', 'aviso'],
};

export class EventosRecurso extends Recurso {
  private readonly grupos: Record<string, TabelaRecurso> = {};

  constructor() {
    super();
    for (const [grupo, decl] of Object.entries(Catalogo.eventos())) {
      const [plural, singular] = ROTULOS[grupo] ?? [grupo, grupo];
      // Nome composto ('events/challenges') só para o Location de um 201 sair certo.
      this.grupos[grupo] = new TabelaRecurso('events/' + grupo, decl, plural, singular);
    }
  }

  nome(): string {
    return 'events';
  }

  rotas(): Rota[] {
    const out: Rota[] = [];
    for (const grupo of Object.keys(this.grupos)) {
      const [plural] = ROTULOS[grupo] ?? [grupo];
      out.push(['GET', grupo, 'grupo', `Lista ${plural}.`, '?q=&limit=50']);
      out.push(['POST', grupo, 'grupo', `Cria um registro em ${plural}.`, '']);
      out.push(['GET', grupo + '/{id}', 'grupo', `Um registro de ${plural} pelo id.`, '']);
      out.push(['PATCH', grupo + '/{id}', 'grupo', `Altera um registro de ${plural}.`, '']);
      out.push(['DELETE', grupo + '/{id}', 'grupo', `Apaga um registro de ${plural}.`, '']);
    }
    return out;
  }

  /** Toda rota de evento cai aqui; o grupo é o primeiro segmento depois de /events. */
  protected async grupo(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const colecao = this.grupos[req.segmento(1)];
    if (!colecao) return erro('rota_ausente');

    const temId = (params.id ?? '') !== '';
    const m = req.metodo();
    const acao = m === 'GET' ? (temId ? 'obter' : 'listar') : m === 'POST' ? 'criar' : m === 'PATCH' ? 'atualizar' : m === 'DELETE' ? 'excluir' : '';
    if (acao === '') return erro('metodo_invalido');
    return colecao.operar(acao, req, ctx, params);
  }
}
