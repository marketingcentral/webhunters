/**
 * Um recurso de coleção: as cinco operações sobre UMA declaração do catálogo — App\Api\TabelaRecurso.
 * Atende missions, achievements, maps, items e shop (e as filhas de maps e events).
 *
 * PATCH (parcial), não PUT. EXCLUIR RECUSA (409) quando há dependente.
 */
import { url } from '../core/helpers';
import type { AppResponse } from '../core/response';
import type { Contexto } from './contexto';
import { Recurso, type Params, type Rota } from './recurso';
import { PREFIXO, type Requisicao } from './requisicao';
import { erro, ok, paginacao } from './resposta';
import * as Tabela from './tabela';

export class TabelaRecurso extends Recurso {
  /**
   * @param nomeRecurso recurso na URL (/api/v1/<nome>)
   * @param decl        declaração do catálogo
   * @param rotulo      plural, para o resumo da referência
   * @param singular    para a frase da auditoria ("Criou missão #12 pela API.")
   */
  constructor(
    private readonly nomeRecurso: string,
    protected readonly decl: Tabela.Declaracao,
    private readonly rotulo: string,
    private readonly singular: string,
  ) {
    super();
  }

  nome(): string {
    return this.nomeRecurso;
  }

  rotas(): Rota[] {
    return [
      ['GET', '', 'listar', `Lista ${this.rotulo} com filtro e página.`, '?q=&limit=50&offset=0'],
      ['GET', '{id}', 'obter', 'Um registro pelo id.', ''],
      ['POST', '', 'criar', 'Cria um registro.', ''],
      ['PATCH', '{id}', 'atualizar', 'Altera os campos enviados (alteração parcial).', ''],
      ['DELETE', '{id}', 'excluir', 'Apaga o registro, se nada depender dele.', ''],
    ];
  }

  /** Coleção-pai quando aninhada; null nas de primeiro nível. */
  protected paiId(_params: Params): number | null {
    return null;
  }

  /** Executa uma das cinco ações a pedido de OUTRO recurso (/maps/{id}/markers, /events/{grupo}). */
  operar(acao: string, req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    switch (acao) {
      case 'listar':
        return this.listar(req, ctx, params);
      case 'obter':
        return this.obter(req, ctx, params);
      case 'criar':
        return this.criar(req, ctx, params);
      case 'atualizar':
        return this.atualizar(req, ctx, params);
      case 'excluir':
        return this.excluir(req, ctx, params);
      default:
        throw new Error('Ação desconhecida na coleção: ' + acao);
    }
  }

  /** Nome da entidade na trilha de auditoria: a tabela real. */
  protected entidade(): string {
    return this.decl.tabela || this.nomeRecurso;
  }

  /** Caminho da COLEÇÃO (sem /api/v1), para o Location de um 201. */
  protected colecaoUrl(_params: Params): string {
    return this.nomeRecurso;
  }

  // ===================================================================== ações

  protected async listar(req: Requisicao, _ctx: Contexto, params: Params): Promise<AppResponse> {
    const [limite, deslocamento] = this.pagina(req);
    // Só os parâmetros que a declaração conhece chegam à consulta.
    const consulta: Record<string, string> = { q: req.consulta('q') };
    for (const p of Object.keys(this.decl.filtros ?? {})) consulta[p] = req.consulta(p);
    const r = await Tabela.listar(this.decl, consulta, limite, deslocamento, this.paiId(params));
    return ok(r.linhas, paginacao(r.total, limite, deslocamento));
  }

  protected async obter(_req: Requisicao, _ctx: Contexto, params: Params): Promise<AppResponse> {
    const id = this.id(params);
    if (id <= 0) return this.naoEncontrado();
    const linha = await Tabela.obter(this.decl, id, this.paiId(params));
    return linha === null ? this.naoEncontrado() : ok(linha);
  }

  protected async criar(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const r = await Tabela.criar(this.decl, req.corpo(), this.paiId(params));
    if (!r.ok) return erro('entrada_invalida', '', r.erros);

    const linha = await Tabela.obter(this.decl, r.id, this.paiId(params));
    await ctx.auditar('criou', this.entidade(), r.id, `Criou ${this.singular} #${r.id} pela API.`, {
      campos: Object.keys(req.corpo()),
    });
    return ok(linha ?? { id: r.id }, {}, 201).withHeader(
      'Location',
      url(`${PREFIXO.replace(/^\/+/, '')}/${this.colecaoUrl(params)}/${r.id}`),
    );
  }

  protected async atualizar(req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const id = this.id(params);
    const pai = this.paiId(params);
    if (id <= 0 || (await Tabela.obter(this.decl, id, pai)) === null) return this.naoEncontrado();

    const r = await Tabela.atualizar(this.decl, id, req.corpo(), pai);
    if (!r.ok) return erro('entrada_invalida', '', r.erros);

    // Só os NOMES dos campos vão para a trilha, nunca os valores.
    await ctx.auditar('alterou', this.entidade(), id, `Alterou ${this.singular} #${id} pela API.`, {
      campos: Object.keys(req.corpo()),
    });
    return ok((await Tabela.obter(this.decl, id, pai)) ?? []);
  }

  protected async excluir(_req: Requisicao, ctx: Contexto, params: Params): Promise<AppResponse> {
    const id = this.id(params);
    const pai = this.paiId(params);
    if (id <= 0) return this.naoEncontrado();
    const linha = await Tabela.obter(this.decl, id, pai);
    if (linha === null) return this.naoEncontrado();

    const presos = await Tabela.dependencias(this.decl.dependentes ?? [], id);
    if (Object.keys(presos).length) {
      return erro('conflito', 'Outros registros dependem deste. Apagá-lo deixaria dados órfãos.', { dependentes: presos });
    }

    await Tabela.excluir(this.decl, id, pai);
    // O registro apagado vai INTEIRO para a trilha: depois do DELETE não há outro lugar de onde reconstruí-lo.
    await ctx.auditar('excluiu', this.entidade(), id, `Excluiu ${this.singular} #${id} pela API.`, { registro: linha });
    return ok({ id, deleted: true });
  }
}
