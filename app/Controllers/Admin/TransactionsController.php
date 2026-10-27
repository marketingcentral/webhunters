<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\TransactionsRepo;

/**
 * Tela Transações do painel: /admin/transacoes.
 *
 * Governa: player_purchases (× users × items).
 *
 * O QUE ESTA TELA É
 * -----------------
 * Um LIVRO-CAIXA, e livro-caixa não se edita. A tela lê, recorta, soma e exporta; não
 * existe criar, editar nem excluir — as ações de escrita registradas em app/routes.php são
 * respondidas com a recusa explicada (ver `salvar()`), e não com um 500 por método ausente.
 *
 * O TRABALHO DO CONTROLLER
 * ------------------------
 * Traduzir a URL em um filtro válido e nada mais. Todo o SQL está em
 * App\Repo\TransactionsRepo; toda a geometria (o SVG das barras) está na view. Se um número
 * da tela estiver errado, o defeito está no repositório — não aqui.
 *
 * A URL É ENTRADA DE USUÁRIO, e é tratada como tal: período, moeda, ordenação, direção e
 * página passam por lista branca ou viram o padrão; datas fora de formato não silenciam,
 * viram ERRO DE CAMPO no formulário de filtro e a tela continua mostrando a janela padrão.
 * Uma tela de relatório que devolve o período errado sem avisar é pior do que uma que
 * recusa o parâmetro.
 *
 * A MOEDA NUNCA É SOMADA. `player_purchases.currency` guarda moeda de jogo (créditos,
 * dinheiro, pontos) e as três são incomparáveis; o total do período vem sempre quebrado por
 * moeda. Só contagens (transações, unidades, jogadores) atravessam as moedas.
 */
final class TransactionsController extends AdminController
{
    /** Janelas do seletor de período, em dias. `tudo` é tratado à parte. */
    private const JANELAS = [7, 30, 90];

    /** Janela usada quando a URL não pede nada — a que cabe num mês de operação. */
    private const JANELA_PADRAO = 30;

    /** Linhas por página da lista. */
    private const POR_PAGINA = 25;

    /** Teto do CSV: um arquivo de export não pode virar um jeito de derrubar o painel. */
    private const TETO_CSV = 5000;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $extremos = TransactionsRepo::extremos();
        $filtro   = $this->filtro($request, $extremos);

        // O CSV sai ANTES de montar a tela: ele usa o mesmo filtro e a mesma ordenação, mas
        // não tem gráfico, KPI nem paginação para calcular.
        if ($request->query('export') === 'csv') {
            return $this->csv($filtro);
        }

        $total   = TransactionsRepo::contar($filtro['f']);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = min(max(1, (int)($request->query('p') ?? 1)), $paginas);

        // Os dois agregados são caros (o por-moeda roda a janela atual E a anterior) e são
        // lidos duas vezes cada um: pela tela e pela topbar. Calculados aqui, uma vez só.
        $totais   = TransactionsRepo::totais($filtro['f']);
        $porMoeda = TransactionsRepo::porMoeda($filtro['f']);

        return $this->render(
            'admin-transacoes',
            $this->adminPage('transacoes', 'Transações', 'Compras da loja, por período e por moeda'),
            [
                'cromo'    => data_mock('admin-transacoes'),
                'filtro'   => $filtro,
                'extremos' => $extremos,
                'moedas'   => TransactionsRepo::moedasUsadas(),

                'totais'   => $totais,
                'moeda_a_moeda' => $porMoeda,
                'serie'    => TransactionsRepo::serie($filtro['f']),
                'linhas'   => TransactionsRepo::lista(
                    $filtro['f'],
                    $filtro['ord'],
                    $filtro['dir'],
                    self::POR_PAGINA,
                    ($pagina - 1) * self::POR_PAGINA
                ),
                'itens'     => TransactionsRepo::topItens($filtro['f'], 6),
                'jogadores' => TransactionsRepo::topJogadores($filtro['f'], 6),

                'paginacao' => [
                    'pagina'  => $pagina,
                    'paginas' => $paginas,
                    'total'   => $total,
                    'por'     => self::POR_PAGINA,
                    'primeiro'=> $total === 0 ? 0 : ($pagina - 1) * self::POR_PAGINA + 1,
                    'ultimo'  => min($total, $pagina * self::POR_PAGINA),
                ],

                // A casca do painel lê estas duas chaves.
                'admin_kpis'   => $this->kpisDaTopbar($totais, $porMoeda),
                'admin_alerts' => [],
            ]
        );
    }

    /**
     * POST /admin/transacoes/salvar — recusa explicada.
     *
     * A rota existe porque o roteador registra o trio para toda tela do painel. Sem este
     * método, um POST daria erro de método ausente (500) e o operador leria "o painel
     * quebrou" onde a resposta certa é "isto aqui não se edita, e eis o porquê". A tentativa
     * vai para a auditoria: alguém tentando alterar um livro-caixa é exatamente o tipo de
     * evento que a trilha existe para guardar.
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        return $this->recusar('transacao_escrita_recusada', 'salvar');
    }

    /** POST /admin/transacoes/excluir — mesma recusa, mesma razão. */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        return $this->recusar('transacao_exclusao_recusada', 'excluir');
    }

    /* =====================================================================================
       ENTRADA
       ===================================================================================== */

    /**
     * A URL virando filtro válido, com os erros de campo que a view precisa desenhar.
     *
     * Devolve TUDO o que o formulário precisa para se redesenhar: o filtro efetivo (`f`), o
     * que o operador digitou (`bruto` — para o campo não perder o texto recusado), a
     * ordenação e os erros por campo.
     *
     * @param array{n:int,primeira:?string,ultima:?string} $extremos
     * @return array{f:array{de:string,ate:string,moeda:string,busca:string},periodo:string,
     *               ord:string,dir:string,bruto:array<string,string>,erros:array<string,string>}
     */
    private function filtro(Request $request, array $extremos): array
    {
        $cromo = data_mock('admin-transacoes');
        $msg   = (array)($cromo['erros'] ?? []);
        $erros = [];
        $hoje  = date('Y-m-d');

        $periodo = (string)($request->query('periodo') ?? '');
        $deBruto  = trim((string)($request->query('de') ?? ''));
        $ateBruto = trim((string)($request->query('ate') ?? ''));

        // Datas digitadas ganham do seletor de período: quem preencheu o campo quis o campo.
        if ($deBruto !== '' || $ateBruto !== '') {
            $periodo = 'custom';
        } elseif (!in_array($periodo, ['tudo', '7', '30', '90'], true)) {
            $periodo = (string)self::JANELA_PADRAO;
        }

        if ($periodo === 'custom') {
            $de  = $this->data($deBruto);
            $ate = $this->data($ateBruto);

            if ($deBruto !== '' && $de === null)   { $erros['de']  = (string)($msg['data'] ?? 'Data inválida.'); }
            if ($ateBruto !== '' && $ate === null) { $erros['ate'] = (string)($msg['data'] ?? 'Data inválida.'); }

            // Um lado só preenchido é intenção legítima: "de X até hoje", "tudo até X".
            $de  ??= $this->primeiroDia($extremos);
            $ate ??= $hoje;

            if ($erros === [] && $de > $ate) {
                $erros['de'] = (string)($msg['invertido'] ?? 'A data inicial é posterior à final.');
            }
            if ($erros === [] && $de > $hoje) {
                $erros['de'] = (string)($msg['futuro'] ?? 'A data inicial é posterior a hoje.');
            }

            if ($erros !== []) {
                // Recusado o recorte, a tela volta para a janela padrão E diz que voltou.
                // Mostrar um período qualquer sem avisar seria mentir sobre o relatório.
                $periodo = (string)self::JANELA_PADRAO;
                $ate = $hoje;
                $de  = date('Y-m-d', strtotime($hoje . ' -' . (self::JANELA_PADRAO - 1) . ' day'));
            }
        } elseif ($periodo === 'tudo') {
            $de  = $this->primeiroDia($extremos);
            $ate = $hoje;
        } else {
            $dias = (int)$periodo;
            $dias = in_array($dias, self::JANELAS, true) ? $dias : self::JANELA_PADRAO;
            $ate  = $hoje;
            $de   = date('Y-m-d', strtotime($hoje . ' -' . ($dias - 1) . ' day'));
        }

        $moeda = trim((string)($request->query('moeda') ?? ''));
        if ($moeda !== '' && !in_array($moeda, TransactionsRepo::moedasUsadas(), true)) {
            $erros['moeda'] = (string)($msg['moeda'] ?? 'Moeda desconhecida.');
            $moeda = '';
        }

        $ord = (string)($request->query('ord') ?? 'data');
        if (!in_array($ord, TransactionsRepo::ordensValidas(), true)) {
            $ord = 'data';
        }
        $dir = strtolower((string)($request->query('dir') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return [
            'f'       => [
                'de'    => $de,
                'ate'   => $ate,
                'moeda' => $moeda,
                'busca' => mb_substr(trim((string)($request->query('q') ?? '')), 0, 60),
            ],
            'periodo' => $periodo,
            'ord'     => $ord,
            'dir'     => $dir,
            'bruto'   => ['de' => $deBruto, 'ate' => $ateBruto],
            'erros'   => $erros,
        ];
    }

    /** Data no formato do <input type="date"> ou null. Rejeita 2026-02-31 (mktime rola o mês). */
    private function data(string $v): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return null;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
    }

    /**
     * O primeiro dia com movimento, para o período "tudo". Sem nenhuma compra no banco, a
     * janela cai nos 30 dias padrão — um "tudo" que começa em 1970 desenharia um eixo de
     * vinte mil dias vazios.
     *
     * @param array{n:int,primeira:?string,ultima:?string} $extremos
     */
    private function primeiroDia(array $extremos): string
    {
        $primeira = $extremos['primeira'];
        if ($primeira === null) {
            return date('Y-m-d', strtotime('-' . (self::JANELA_PADRAO - 1) . ' day'));
        }
        return date('Y-m-d', (int)strtotime($primeira));
    }

    /* =====================================================================================
       SAÍDAS
       ===================================================================================== */

    /**
     * O mesmo recorte da tela, em CSV — o formato em que um relatório sai do painel e vira
     * planilha. Separador `;` e BOM porque o destino real é o Excel em pt-BR, que com `,`
     * joga a linha inteira numa célula só.
     *
     * O export é auditado: uma cópia do livro-caixa saindo do painel é exatamente o tipo de
     * evento que precisa ter dono, hora e recorte registrados.
     *
     * @param array{f:array{de:string,ate:string,moeda:string,busca:string},ord:string,dir:string} $filtro
     */
    private function csv(array $filtro): Response
    {
        $linhas = TransactionsRepo::lista($filtro['f'], $filtro['ord'], $filtro['dir'], self::TETO_CSV, 0);
        $total  = TransactionsRepo::contar($filtro['f']);

        $this->auditar(
            'transacoes_export',
            'player_purchases',
            null,
            'Exportou ' . count($linhas) . ' de ' . $total . ' transações em CSV.',
            $filtro['f'] + ['ordem' => $filtro['ord'] . ' ' . $filtro['dir'], 'teto' => self::TETO_CSV]
        );

        $saida = fopen('php://temp', 'r+');
        fputcsv($saida, ['id', 'data_hora', 'jogador_id', 'jogador', 'item_id', 'item',
                         'quantidade', 'preco_unitario', 'total', 'moeda'], ';', '"', '');
        foreach ($linhas as $l) {
            fputcsv($saida, [
                (int)$l['id'],
                (string)$l['bought_at'],
                (int)$l['user_id'],
                (string)($l['username'] ?? ''),
                (int)$l['item_id'],
                (string)($l['item_name'] ?? ''),
                (int)$l['quantity'],
                (int)$l['price'],
                (int)$l['total'],
                (string)$l['currency'],
            ], ';', '"', '');
        }
        rewind($saida);
        $corpo = (string)stream_get_contents($saida);
        fclose($saida);

        $nome = 'transacoes_' . $filtro['f']['de'] . '_a_' . $filtro['f']['ate']
              . ($filtro['f']['moeda'] !== '' ? '_' . $filtro['f']['moeda'] : '') . '.csv';

        // "\xEF\xBB\xBF" = BOM de UTF-8: sem ele o Excel lê Ã§ onde está ç.
        return (new Response("\xEF\xBB\xBF" . $corpo, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nome . '"',
        ]));
    }

    /** A recusa de escrita, igual para salvar e excluir. */
    private function recusar(string $acao, string $tentativa): Response
    {
        $cromo = data_mock('admin-transacoes');

        $this->auditar(
            $acao,
            'player_purchases',
            null,
            'Tentativa de ' . $tentativa . ' negada: a tela de transações é somente leitura.'
        );

        flash('error', (string)($cromo['somente_leitura'] ?? 'Transações não podem ser alteradas pelo painel.'));
        return $this->redirect('admin/transacoes');
    }

    /**
     * Os indicadores compactos da topbar, no recorte que está na tela.
     *
     * O terceiro slot NÃO repete "receita": com três moedas possíveis, um número só ali
     * seria falso. Ele mostra a moeda que dominou o período (a de maior número de
     * transações) com o total DELA, e o `sub` diz qual é.
     *
     * @param array{transacoes:int,unidades:int,jogadores:int} $totais
     * @param list<array<string,mixed>> $porMoeda
     * @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(array $totais, array $porMoeda): array
    {
        $dominante = null;
        foreach ($porMoeda as $m) {
            if ($dominante === null || (int)$m['transacoes'] > (int)$dominante['transacoes']) {
                $dominante = $m;
            }
        }

        return [
            [
                'icon'  => 'receipt_long',
                'label' => 'Transações no período',
                'value' => num($totais['transacoes']),
                'tone'  => 'cyan',
            ],
            [
                'icon'  => 'group',
                'label' => 'Jogadores compradores',
                'value' => num($totais['jogadores']),
                'tone'  => 'purple',
            ],
            [
                'icon'  => 'payments',
                'label' => $dominante !== null ? 'Total em ' . $dominante['label'] : 'Total do período',
                'value' => $dominante !== null ? num((int)$dominante['total']) : null,
                'sub'   => $dominante !== null && count($porMoeda) > 1
                    ? '+' . (count($porMoeda) - 1) . ' outra(s) moeda(s)'
                    : null,
                'tone'  => 'gold',
            ],
        ];
    }
}
