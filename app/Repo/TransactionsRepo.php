<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Livro-caixa da loja do jogo: `player_purchases` × `users` × `items`.
 *
 * TODO o SQL da tela /admin/transacoes mora aqui. O controller só traduz a URL em filtro e a
 * view só desenha — assim um número errado tem UM lugar para ser conferido, e dá para rodar
 * a mesma consulta fora do navegador.
 *
 * REGRA QUE ATRAVESSA O ARQUIVO INTEIRO: `currency` é moeda DE JOGO (credits, cash, points).
 * São três unidades incomparáveis. Por isso não existe aqui nenhum `SUM(price*quantity)` sem
 * `GROUP BY currency`: somar as três produziria um número que não significa nada e que a
 * tela apresentaria com a cara de receita. O que soma a tela inteira são CONTAGENS —
 * transações, unidades e jogadores distintos.
 *
 * Sobre os JOINs: as FK de `player_purchases` são ON DELETE CASCADE, então em um banco
 * íntegro não há linha órfã. Mesmo assim o JOIN é LEFT: bancos de produção nascidos de
 * patch podem não ter a FK, e um livro-caixa que ESCONDE uma compra porque o item saiu do
 * catálogo é pior do que um que a mostra sem nome. A view rotula o que veio nulo.
 */
final class TransactionsRepo
{
    /**
     * As três moedas do jogo, na ordem em que a loja as apresenta (app/Data/loja.php).
     * `tom` é o nome da cor do design system — a mesma que o item usa na loja, para o
     * operador reconhecer a moeda pela cor sem ler o rótulo.
     */
    public const MOEDAS = [
        'credits' => ['label' => 'Créditos', 'curto' => 'CR', 'tom' => 'lime'],
        'cash'    => ['label' => 'Dinheiro', 'curto' => 'DIN', 'tom' => 'cyan'],
        'points'  => ['label' => 'Pontos',   'curto' => 'PT',  'tom' => 'amber'],
    ];

    /**
     * Colunas que a tabela aceita ordenar, e a expressão SQL de cada uma.
     *
     * É uma LISTA BRANCA, não um mapeamento de conveniência: `ord` vem da URL e é concatenado
     * na consulta (ORDER BY não aceita parâmetro vinculado). O que não está nesta lista vira
     * a ordenação padrão.
     */
    private const ORDENS = [
        'data'    => 'pp.bought_at',
        'valor'   => 'pp.price * pp.quantity',
        'jogador' => 'u.username',
        'item'    => 'it.name',
        'id'      => 'pp.id',
    ];

    /** Acima desta quantidade de dias a barra por dia vira ilegível e o gráfico agrupa por semana. */
    private const DIAS_POR_DIA = 92;

    /* =====================================================================================
       LEITURAS
       ===================================================================================== */

    /**
     * Contagens do período — as únicas grandezas que podem somar moedas diferentes.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return array{transacoes:int,unidades:int,jogadores:int}
     */
    public static function totais(array $f): array
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);

        $l = Database::one(
            'SELECT COUNT(*)                    AS transacoes,
                    COALESCE(SUM(pp.quantity),0) AS unidades,
                    COUNT(DISTINCT pp.user_id)   AS jogadores
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde,
            $p
        ) ?? [];

        return [
            'transacoes' => (int)($l['transacoes'] ?? 0),
            'unidades'   => (int)($l['unidades'] ?? 0),
            'jogadores'  => (int)($l['jogadores'] ?? 0),
        ];
    }

    /**
     * Total, ticket médio e variação POR MOEDA. É o quadro que substitui o "total geral"
     * impossível: uma linha por moeda que teve movimento no período.
     *
     * `delta` compara com o período anterior de mesma duração e é null quando não há base —
     * um "+100%" a partir do zero não informa nada e a view mostra travessão.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return list<array{moeda:string,label:string,tom:string,curto:string,transacoes:int,
     *                    unidades:int,jogadores:int,total:int,ticket:float,delta:float|null}>
     */
    public static function porMoeda(array $f): array
    {
        $atual    = self::agregadoPorMoeda($f);
        $anterior = self::agregadoPorMoeda(self::janelaAnterior($f));

        $saida = [];
        foreach ($atual as $moeda => $a) {
            $meta  = self::MOEDAS[$moeda] ?? ['label' => $moeda, 'curto' => mb_strtoupper($moeda), 'tom' => 'muted'];
            $antes = (int)($anterior[$moeda]['total'] ?? 0);

            $saida[] = [
                'moeda'      => $moeda,
                'label'      => (string)$meta['label'],
                'curto'      => (string)$meta['curto'],
                'tom'        => (string)$meta['tom'],
                'transacoes' => $a['transacoes'],
                'unidades'   => $a['unidades'],
                'jogadores'  => $a['jogadores'],
                'total'      => $a['total'],
                'ticket'     => $a['transacoes'] > 0 ? $a['total'] / $a['transacoes'] : 0.0,
                'delta'      => $antes > 0 ? ($a['total'] - $antes) / $antes : null,
            ];
        }

        // A ordem é a da loja (créditos, dinheiro, pontos), não a que o banco devolveu.
        $ordem = array_keys(self::MOEDAS);
        usort($saida, static function (array $x, array $y) use ($ordem): int {
            $ix = array_search($x['moeda'], $ordem, true);
            $iy = array_search($y['moeda'], $ordem, true);
            return ($ix === false ? 99 : $ix) <=> ($iy === false ? 99 : $iy);
        });

        return $saida;
    }

    /**
     * Série do gráfico: um balde por dia (ou por semana, em período longo), por moeda.
     *
     * O SQL devolve SÓ os dias que tiveram compra; o eixo completo — inclusive os dias de
     * valor zero — é montado aqui em PHP. Sem isso, três compras espalhadas em trinta dias
     * virariam três barras coladas, sugerindo um movimento contínuo que não existiu.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return array{granularidade:string,moedas:list<array{moeda:string,label:string,tom:string,
     *               curto:string,max:int,total:int,pontos:list<array{rotulo:string,titulo:string,valor:int,n:int}>}>}
     */
    public static function serie(array $f): array
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);

        $linhas = Database::all(
            'SELECT DATE(pp.bought_at) AS dia, pp.currency,
                    SUM(pp.price * pp.quantity) AS total, COUNT(*) AS n
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde . '
              GROUP BY dia, pp.currency
              ORDER BY dia ASC',
            $p
        );

        $ini = (int)strtotime($f['de'] . ' 00:00:00');
        $fim = (int)strtotime($f['ate'] . ' 00:00:00');
        $dias = (int)floor(($fim - $ini) / 86400) + 1;
        $semanal = $dias > self::DIAS_POR_DIA;

        // Eixo: a lista fechada de baldes do período, do primeiro ao último, sem buracos.
        $baldes = [];
        for ($t = $ini; $t <= $fim; $t += 86400) {
            $chave = $semanal ? date('o-W', $t) : date('Y-m-d', $t);
            if (!isset($baldes[$chave])) {
                $baldes[$chave] = [
                    'rotulo' => $semanal ? date('d/m', $t) : date('d/m', $t),
                    'titulo' => $semanal
                        ? 'Semana de ' . date('d/m/Y', $t)
                        : date('d/m/Y', $t),
                ];
            }
        }

        /** @var array<string,array<string,array{valor:int,n:int}>> $porMoeda */
        $porMoeda = [];
        foreach ($linhas as $l) {
            $t     = (int)strtotime((string)$l['dia']);
            $chave = $semanal ? date('o-W', $t) : date('Y-m-d', $t);
            $m     = (string)$l['currency'];
            $porMoeda[$m][$chave]['valor'] = ($porMoeda[$m][$chave]['valor'] ?? 0) + (int)$l['total'];
            $porMoeda[$m][$chave]['n']     = ($porMoeda[$m][$chave]['n'] ?? 0) + (int)$l['n'];
        }

        $saida = [];
        foreach (array_keys(self::MOEDAS) as $moeda) {
            if (!isset($porMoeda[$moeda])) {
                continue;
            }
            $meta   = self::MOEDAS[$moeda];
            $pontos = [];
            $max    = 0;
            $total  = 0;
            foreach ($baldes as $chave => $b) {
                $v = (int)($porMoeda[$moeda][$chave]['valor'] ?? 0);
                $n = (int)($porMoeda[$moeda][$chave]['n'] ?? 0);
                $max = max($max, $v);
                $total += $v;
                $pontos[] = ['rotulo' => $b['rotulo'], 'titulo' => $b['titulo'], 'valor' => $v, 'n' => $n];
            }
            $saida[] = [
                'moeda'  => $moeda,
                'label'  => (string)$meta['label'],
                'curto'  => (string)$meta['curto'],
                'tom'    => (string)$meta['tom'],
                'max'    => $max,
                'total'  => $total,
                'pontos' => $pontos,
            ];
        }

        // Moeda fora do catálogo (dado antigo, importação): não pode sumir do gráfico.
        foreach ($porMoeda as $moeda => $valores) {
            if (isset(self::MOEDAS[$moeda])) {
                continue;
            }
            $pontos = [];
            $max = 0; $total = 0;
            foreach ($baldes as $chave => $b) {
                $v = (int)($valores[$chave]['valor'] ?? 0);
                $max = max($max, $v);
                $total += $v;
                $pontos[] = ['rotulo' => $b['rotulo'], 'titulo' => $b['titulo'],
                             'valor' => $v, 'n' => (int)($valores[$chave]['n'] ?? 0)];
            }
            $saida[] = ['moeda' => (string)$moeda, 'label' => (string)$moeda,
                        'curto' => mb_strtoupper((string)$moeda), 'tom' => 'muted',
                        'max' => $max, 'total' => $total, 'pontos' => $pontos];
        }

        return ['granularidade' => $semanal ? 'semana' : 'dia', 'moedas' => $saida];
    }

    /**
     * Uma página da lista, já com o nome do jogador e o nome do item resolvidos.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return list<array<string,mixed>>
     */
    public static function lista(array $f, string $ord, string $dir, int $limite, int $offset): array
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);

        $coluna = self::ORDENS[$ord] ?? self::ORDENS['data'];
        $dir    = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        // O id desempata: sem ele, duas compras no mesmo segundo trocam de lugar entre
        // páginas e uma delas some da paginação.
        $ordem  = $coluna . ' ' . $dir . ', pp.id ' . $dir;

        $limite = max(1, min(500, $limite));
        $offset = max(0, $offset);

        return Database::all(
            'SELECT pp.id, pp.quantity, pp.currency, pp.price, pp.bought_at,
                    pp.price * pp.quantity AS total,
                    pp.user_id, u.username, u.status AS user_status, u.role AS user_role,
                    pp.item_id, it.name AS item_name, it.slug AS item_slug,
                    it.category AS item_category, it.rarity AS item_rarity
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde . '
              ORDER BY ' . $ordem . '
              LIMIT ' . $limite . ' OFFSET ' . $offset,
            $p
        );
    }

    /** @param array{de:string,ate:string,moeda:string,busca:string} $f */
    public static function contar(array $f): int
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);

        return (int)Database::value(
            'SELECT COUNT(*)
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde,
            $p
        );
    }

    /**
     * Itens mais vendidos do período, por unidades. Um item vendido em duas moedas aparece
     * uma vez por moeda: juntar as duas linhas somaria valores incomparáveis.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return list<array{item_id:int,nome:string,moeda:string,unidades:int,transacoes:int,total:int}>
     */
    public static function topItens(array $f, int $n = 5): array
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);
        $n = max(1, min(50, $n));

        $linhas = Database::all(
            'SELECT pp.item_id, it.name AS nome, pp.currency,
                    SUM(pp.quantity) AS unidades, COUNT(*) AS transacoes,
                    SUM(pp.price * pp.quantity) AS total
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde . '
              GROUP BY pp.item_id, it.name, pp.currency
              ORDER BY unidades DESC, total DESC
              LIMIT ' . $n,
            $p
        );

        return array_map(static fn(array $l): array => [
            'item_id'    => (int)$l['item_id'],
            'nome'       => (string)($l['nome'] ?? ''),
            'moeda'      => (string)$l['currency'],
            'unidades'   => (int)$l['unidades'],
            'transacoes' => (int)$l['transacoes'],
            'total'      => (int)$l['total'],
        ], $linhas);
    }

    /**
     * Quem mais gastou no período, por moeda — mesma razão da lista de itens.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return list<array{user_id:int,nome:string,moeda:string,transacoes:int,total:int}>
     */
    public static function topJogadores(array $f, int $n = 5): array
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);
        $n = max(1, min(50, $n));

        $linhas = Database::all(
            'SELECT pp.user_id, u.username AS nome, pp.currency,
                    COUNT(*) AS transacoes, SUM(pp.price * pp.quantity) AS total
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde . '
              GROUP BY pp.user_id, u.username, pp.currency
              ORDER BY total DESC
              LIMIT ' . $n,
            $p
        );

        return array_map(static fn(array $l): array => [
            'user_id'    => (int)$l['user_id'],
            'nome'       => (string)($l['nome'] ?? ''),
            'moeda'      => (string)$l['currency'],
            'transacoes' => (int)$l['transacoes'],
            'total'      => (int)$l['total'],
        ], $linhas);
    }

    /**
     * O livro-caixa inteiro, sem filtro: quantas linhas existem e entre que datas.
     *
     * É o que separa "não há compra nenhuma" de "não há compra NESTE recorte" — dois estados
     * vazios com causas diferentes, que uma tela honesta não pode mostrar com a mesma frase.
     *
     * @return array{n:int,primeira:?string,ultima:?string}
     */
    public static function extremos(): array
    {
        $l = Database::one(
            'SELECT COUNT(*) AS n, MIN(bought_at) AS primeira, MAX(bought_at) AS ultima
               FROM player_purchases'
        ) ?? [];

        return [
            'n'        => (int)($l['n'] ?? 0),
            'primeira' => $l['primeira'] !== null ? (string)$l['primeira'] : null,
            'ultima'   => $l['ultima'] !== null ? (string)$l['ultima'] : null,
        ];
    }

    /**
     * Moedas que REALMENTE aparecem no livro-caixa (sem filtro de período).
     *
     * O seletor de moeda só oferece o que existe: um filtro "Pontos" que não pode devolver
     * nada é uma armadilha, não uma opção.
     *
     * @return list<string>
     */
    public static function moedasUsadas(): array
    {
        // Memorizada por requisição: a lista é lida na validação do filtro E de novo na
        // montagem do <select>, e o resultado não muda no meio de uma requisição.
        static $cache = null;
        if ($cache === null) {
            $cache = array_map(
                static fn(array $l): string => (string)$l['currency'],
                Database::all('SELECT DISTINCT currency FROM player_purchases ORDER BY currency')
            );
        }
        return $cache;
    }

    /* =====================================================================================
       APOIO
       ===================================================================================== */

    /** Rótulo de uma moeda, mesmo quando ela não está no catálogo. */
    public static function rotuloMoeda(string $moeda): string
    {
        return (string)(self::MOEDAS[$moeda]['label'] ?? $moeda);
    }

    /** Sigla curta da moeda, para acompanhar número em coluna estreita. */
    public static function siglaMoeda(string $moeda): string
    {
        return (string)(self::MOEDAS[$moeda]['curto'] ?? mb_strtoupper($moeda));
    }

    /** Cor do design system associada à moeda. */
    public static function tomMoeda(string $moeda): string
    {
        return (string)(self::MOEDAS[$moeda]['tom'] ?? 'muted');
    }

    /** Colunas que a lista aceita ordenar. @return list<string> */
    public static function ordensValidas(): array
    {
        return array_keys(self::ORDENS);
    }

    /**
     * Agregado bruto por moeda de UMA janela. Serve o período pedido e o anterior.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return array<string,array{transacoes:int,unidades:int,jogadores:int,total:int}>
     */
    private static function agregadoPorMoeda(array $f): array
    {
        ['sql' => $onde, 'p' => $p] = self::onde($f);

        $linhas = Database::all(
            'SELECT pp.currency,
                    COUNT(*)                        AS transacoes,
                    COALESCE(SUM(pp.quantity),0)    AS unidades,
                    COUNT(DISTINCT pp.user_id)      AS jogadores,
                    SUM(pp.price * pp.quantity)     AS total
               FROM player_purchases pp
               LEFT JOIN users u  ON u.id  = pp.user_id
               LEFT JOIN items it ON it.id = pp.item_id
              ' . $onde . '
              GROUP BY pp.currency',
            $p
        );

        $saida = [];
        foreach ($linhas as $l) {
            $saida[(string)$l['currency']] = [
                'transacoes' => (int)$l['transacoes'],
                'unidades'   => (int)$l['unidades'],
                'jogadores'  => (int)$l['jogadores'],
                'total'      => (int)$l['total'],
            ];
        }
        return $saida;
    }

    /**
     * A janela imediatamente anterior, de mesma duração — a base do `delta`.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return array{de:string,ate:string,moeda:string,busca:string}
     */
    private static function janelaAnterior(array $f): array
    {
        $ini = (int)strtotime($f['de'] . ' 00:00:00');
        $fim = (int)strtotime($f['ate'] . ' 00:00:00');
        $dur = max(1, (int)floor(($fim - $ini) / 86400) + 1);

        return [
            'de'    => date('Y-m-d', $ini - $dur * 86400),
            'ate'   => date('Y-m-d', $ini - 86400),
            'moeda' => $f['moeda'],
            'busca' => $f['busca'],
        ];
    }

    /**
     * O WHERE compartilhado por todas as consultas da tela, com os valores VINCULADOS.
     *
     * O período entra como par de DATETIME calculado aqui (00:00:00 e 23:59:59) em vez de
     * `DATE(pp.bought_at) BETWEEN ...`: aplicar função na coluna descartaria o índice e, num
     * livro-caixa que só cresce, isso é a diferença entre a tela abrir e a tela travar.
     *
     * @param array{de:string,ate:string,moeda:string,busca:string} $f
     * @return array{sql:string,p:array<string,mixed>}
     */
    private static function onde(array $f): array
    {
        $cond = ['pp.bought_at >= :de', 'pp.bought_at <= :ate'];
        $p = [
            'de'  => $f['de'] . ' 00:00:00',
            'ate' => $f['ate'] . ' 23:59:59',
        ];

        if (($f['moeda'] ?? '') !== '') {
            $cond[] = 'pp.currency = :moeda';
            $p['moeda'] = $f['moeda'];
        }

        $busca = trim((string)($f['busca'] ?? ''));
        if ($busca !== '') {
            // LIKE com curinga nos dois lados: o operador digita "ak" e quer achar "AK-103".
            // O escape de % e _ é obrigatório — sem ele, "100%" vira "qualquer coisa".
            $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $busca) . '%';
            // Um placeholder POR OCORRÊNCIA. A conexão roda com PDO::ATTR_EMULATE_PREPARES
            // desligado (App\Core\Database), e aí o mesmo `:q` repetido em três colunas
            // devolve SQLSTATE[HY093] Invalid parameter number — o driver nativo vincula
            // posicionalmente e conta três parâmetros para um nome só.
            $partes = ['u.username LIKE :q1', 'it.name LIKE :q2', 'it.slug LIKE :q3'];
            $p['q1'] = $termo;
            $p['q2'] = $termo;
            $p['q3'] = $termo;
            // Busca numérica também casa o número da transação: é assim que se confere uma
            // linha citada num chamado de suporte.
            if (ctype_digit($busca)) {
                $partes[] = 'pp.id = :qid';
                $p['qid'] = (int)$busca;
            }
            $cond[] = '(' . implode(' OR ', $partes) . ')';
        }

        return ['sql' => 'WHERE ' . implode(' AND ', $cond), 'p' => $p];
    }
}
