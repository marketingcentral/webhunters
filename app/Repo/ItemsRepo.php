<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Catálogo mestre de itens (`items`) para o painel administrativo.
 *
 * Todo SQL da tela /admin/itens vive aqui — o controller decide POLÍTICA (o que é válido, o
 * que pode ser excluído) e a view desenha; nenhum dos dois monta consulta. É o que permite
 * conferir um número da tela rodando o método sozinho no CLI, fora do navegador.
 *
 * PORQUE A COLUNA "EM INVENTÁRIOS" EXISTE: `player_items.item_id` tem FOREIGN KEY com
 * ON DELETE CASCADE. Apagar uma linha de `items` apagaria, em silêncio, todas as instâncias
 * daquele item na mochila de todo mundo — e `shop_offers` cai junto pelo mesmo motivo.
 * A contagem não é enfeite de tabela: é o dado que autoriza (ou proíbe) a exclusão.
 */
final class ItemsRepo
{
    /** Linhas por página da listagem. */
    public const POR_PAGINA = 20;

    /**
     * Ordenações aceitas -> ORDER BY. A chave vem da URL e NUNCA é interpolada: só o valor
     * desta tabela entra no SQL. `nome` é o desempate de todas, para a paginação ser estável
     * (duas linhas com o mesmo preço não podem trocar de página entre dois cliques).
     */
    private const ORDEM = [
        'nome'    => 'i.name ASC',
        'recente' => 'i.id DESC',
        'preco'   => 'i.base_price DESC, i.name ASC',
        'peso'    => 'i.weight_kg DESC, i.name ASC',
        'uso'     => 'inst DESC, i.name ASC',
    ];

    /**
     * Página da listagem, já com a contagem de posse de cada item.
     *
     * A posse vem de uma tabela derivada agregada UMA vez (e não de subconsulta por linha):
     * com o catálogo crescendo, uma correlacionada por linha viraria N+1 dentro do próprio SQL.
     *
     * @param array{q?:string,cat?:string,rar?:string,ord?:string,pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,pagina:int,paginas:int}
     */
    public static function listar(array $f = []): array
    {
        [$where, $params] = self::filtro($f);
        $ordem = self::ORDEM[(string)($f['ord'] ?? 'nome')] ?? self::ORDEM['nome'];

        $total = (int)Database::value("SELECT COUNT(*) FROM items i $where", $params);

        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = min(max(1, (int)($f['pagina'] ?? 1)), $paginas);
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        // LIMIT/OFFSET entram como inteiros já validados acima — o MySQL não aceita placeholder
        // em LIMIT com prepared statements reais (EMULATE_PREPARES está desligado).
        $linhas = Database::all(
            "SELECT i.id, i.slug, i.name, i.short_name, i.category, i.subtype, i.slot_type,
                    i.grid_w, i.grid_h, i.weight_kg, i.rarity, i.image, i.base_price, i.currency,
                    i.stackable, i.max_stack, i.durability_max, i.ammo_caliber, i.mag_capacity,
                    COALESCE(p.inst, 0) AS inst,
                    COALESCE(p.jogadores, 0) AS jogadores
               FROM items i
               LEFT JOIN (
                    SELECT item_id, COUNT(*) AS inst, COUNT(DISTINCT user_id) AS jogadores
                      FROM player_items GROUP BY item_id
               ) p ON p.item_id = i.id
               $where
              ORDER BY $ordem
              LIMIT " . self::POR_PAGINA . " OFFSET $offset",
            $params
        );

        return ['linhas' => $linhas, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /**
     * Cabeçalho de números da tela. Tudo medido, nada estimado.
     *
     * @return array{itens:int,instancias:int,donos:int,categorias:int,ofertas:int,orfaos:int}
     */
    public static function resumo(): array
    {
        $t = Database::one(
            'SELECT (SELECT COUNT(*) FROM items) AS itens,
                    (SELECT COUNT(*) FROM player_items) AS instancias,
                    (SELECT COUNT(DISTINCT user_id) FROM player_items) AS donos,
                    (SELECT COUNT(DISTINCT category) FROM items) AS categorias'
        ) ?? [];

        return [
            'itens'      => (int)($t['itens'] ?? 0),
            'instancias' => (int)($t['instancias'] ?? 0),
            'donos'      => (int)($t['donos'] ?? 0),
            'categorias' => (int)($t['categorias'] ?? 0),
            'ofertas'    => self::contarSeExistir('shop_offers'),
            // Item que ninguém possui, ninguém vende e ninguém ganha: existe no catálogo e não
            // chega a jogador nenhum. Não é erro — é o número que mostra o catálogo ocioso.
            'orfaos'     => (int)Database::value(
                'SELECT COUNT(*) FROM items i
                  WHERE NOT EXISTS (SELECT 1 FROM player_items pi WHERE pi.item_id = i.id)'
            ),
        ];
    }

    /** Categorias presentes no banco, com quantos itens cada uma tem. @return array<string,int> */
    public static function categoriasUsadas(): array
    {
        $out = [];
        foreach (Database::all('SELECT category, COUNT(*) n FROM items GROUP BY category') as $r) {
            $out[(string)$r['category']] = (int)$r['n'];
        }
        return $out;
    }

    /** Raridades presentes no banco. @return array<string,int> */
    public static function raridadesUsadas(): array
    {
        $out = [];
        foreach (Database::all('SELECT rarity, COUNT(*) n FROM items GROUP BY rarity') as $r) {
            $out[(string)$r['rarity']] = (int)$r['n'];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function porId(int $id): ?array
    {
        return $id > 0 ? Database::one('SELECT * FROM items WHERE id = :id LIMIT 1', ['id' => $id]) : null;
    }

    /**
     * Slug já em uso? `$exceto` é o próprio item numa edição — sem ele, salvar sem mexer no
     * slug acusaria conflito consigo mesmo.
     */
    public static function slugEmUso(string $slug, int $exceto = 0): bool
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM items WHERE slug = :s AND id <> :id',
            ['s' => $slug, 'id' => $exceto]
        ) > 0;
    }

    /**
     * Onde este item é referenciado. É a consulta que decide se o botão Excluir funciona.
     *
     * Cada chave carrega o EFEITO da exclusão, e os efeitos não são iguais: `player_items` e
     * `shop_offers` caem em CASCADE (some a posse / a oferta), enquanto as recompensas usam
     * SET NULL (a missão continua existindo, mas passa a premiar nada). As duas situações
     * bloqueiam, e a tela precisa poder explicar qual é qual.
     *
     * @return array{instancias:int,jogadores:int,ofertas:int,missoes:int,conquistas:int,total:int}
     */
    public static function usos(int $id): array
    {
        $posse = Database::one(
            'SELECT COUNT(*) AS inst, COUNT(DISTINCT user_id) AS jog FROM player_items WHERE item_id = :id',
            ['id' => $id]
        ) ?? [];

        $u = [
            'instancias' => (int)($posse['inst'] ?? 0),
            'jogadores'  => (int)($posse['jog'] ?? 0),
            'ofertas'    => self::contarSeExistir('shop_offers', $id),
            'missoes'    => self::contarSeExistir('mission_rewards', $id),
            'conquistas' => self::contarSeExistir('achievement_rewards', $id),
        ];
        // `jogadores` é recorte de `instancias`, não uma referência a mais: somá-lo contaria
        // a mesma posse duas vezes e inflaria o bloqueio.
        $u['total'] = $u['instancias'] + $u['ofertas'] + $u['missoes'] + $u['conquistas'];

        return $u;
    }

    /** @param array<string,mixed> $dados */
    public static function criar(array $dados): int
    {
        return Database::insert('items', $dados);
    }

    /**
     * UPDATE só das colunas entregues. A lista de colunas vem das CHAVES do array montado
     * pelo controller a partir de um esquema fixo — nunca de $_POST direto —, então não há
     * como um campo forjado no formulário virar coluna aqui.
     *
     * @param array<string,mixed> $dados
     */
    public static function atualizar(int $id, array $dados): void
    {
        if ($dados === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($dados) as $col) {
            $sets[] = "`$col` = :$col";
        }
        Database::run(
            'UPDATE items SET ' . implode(', ', $sets) . ' WHERE id = :id_alvo',
            $dados + ['id_alvo' => $id]
        );
    }

    public static function excluir(int $id): void
    {
        Database::run('DELETE FROM items WHERE id = :id', ['id' => $id]);
    }

    /**
     * WHERE + parâmetros dos filtros da listagem.
     *
     * @param array{q?:string,cat?:string,rar?:string} $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtro(array $f): array
    {
        $cond   = [];
        $params = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // LIKE com curinga nas pontas: o operador digita "ak" e espera achar "AK-103".
            // O escape dos curingas do próprio LIKE impede que um `%` digitado vire "tudo".
            //
            // Os três campos são concatenados em vez de comparados um a um porque o driver
            // roda prepared statement NATIVO (EMULATE_PREPARES desligado) e o MySQL rejeita o
            // mesmo `:q` usado três vezes — daria HY093. CONCAT_WS ainda ignora NULL sozinho,
            // então item sem `short_name` continua entrando na busca.
            $cond[] = "CONCAT_WS(' ', i.name, i.slug, i.short_name) LIKE :q";
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }

        $cat = trim((string)($f['cat'] ?? ''));
        if ($cat !== '') {
            $cond[] = 'i.category = :cat';
            $params['cat'] = $cat;
        }

        $rar = trim((string)($f['rar'] ?? ''));
        if ($rar !== '') {
            $cond[] = 'i.rarity = :rar';
            $params['rar'] = $rar;
        }

        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $params];
    }

    /**
     * COUNT numa tabela que pode não existir neste banco.
     *
     * `mission_rewards`, `achievement_rewards` e `shop_offers` vêm de db/schema.sql, mas um
     * banco montado por patches pode estar sem alguma delas. Deixar o erro subir travaria a
     * tela inteira; devolver 0 diria que NÃO HÁ referência — e liberaria uma exclusão perigosa.
     * Por isso a ausência é conferida antes, e só a ausência de verdade devolve 0.
     */
    private static function contarSeExistir(string $tabela, int $itemId = 0): int
    {
        static $existe = [];
        if (!isset($existe[$tabela])) {
            $existe[$tabela] = (int)Database::value(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $tabela]
            ) > 0;
        }
        if (!$existe[$tabela]) {
            return 0;
        }
        return $itemId > 0
            ? (int)Database::value("SELECT COUNT(*) FROM `$tabela` WHERE item_id = :id", ['id' => $itemId])
            : (int)Database::value("SELECT COUNT(*) FROM `$tabela` WHERE item_id IS NOT NULL");
    }
}
