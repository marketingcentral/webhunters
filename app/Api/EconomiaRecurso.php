<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;
use App\Repo\TransactionsRepo;

/**
 * /api/v1/economy — o livro-caixa da loja do jogo, só leitura.
 *
 * NÃO EXISTE `economy:write` EM USO, e a ausência é a decisão. A API pode LER as transações e
 * o relatório, mas nenhum endpoint daqui cria, altera ou apaga uma compra: `player_purchases`
 * é registro histórico do que aconteceu no mundo. Uma linha inventada ali não é um dado
 * errado — é fraude gravada com a nossa própria assinatura, e uma linha apagada é a fraude
 * que some. Crédito de jogador se ajusta pelo painel, com nome e senha de quem ajustou.
 *
 * TRÊS MOEDAS, NUNCA UM TOTAL ÚNICO. credits, cash e points são unidades incomparáveis, e
 * TransactionsRepo já se recusa a somá-las. A API repete essa recusa: o relatório devolve uma
 * linha POR MOEDA. Um campo "total" nesta resposta seria um número com cara de receita e
 * significado nenhum — e seria justamente o campo que o integrador colocaria no painel dele.
 */
final class EconomiaRecurso extends Recurso
{
    /** Janela padrão do relatório, em dias. */
    private const DIAS_PADRAO = 30;

    public function nome(): string
    {
        return 'economy';
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return [
            ['GET', 'transactions', 'transacoes', 'Compras da loja, uma linha por transação.', '?from=2026-08-01&to=2026-09-01&currency=credits&q=&limit=50'],
            ['GET', 'report',       'relatorio',  'Relatório do período, agregado POR MOEDA.', '?from=2026-08-01&to=2026-09-01'],
        ];
    }

    protected function transacoes(Requisicao $req, Contexto $ctx, array $params): Response
    {
        [$limite, $deslocamento] = $this->pagina($req);
        $f = $this->janela($req);

        $total  = TransactionsRepo::contar($f);
        $linhas = TransactionsRepo::lista($f, 'data', 'desc', $limite, $deslocamento);

        return Resposta::ok(
            array_map(
                static fn(array $l): array => [
                    'id'        => (int)$l['id'],
                    'bought_at' => (string)$l['bought_at'],
                    'user'      => [
                        'id'       => (int)$l['user_id'],
                        // Nome nulo = a conta saiu do cadastro. A compra continua no livro,
                        // e o campo diz isso em vez de inventar um nome.
                        'username' => $l['username'] === null ? null : (string)$l['username'],
                    ],
                    'item'      => [
                        'id'       => (int)$l['item_id'],
                        'slug'     => $l['item_slug'] === null ? null : (string)$l['item_slug'],
                        'name'     => $l['item_name'] === null ? null : (string)$l['item_name'],
                        'category' => (string)($l['item_category'] ?? ''),
                    ],
                    'quantity'  => (int)$l['quantity'],
                    'currency'  => (string)$l['currency'],
                    'unit_price'=> (int)$l['price'],
                    'total'     => (int)$l['total'],
                ],
                $linhas
            ),
            Resposta::paginacao($total, $limite, $deslocamento) + ['period' => ['from' => $f['de'], 'to' => $f['ate']]]
        );
    }

    protected function relatorio(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $f = $this->janela($req);

        return Resposta::ok([
            'period'   => ['from' => $f['de'], 'to' => $f['ate']],
            // Contagens PODEM somar as três moedas: transação é transação, unidade é unidade.
            // O que não soma é dinheiro.
            'counts'   => TransactionsRepo::totais($f),
            'by_currency' => array_map(
                static fn(array $m): array => [
                    'currency'     => $m['moeda'],
                    'label'        => $m['label'],
                    'transactions' => $m['transacoes'],
                    'units'        => $m['unidades'],
                    'players'      => $m['jogadores'],
                    'total'        => $m['total'],
                    'ticket'       => $m['ticket'],
                    // Variação contra o período anterior de mesma duração; null quando não há
                    // base de comparação — "+100%" a partir do zero não informa nada.
                    'delta'        => $m['delta'],
                ],
                TransactionsRepo::porMoeda($f)
            ),
            'top_items'   => TransactionsRepo::topItens($f, 10),
            'top_players' => TransactionsRepo::topJogadores($f, 10),
        ]);
    }

    /**
     * A janela do período, no formato que TransactionsRepo espera.
     *
     * Sem `from`/`to`, são os últimos 30 dias — e não "tudo": um relatório do histórico
     * inteiro varre a tabela toda para responder uma pergunta que quase ninguém faz, e é o
     * tipo de chamada que derruba o banco quando alguém a põe num laço.
     *
     * @return array{de:string, ate:string, moeda:string, busca:string}
     */
    private function janela(Requisicao $req): array
    {
        $hoje = new \DateTimeImmutable('today');

        $de  = self::data($req->consulta('from')) ?? $hoje->modify('-' . self::DIAS_PADRAO . ' days')->format('Y-m-d');
        $ate = self::data($req->consulta('to'))   ?? $hoje->format('Y-m-d');
        // Datas trocadas seriam um recorte impossível (zero resultados sem explicação): o par
        // é endireitado e o período devolvido na resposta mostra o que de fato foi consultado.
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }

        $moeda = $req->consulta('currency');
        return [
            'de'    => $de,
            'ate'   => $ate,
            'moeda' => isset(TransactionsRepo::MOEDAS[$moeda]) ? $moeda : '',
            'busca' => mb_substr($req->consulta('q'), 0, 60),
        ];
    }

    /** 'AAAA-MM-DD' de verdade (31/02 não passa), ou null. */
    private static function data(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }
}
