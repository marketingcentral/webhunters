/**
 * /api/v1/economy — o livro-caixa da loja, SÓ LEITURA (App\Api\EconomiaRecurso).
 * Nenhum endpoint cria, altera ou apaga compra; e nunca há um "total" somando moedas.
 */
import type { AppResponse } from '../../core/response';
import { dataValida } from '../campo';
import * as Infra from '../infra-repo';
import { Recurso, type Rota } from '../recurso';
import type { Requisicao } from '../requisicao';
import { ok, paginacao } from '../resposta';

const DIAS_PADRAO = 30;

/** Sem from/to: os últimos 30 dias (UTC). Datas trocadas são endireitadas. */
function janela(req: Requisicao): Infra.FiltroLivro {
  const hoje = Infra.fmt(Math.floor(Date.now() / 1000)).slice(0, 10);
  const data = (v: string): string | null => (dataValida(v.trim()) ? v.trim() : null);
  let de = data(req.consulta('from')) ?? Infra.fmt(Infra.ts(hoje + ' 00:00:00') - DIAS_PADRAO * 86400).slice(0, 10);
  let ate = data(req.consulta('to')) ?? hoje;
  if (de > ate) [de, ate] = [ate, de];
  const moeda = req.consulta('currency');
  return {
    de,
    ate,
    moeda: Infra.MOEDAS_LIVRO[moeda] ? moeda : '',
    busca: [...req.consulta('q')].slice(0, 60).join(''),
  };
}

const int = (v: unknown): number => Math.trunc(Number(v ?? 0)) || 0;
const strOuNull = (v: unknown): string | null => (v === null || v === undefined ? null : String(v));

export class EconomiaRecurso extends Recurso {
  nome(): string {
    return 'economy';
  }

  rotas(): Rota[] {
    return [
      ['GET', 'transactions', 'transacoes', 'Compras da loja, uma linha por transação.', '?from=2026-08-01&to=2026-09-01&currency=credits&q=&limit=50'],
      ['GET', 'report', 'relatorio', 'Relatório do período, agregado POR MOEDA.', '?from=2026-08-01&to=2026-09-01'],
    ];
  }

  protected async transacoes(req: Requisicao): Promise<AppResponse> {
    const [limite, deslocamento] = this.pagina(req);
    const f = janela(req);
    const total = await Infra.contarLivro(f);
    const linhas = await Infra.listaLivro(f, limite, deslocamento);
    return ok(
      linhas.map((l) => ({
        id: int(l.id),
        bought_at: String(l.bought_at),
        // Nome nulo = a conta saiu do cadastro; a compra continua no livro.
        user: { id: int(l.user_id), username: strOuNull(l.username) },
        item: { id: int(l.item_id), slug: strOuNull(l.item_slug), name: strOuNull(l.item_name), category: String(l.item_category ?? '') },
        quantity: int(l.quantity),
        currency: String(l.currency),
        unit_price: int(l.price),
        total: int(l.total),
      })),
      { ...paginacao(total, limite, deslocamento), period: { from: f.de, to: f.ate } },
    );
  }

  protected async relatorio(req: Requisicao): Promise<AppResponse> {
    const f = janela(req);
    const porMoeda = await Infra.porMoeda(f);
    return ok({
      period: { from: f.de, to: f.ate },
      // Contagens podem somar moedas; dinheiro não.
      counts: await Infra.totaisLivro(f),
      by_currency: porMoeda.map((m) => ({
        currency: m.moeda,
        label: m.label,
        transactions: m.transacoes,
        units: m.unidades,
        players: m.jogadores,
        total: m.total,
        ticket: m.ticket,
        delta: m.delta,
      })),
      top_items: await Infra.topItens(f, 10),
      top_players: await Infra.topJogadores(f, 10),
    });
  }
}
