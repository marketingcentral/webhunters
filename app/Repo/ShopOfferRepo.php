<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * A vitrine da loja pelo lado de dentro: `shop_offers` sempre conciliada com `items`.
 *
 * Uma oferta SOZINHA não é vendável — ela é um preço pendurado num item_id. Por isso não
 * existe aqui um método que devolva shop_offers pura: toda leitura já vem com o JOIN em items
 * (nome, raridade, arte, preço de catálogo). Uma linha órfã, se existisse, não apareceria na
 * loja do jogo (App\Repo\CatalogRepo::shopOffers também usa JOIN) e também não pode aparecer
 * no painel fingindo estar à venda.
 *
 * A REGRA DO PREÇO. O banco guarda `price` e `discount` separados e NÃO guarda o preço final:
 * quem calcula é o jogo, na hora da compra (App\Controllers\ShopController::offerToCard).
 * self::precoFinal() repete exatamente aquela conta — é a única forma de o número da tabela do
 * painel ser o mesmo que o jogador vê no botão COMPRAR. Se um dia a fórmula do jogo mudar,
 * muda nos dois lugares ou o painel passa a mentir.
 *
 * Preço aqui é MOEDA DE JOGO (créditos, dinheiro, pontos) — nunca real. O dashboard fala em
 * R$; esta tela não. Misturar as duas escalas na mesma cabeça é como o operador erra a mão
 * num preço por um fator de cem.
 */
final class ShopOfferRepo
{
    /** Moedas aceitas por `shop_offers.currency` — é a carteira que o jogador tem no perfil. */
    public const MOEDAS = ['credits', 'cash', 'points'];

    /** Teto do desconto. 100% sairia de graça; acima de 90 o preço final vira ruído. */
    public const DESCONTO_MAX = 90;

    /** `tag` é varchar(16): o banco corta em silêncio, então o formulário recusa antes. */
    public const TAG_MAX = 16;

    /** `sort_order` é smallint unsigned. */
    public const ORDEM_MAX = 65535;

    /**
     * Teto prático do preço. A coluna é int unsigned (4.294.967.295), mas um preço com dez
     * dígitos é sempre erro de digitação — e o painel recusa erro de digitação antes de o
     * jogador ver uma AK por 4 bilhões.
     */
    public const PRECO_MAX = 99999999;

    /** Quantos nichos a vitrine do jogo desenha por aba (app/Views/pages/loja.php). */
    public const NICHOS_VITRINE = 12;

    /** Linhas por página da listagem — o mesmo 12 da vitrine, para a leitura casar. */
    public const POR_PAGINA = 12;

    /**
     * Ordenações aceitas pela listagem.
     *
     * É uma LISTA BRANCA porque o trecho vai concatenado no SQL: `ORDER BY` não aceita
     * placeholder no MySQL. O valor que chega da query string nunca entra na consulta — só
     * a chave é usada para escolher uma destas expressões, escritas à mão.
     *
     * O desempate final é sempre `o.id`: sem ele, duas ofertas com a mesma ordem trocavam de
     * lugar entre dois carregamentos da mesma página e a paginação repetia (ou pulava) linha.
     *
     * `#ABA#` é substituído pela ordem EDITORIAL das abas (a de app/Data/loja.php, que é a
     * ordem em que o jogador vê as abas na loja) — e não por `o.category`, que ordenaria em
     * alfabeto e poria SKINS entre ARMAS e EQUIPAMENTO, contradizendo a fileira de abas
     * desenhada logo acima da tabela.
     */
    private const ORDENS = [
        // A lista de abas fica sempre na ordem da loja: inverter a ORDEM inverte a posição
        // dentro da aba, não a fila das abas — senão "ordem decrescente" embaralharia a tela.
        'ordem'     => '#ABA# ASC, o.sort_order %s',
        'item'      => 'i.name %s',
        'categoria' => '#ABA# %s, o.sort_order ASC',
        'preco'     => 'preco_final %s',
        'desconto'  => 'COALESCE(o.discount, 0) %s',
        'destaque'  => 'o.featured %s, #ABA# ASC, o.sort_order ASC',
    ];

    /** Estados do filtro rápido, como cláusula WHERE. Mesma razão de lista branca. */
    private const ESTADOS = [
        'destaque' => 'o.featured = 1',
        'comum'    => 'o.featured = 0',
        'promo'    => 'COALESCE(o.discount, 0) > 0',
        'cheio'    => 'COALESCE(o.discount, 0) = 0',
    ];

    /**
     * O preço que o jogador realmente paga.
     *
     * Cópia fiel de App\Controllers\ShopController::offerToCard(). O arredondamento é o do
     * PHP (meio para cima), igual ao do MySQL na expressão de ordenação — 12.999 com 15% dá
     * 11.049 nos dois.
     */
    public static function precoFinal(int $preco, ?int $desconto): int
    {
        $d = (int)$desconto;
        if ($d <= 0) {
            return $preco;
        }
        return (int)round($preco * (100 - min(100, $d)) / 100);
    }

    /**
     * Uma página da listagem, já com o item de cada oferta.
     *
     * @param array{q?:string,cat?:string,moeda?:string,estado?:string,ord?:string,dir?:string,pag?:int} $f
     * @return array{linhas:list<array<string,mixed>>, total:int, pagina:int, paginas:int, por_pagina:int}
     */
    public static function listar(array $f): array
    {
        [$where, $params] = self::filtro($f);

        $ord = isset(self::ORDENS[(string)($f['ord'] ?? '')]) ? (string)$f['ord'] : 'ordem';
        $dir = strtoupper((string)($f['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';
        $orderBy = str_replace(
            ['%s', '#ABA#'],
            [$dir, self::ordemDasAbas((array)($f['abas'] ?? []))],
            self::ORDENS[$ord]
        ) . ', o.id ASC';

        $total = (int)self::valor(
            'SELECT COUNT(*) FROM shop_offers o JOIN items i ON i.id = o.item_id WHERE ' . $where,
            $params
        );

        $porPagina = self::POR_PAGINA;
        $paginas   = max(1, (int)ceil($total / $porPagina));
        // Página fora do intervalo (filtro mudou com ?pag=9 na URL) volta para a última que
        // existe, em vez de mostrar uma tabela vazia que parece "nenhum resultado".
        $pagina    = max(1, min($paginas, (int)($f['pag'] ?? 1)));
        $offset    = ($pagina - 1) * $porPagina;

        $linhas = self::linhas(
            'SELECT o.id, o.item_id, o.category, o.price, o.currency, o.discount, o.tag,
                    o.featured, o.sort_order,
                    ROUND(o.price * (100 - COALESCE(o.discount, 0)) / 100) AS preco_final,
                    i.name AS item_nome, i.slug AS item_slug, i.rarity AS item_raridade,
                    i.image AS item_imagem, i.category AS item_categoria,
                    i.base_price AS item_preco_base, i.currency AS item_moeda_base
               FROM shop_offers o
               JOIN items i ON i.id = o.item_id
              WHERE ' . $where . '
              ORDER BY ' . $orderBy . '
              LIMIT ' . $porPagina . ' OFFSET ' . $offset,
            $params
        );

        foreach ($linhas as $i => $l) {
            $linhas[$i] = self::hidratar($l);
        }

        return [
            'linhas'     => $linhas,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    /** Uma oferta com o item embutido — o que o formulário e a confirmação leem. */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $l = self::linha(
            'SELECT o.id, o.item_id, o.category, o.price, o.currency, o.discount, o.tag,
                    o.featured, o.sort_order,
                    i.name AS item_nome, i.slug AS item_slug, i.rarity AS item_raridade,
                    i.image AS item_imagem, i.category AS item_categoria,
                    i.base_price AS item_preco_base, i.currency AS item_moeda_base
               FROM shop_offers o
               JOIN items i ON i.id = o.item_id
              WHERE o.id = :id LIMIT 1',
            ['id' => $id]
        );
        return $l === null ? null : self::hidratar($l);
    }

    /**
     * Catálogo inteiro para o seletor do formulário, com quantas ofertas cada item já tem.
     *
     * A contagem não é enfeite: é ela que deixa o operador ver, na própria lista, que a
     * AK-103 já está à venda — antes de criar a segunda oferta e receber a recusa.
     *
     * @return list<array{id:int,nome:string,slug:string,categoria:string,raridade:string,
     *                    preco_base:int,moeda_base:string,ofertas:int}>
     */
    public static function itensDoCatalogo(): array
    {
        $linhas = self::linhas(
            'SELECT i.id, i.name, i.slug, i.category, i.rarity, i.base_price, i.currency,
                    COUNT(o.id) AS ofertas
               FROM items i
          LEFT JOIN shop_offers o ON o.item_id = i.id
           GROUP BY i.id
           ORDER BY i.category ASC, i.name ASC'
        );

        $saida = [];
        foreach ($linhas as $l) {
            $saida[] = [
                'id'         => (int)$l['id'],
                'nome'       => (string)$l['name'],
                'slug'       => (string)$l['slug'],
                'categoria'  => (string)$l['category'],
                'raridade'   => (string)$l['rarity'],
                'preco_base' => (int)$l['base_price'],
                'moeda_base' => (string)$l['currency'],
                'ofertas'    => (int)$l['ofertas'],
            ];
        }
        return $saida;
    }

    /**
     * Os quatro números da faixa de indicadores.
     *
     * `itens_sem_oferta` é o único que olha para fora de shop_offers: é o tamanho do catálogo
     * parado, e é a pergunta que o operador da loja realmente faz ("o que ainda dá para pôr
     * à venda?").
     *
     * @return array{ofertas:int, destaque:int, promo:int, desconto_medio:int, itens:int,
     *                itens_sem_oferta:int, por_moeda:array<string,int>}
     */
    public static function resumo(): array
    {
        $l = self::linha(
            'SELECT COUNT(*) AS ofertas,
                    COALESCE(SUM(o.featured = 1), 0) AS destaque,
                    COALESCE(SUM(COALESCE(o.discount, 0) > 0), 0) AS promo,
                    COALESCE(AVG(NULLIF(o.discount, 0)), 0) AS desconto_medio
               FROM shop_offers o JOIN items i ON i.id = o.item_id'
        ) ?? [];

        $porMoeda = [];
        foreach (self::linhas(
            'SELECT o.currency, COUNT(*) AS n
               FROM shop_offers o JOIN items i ON i.id = o.item_id
           GROUP BY o.currency'
        ) as $m) {
            $porMoeda[(string)$m['currency']] = (int)$m['n'];
        }

        $itens = (int)self::valor('SELECT COUNT(*) FROM items');
        $comOferta = (int)self::valor('SELECT COUNT(DISTINCT item_id) FROM shop_offers');

        return [
            'ofertas'          => (int)($l['ofertas'] ?? 0),
            'destaque'         => (int)($l['destaque'] ?? 0),
            'promo'            => (int)($l['promo'] ?? 0),
            'desconto_medio'   => (int)round((float)($l['desconto_medio'] ?? 0)),
            'itens'            => $itens,
            'itens_sem_oferta' => max(0, $itens - $comOferta),
            'por_moeda'        => $porMoeda,
        ];
    }

    /**
     * Quantas ofertas cada aba tem hoje. Alimenta o contador do filtro, o aviso de aba cheia
     * e — o que mais importa — a recusa de excluir a última oferta de uma aba.
     *
     * @return array<string,int>
     */
    public static function contagemPorCategoria(): array
    {
        $saida = [];
        foreach (self::linhas(
            'SELECT o.category, COUNT(*) AS n
               FROM shop_offers o JOIN items i ON i.id = o.item_id
           GROUP BY o.category'
        ) as $l) {
            $saida[(string)$l['category']] = (int)$l['n'];
        }
        return $saida;
    }

    /**
     * A mesma dupla item+categoria já está publicada? (ignorando a própria oferta na edição)
     *
     * Não há UNIQUE no banco para isso, e não vai haver por patch: duas ofertas do mesmo item
     * em ABAS diferentes são legítimas (uma munição vendida em SUPRIMENTOS e em PACOTES). O
     * que não pode é o mesmo produto duas vezes na MESMA vitrine.
     */
    public static function categoriaDoDuplicado(int $itemId, string $categoria, int $ignorarId = 0): bool
    {
        return (int)self::valor(
            'SELECT COUNT(*) FROM shop_offers
              WHERE item_id = :i AND category = :c AND id <> :ig',
            ['i' => $itemId, 'c' => $categoria, 'ig' => $ignorarId]
        ) > 0;
    }

    /** @return array<string,mixed>|null Item do catálogo, para validar o item_id que chegou. */
    public static function item(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return self::linha(
            'SELECT id, name, slug, category, rarity, image, base_price, currency
               FROM items WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Histórico de compra do ITEM (não da oferta): player_purchases guarda item_id, não
     * offer_id. Por isso excluir a oferta não apaga compra nenhuma — e a tela de confirmação
     * diz exatamente isso, com o número na mão.
     *
     * @return array{n:int, ultima:int}
     */
    public static function comprasDoItem(int $itemId): array
    {
        $l = self::linha(
            'SELECT COUNT(*) AS n, MAX(bought_at) AS ultima FROM player_purchases WHERE item_id = :i',
            ['i' => $itemId]
        ) ?? [];
        $ultima = (string)($l['ultima'] ?? '');
        return [
            'n'      => (int)($l['n'] ?? 0),
            'ultima' => $ultima !== '' ? (int)strtotime($ultima) : 0,
        ];
    }

    /** Próxima posição livre no fim de uma aba — o padrão de quem cria uma oferta nova. */
    public static function proximaOrdem(string $categoria): int
    {
        $max = self::valor('SELECT MAX(sort_order) FROM shop_offers WHERE category = :c', ['c' => $categoria]);
        return $max === null ? 0 : min(self::ORDEM_MAX, (int)$max + 1);
    }

    /**
     * A oferta imediatamente acima (ou abaixo) na MESMA aba, pela ordem que a loja usa.
     * É com ela que a movida troca de lugar.
     *
     * @param array<string,mixed> $oferta
     * @return array<string,mixed>|null
     */
    public static function vizinha(array $oferta, string $direcao): ?array
    {
        $acima = $direcao === 'cima';
        // O par (sort_order, id) é o que ordena a vitrine; comparar só sort_order deixaria
        // duas ofertas empatadas trocando de lugar sem sair do lugar.
        return self::linha(
            'SELECT id, sort_order FROM shop_offers
              WHERE category = :c
                AND (sort_order ' . ($acima ? '<' : '>') . ' :s
                     OR (sort_order = :s2 AND id ' . ($acima ? '<' : '>') . ' :i))
              ORDER BY sort_order ' . ($acima ? 'DESC' : 'ASC') . ', id ' . ($acima ? 'DESC' : 'ASC') . '
              LIMIT 1',
            [
                'c'  => (string)$oferta['category'],
                's'  => (int)$oferta['sort_order'],
                's2' => (int)$oferta['sort_order'],
                'i'  => (int)$oferta['id'],
            ]
        );
    }

    /**
     * Posição (1, 2, 3…) de uma oferta dentro da própria aba — o número que a mensagem de
     * confirmação da movida mostra. Contado da mesma forma que a vitrine ordena.
     *
     * @param array<string,mixed> $oferta
     */
    public static function posicaoNaAba(array $oferta): int
    {
        return 1 + (int)self::valor(
            'SELECT COUNT(*) FROM shop_offers
              WHERE category = :c
                AND (sort_order < :s OR (sort_order = :s2 AND id < :i))',
            [
                'c'  => (string)$oferta['category'],
                's'  => (int)$oferta['sort_order'],
                's2' => (int)$oferta['sort_order'],
                'i'  => (int)$oferta['id'],
            ]
        );
    }

    // ---------------------------------------------------------------------------- escrita

    /** @param array<string,mixed> $d @return int id da oferta criada */
    public static function criar(array $d): int
    {
        return Database::insert('shop_offers', self::colunas($d));
    }

    /** @param array<string,mixed> $d */
    public static function atualizar(int $id, array $d): void
    {
        $cols = self::colunas($d);
        $set  = [];
        foreach (array_keys($cols) as $c) {
            $set[] = '`' . $c . '` = :' . $c;
        }
        $cols['id'] = $id;
        Database::run('UPDATE shop_offers SET ' . implode(', ', $set) . ' WHERE id = :id', $cols);
    }

    public static function apagar(int $id): void
    {
        Database::run('DELETE FROM shop_offers WHERE id = :id', ['id' => $id]);
    }

    public static function definirDestaque(int $id, bool $destaque): void
    {
        Database::run('UPDATE shop_offers SET featured = :f WHERE id = :id', ['f' => $destaque ? 1 : 0, 'id' => $id]);
    }

    /**
     * Troca a posição de duas ofertas numa transação: metade de uma troca de ordem deixaria
     * duas ofertas na mesma posição, e a vitrine passaria a desempatar por id sem que ninguém
     * tenha pedido isso.
     */
    public static function trocarOrdem(int $idA, int $ordemA, int $idB, int $ordemB): void
    {
        Database::transaction(static function () use ($idA, $ordemA, $idB, $ordemB): void {
            Database::run('UPDATE shop_offers SET sort_order = :s WHERE id = :id', ['s' => $ordemB, 'id' => $idA]);
            Database::run('UPDATE shop_offers SET sort_order = :s WHERE id = :id', ['s' => $ordemA, 'id' => $idB]);
        });
    }

    // ------------------------------------------------------------------------------- apoio

    /** Só as colunas que existem em shop_offers — nada do formulário entra direto no SQL. */
    private static function colunas(array $d): array
    {
        return [
            'item_id'    => (int)$d['item_id'],
            'category'   => (string)$d['category'],
            'price'      => (int)$d['price'],
            'currency'   => (string)$d['currency'],
            'discount'   => ($d['discount'] ?? null) === null ? null : (int)$d['discount'],
            'tag'        => ($d['tag'] ?? null) === null || $d['tag'] === '' ? null : (string)$d['tag'],
            'featured'   => !empty($d['featured']) ? 1 : 0,
            'sort_order' => (int)$d['sort_order'],
        ];
    }

    /** Linha crua do JOIN -> a forma que controller e view usam, já tipada. */
    private static function hidratar(array $l): array
    {
        $preco    = (int)$l['price'];
        $desconto = $l['discount'] === null ? null : (int)$l['discount'];

        return [
            'id'             => (int)$l['id'],
            'item_id'        => (int)$l['item_id'],
            'category'       => (string)$l['category'],
            'price'          => $preco,
            'currency'       => (string)$l['currency'],
            'discount'       => $desconto,
            'tag'            => (string)($l['tag'] ?? ''),
            'featured'       => (int)$l['featured'] === 1,
            'sort_order'     => (int)$l['sort_order'],
            'preco_final'    => self::precoFinal($preco, $desconto),
            'item_nome'      => (string)$l['item_nome'],
            'item_slug'      => (string)$l['item_slug'],
            'item_raridade'  => (string)$l['item_raridade'],
            'item_imagem'    => (string)$l['item_imagem'],
            'item_categoria' => (string)$l['item_categoria'],
            'item_preco_base'=> (int)$l['item_preco_base'],
            'item_moeda_base'=> (string)$l['item_moeda_base'],
        ];
    }

    /**
     * WHERE + parâmetros da listagem. Tudo que vem do usuário é BINDADO; o que é concatenado
     * (ordenação, estado) sai de lista branca.
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function filtro(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // Busca no que o operador tem na mão: o nome que ele lê na loja, o slug que ele
            // vê no banco, a etiqueta e o próprio id da oferta (é o que aparece na URL).
            //
            // Três placeholders com o MESMO valor, e não um repetido: o PDO deste projeto roda
            // sem emulação (Database::pdo, ATTR_EMULATE_PREPARES => false) e o driver do MySQL
            // recusa o mesmo nome duas vezes na consulta — "Invalid parameter number", que a
            // embalagem transformaria em lista vazia e a tela mostraria como "nada encontrado".
            $where[]       = '(i.name LIKE :q1 OR i.slug LIKE :q2 OR o.tag LIKE :q3 OR o.id = :qid)';
            $curinga       = '%' . self::escaparLike($q) . '%';
            $params['q1']  = $curinga;
            $params['q2']  = $curinga;
            $params['q3']  = $curinga;
            $params['qid'] = ctype_digit($q) ? (int)$q : 0;
        }

        $cat = (string)($f['cat'] ?? '');
        if ($cat !== '') {
            $where[]           = 'o.category = :cat';
            $params['cat']     = $cat;
        }

        $moeda = (string)($f['moeda'] ?? '');
        if (in_array($moeda, self::MOEDAS, true)) {
            $where[]           = 'o.currency = :moeda';
            $params['moeda']   = $moeda;
        }

        $estado = (string)($f['estado'] ?? '');
        if (isset(self::ESTADOS[$estado])) {
            $where[] = self::ESTADOS[$estado];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * A ordem EDITORIAL das abas, como expressão SQL.
     *
     * Os slugs vêm de app/Data/admin-loja.php (arquivo do projeto, não entrada de usuário) e
     * ainda assim passam por um filtro estrito antes de entrar na string: `ORDER BY` não
     * aceita placeholder, e concatenar por confiança na origem é como um dia entra um dado
     * de fora por um caminho que ninguém releu.
     *
     * FIELD() devolve 0 para o que não está na lista — uma categoria antiga ordenaria ANTES
     * da primeira aba. O CASE joga essas para o fim, onde elas não atrapalham a leitura das
     * abas que existem.
     *
     * @param list<string> $abas
     */
    private static function ordemDasAbas(array $abas): string
    {
        $limpos = [];
        foreach ($abas as $slug) {
            $slug = (string)$slug;
            if (preg_match('/^[a-z0-9_-]{1,24}$/', $slug) === 1) {
                $limpos[] = "'" . $slug . "'";
            }
        }
        if ($limpos === []) {
            return 'o.category';
        }
        $field = 'FIELD(o.category, ' . implode(', ', $limpos) . ')';
        return 'CASE WHEN ' . $field . ' = 0 THEN 9999 ELSE ' . $field . ' END';
    }

    /** `%`, `_` e `\` digitados na busca são texto, não curinga. */
    private static function escaparLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    // As três embalagens abaixo seguem a mesma regra do AdminRepo: leitura que falha vira
    // vazio e o painel continua de pé, com o estado vazio honesto na tela. A ESCRITA não é
    // embalada de propósito — um INSERT que falha tem de estourar, para o controller não
    // gravar auditoria de uma coisa que não aconteceu.

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private static function linhas(string $sql, array $p = []): array
    {
        try {
            return Database::all($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/loja: ' . $e->getMessage());
            return [];
        }
    }

    /** @param array<string,mixed> $p @return array<string,mixed>|null */
    private static function linha(string $sql, array $p = []): ?array
    {
        try {
            return Database::one($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/loja: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string,mixed> $p */
    private static function valor(string $sql, array $p = []): mixed
    {
        try {
            return Database::value($sql, $p);
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/loja: ' . $e->getMessage());
            return null;
        }
    }
}
