<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Catálogo de conquistas visto pelo PAINEL — todo o SQL da tela /admin/conquistas.
 *
 * Por que um repositório próprio e não AdminRepo: AdminRepo é a leitura do dashboard (métrica
 * agregada, só leitura). Aqui há ESCRITA em duas tabelas que precisam mudar juntas
 * (achievements + achievement_rewards) e contagem de uso em player_achievements. Misturar as
 * duas coisas no mesmo arquivo faria a tela mais frágil de auditar do que precisa ser.
 *
 * Três regras que valem para o arquivo inteiro:
 *
 *  1. Nenhum rótulo mora aqui. `category` sai como veio do banco ('combate'); quem traduz é
 *     app/Data/admin-conquistas.php. Assim uma categoria criada no banco por outro time não
 *     desaparece da tela só porque ninguém escreveu o nome bonito dela.
 *  2. Todo valor de usuário entra por parâmetro nomeado. As DUAS exceções (ORDER BY e LIMIT)
 *     não são valores: são vindos de whitelist e de (int), e estão comentadas no lugar.
 *  3. A contagem de uso é a razão de existir da trava de exclusão. `desbloqueios` conta
 *     jogador que CONCLUIU (completed_at preenchido); `progresso` conta quem começou e não
 *     terminou. Apagar a conquista levaria as duas coisas junto por ON DELETE CASCADE — por
 *     isso a tela precisa saber os dois números ANTES de oferecer o botão.
 */
final class AchievementRepo
{
    /** Estados derivados do uso — o filtro da lista e o selo de cada linha. */
    public const ESTADO_DESBLOQUEADA = 'desbloqueada';
    public const ESTADO_PROGRESSO    = 'progresso';
    public const ESTADO_PARADA       = 'parada';

    /** Ordenações aceitas: chave da URL => trecho de ORDER BY (NUNCA texto do usuário). */
    private const ORDENS = [
        'ordem'        => 'a.sort_order ASC, a.name ASC',
        'nome'         => 'a.name ASC',
        'desbloqueios' => 'desbloqueios DESC, a.sort_order ASC',
        'meta'         => 'a.target DESC, a.sort_order ASC',
    ];

    /** Tipos de recompensa que o jogo entende (achievement_rewards.kind). */
    public const RECOMPENSAS = ['xp', 'cash', 'points', 'item'];

    /** Recompensas que são um NÚMERO (as demais apontam para um item do catálogo). */
    public const RECOMPENSAS_NUMERICAS = ['xp', 'cash', 'points'];

    /** Teto de recompensas por conquista — ver o comentário do formulário na view. */
    public const MAX_RECOMPENSAS = 6;

    /* =====================================================================================
       LEITURA
       ===================================================================================== */

    /**
     * Uma página do catálogo, já com contagem de uso e com as recompensas de cada linha.
     *
     * @param array{busca?:string,categoria?:string,estado?:string,ordem?:string,pagina?:int,por_pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,pagina:int,paginas:int,por_pagina:int,inicio:int,fim:int}
     */
    public static function catalogo(array $f = []): array
    {
        $busca     = trim((string)($f['busca'] ?? ''));
        $categoria = trim((string)($f['categoria'] ?? ''));
        $estado    = trim((string)($f['estado'] ?? ''));
        $ordem     = self::ORDENS[(string)($f['ordem'] ?? '')] ?? self::ORDENS['ordem'];
        $porPagina = max(1, min(100, (int)($f['por_pagina'] ?? 20)));
        $pagina    = max(1, (int)($f['pagina'] ?? 1));

        [$onde, $p] = self::filtros($busca, $categoria, $estado);

        $total   = (int)Database::value('SELECT COUNT(*) FROM (' . self::baseSql($onde) . ') t', $p);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina  = min($pagina, $paginas);
        $offset  = ($pagina - 1) * $porPagina;

        /* LIMIT/OFFSET entram no texto do SQL porque MySQL em prepared statement NATIVO
           (Database desliga a emulação) rejeita esses dois pontos como parâmetro string —
           e Database::run passa tudo como string. Os dois já passaram por (int) acima e por
           min/max: não há caminho para um valor de usuário chegar aqui como texto.
           A ordenação vem de self::ORDENS, nunca do que veio na URL. */
        $sql = self::baseSql($onde) . ' ORDER BY a.category ASC, ' . $ordem
             . ' LIMIT ' . $porPagina . ' OFFSET ' . $offset;

        $linhas = Database::all($sql, $p);
        foreach ($linhas as $i => $l) {
            $linhas[$i] = self::normalizar($l);
        }

        // As recompensas de TODA a página numa consulta só — uma por linha viraria N+1 numa
        // tela que já é uma lista.
        $recs = self::recompensasDe(array_map(static fn(array $l): int => (int)$l['id'], $linhas));
        foreach ($linhas as $i => $l) {
            $linhas[$i]['recompensas'] = $recs[(int)$l['id']] ?? [];
        }

        return [
            'linhas'     => $linhas,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => $paginas,
            'por_pagina' => $porPagina,
            'inicio'     => $total === 0 ? 0 : $offset + 1,
            'fim'        => min($offset + $porPagina, $total),
        ];
    }

    /**
     * Os números do topo da tela. Todos medidos; nenhum estimado.
     *
     * @return array{conquistas:int,categorias:int,desbloqueios:int,jogadores:int,paradas:int,sem_recompensa:int}
     */
    public static function resumo(): array
    {
        $a = Database::one(
            'SELECT COUNT(*) AS conquistas, COUNT(DISTINCT category) AS categorias FROM achievements'
        ) ?? [];

        $u = Database::one(
            'SELECT COUNT(*) AS desbloqueios, COUNT(DISTINCT user_id) AS jogadores
               FROM player_achievements WHERE completed_at IS NOT NULL'
        ) ?? [];

        // "Parada" é a conquista que ninguém concluiu E ninguém começou: é o número que diz
        // se o catálogo tem conteúdo morto, e é para ele que a tela oferece o filtro.
        $paradas = (int)Database::value(
            'SELECT COUNT(*) FROM achievements a
              WHERE NOT EXISTS (SELECT 1 FROM player_achievements p
                                 WHERE p.achievement_id = a.id
                                   AND (p.completed_at IS NOT NULL OR p.progress > 0))'
        );

        $semRecompensa = (int)Database::value(
            'SELECT COUNT(*) FROM achievements a
              WHERE NOT EXISTS (SELECT 1 FROM achievement_rewards r WHERE r.achievement_id = a.id)'
        );

        return [
            'conquistas'     => (int)($a['conquistas'] ?? 0),
            'categorias'     => (int)($a['categorias'] ?? 0),
            'desbloqueios'   => (int)($u['desbloqueios'] ?? 0),
            'jogadores'      => (int)($u['jogadores'] ?? 0),
            'paradas'        => $paradas,
            'sem_recompensa' => $semRecompensa,
        ];
    }

    /**
     * Categorias que EXISTEM no banco, com quantas conquistas e quantos desbloqueios cada uma
     * tem. É a fonte do filtro e do painel lateral — e é assim que uma categoria inventada
     * fora daqui continua visível na tela em vez de sumir por falta de rótulo.
     *
     * @return list<array{slug:string,conquistas:int,desbloqueios:int}>
     */
    public static function categorias(): array
    {
        $linhas = Database::all(
            'SELECT a.category AS slug,
                    COUNT(*) AS conquistas,
                    COALESCE(SUM(u.desbloqueios), 0) AS desbloqueios
               FROM achievements a
               LEFT JOIN (SELECT achievement_id, COUNT(*) AS desbloqueios
                            FROM player_achievements
                           WHERE completed_at IS NOT NULL
                           GROUP BY achievement_id) u ON u.achievement_id = a.id
              GROUP BY a.category
              ORDER BY a.category ASC'
        );

        return array_map(static fn(array $l): array => [
            'slug'         => (string)$l['slug'],
            'conquistas'   => (int)$l['conquistas'],
            'desbloqueios' => (int)$l['desbloqueios'],
        ], $linhas);
    }

    /** @return array<string,mixed>|null Uma conquista com contagem de uso, ou null. */
    public static function porId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $linha = Database::one(self::baseSql('a.id = :id'), ['id' => $id]);
        if ($linha === null) {
            return null;
        }
        $linha = self::normalizar($linha);
        $linha['recompensas'] = self::recompensasDe([$id])[$id] ?? [];
        return $linha;
    }

    /** O dono do slug, quando já houver um (usado pela validação de unicidade). */
    public static function porSlug(string $slug, int $excetoId = 0): ?array
    {
        return Database::one(
            'SELECT id, name FROM achievements WHERE slug = :s AND id <> :e LIMIT 1',
            ['s' => $slug, 'e' => $excetoId]
        );
    }

    /**
     * Uso de UMA conquista — a consulta que autoriza (ou proíbe) a exclusão.
     *
     * @return array{jogadores:int,desbloqueios:int,progresso:int}
     */
    public static function uso(int $id): array
    {
        $l = Database::one(
            'SELECT COUNT(*) AS jogadores,
                    COALESCE(SUM(completed_at IS NOT NULL), 0) AS desbloqueios,
                    COALESCE(SUM(completed_at IS NULL AND progress > 0), 0) AS progresso
               FROM player_achievements WHERE achievement_id = :id',
            ['id' => $id]
        ) ?? [];

        return [
            'jogadores'    => (int)($l['jogadores'] ?? 0),
            'desbloqueios' => (int)($l['desbloqueios'] ?? 0),
            'progresso'    => (int)($l['progresso'] ?? 0),
        ];
    }

    /**
     * Itens que podem ser recompensa. Lista inteira porque o catálogo tem dezenas de linhas,
     * não milhares — quando passar disso, aqui é o lugar de trocar por busca.
     *
     * @return list<array{id:int,name:string,category:string,rarity:string}>
     */
    public static function itens(): array
    {
        $linhas = Database::all(
            'SELECT id, name, category, rarity FROM items ORDER BY category ASC, name ASC'
        );
        return array_map(static fn(array $l): array => [
            'id'       => (int)$l['id'],
            'name'     => (string)$l['name'],
            'category' => (string)$l['category'],
            'rarity'   => (string)$l['rarity'],
        ], $linhas);
    }

    /** Existe mesmo este item? (a validação não confia no <select> que voltou do navegador) */
    public static function itemExiste(int $id): bool
    {
        return $id > 0 && Database::value('SELECT 1 FROM items WHERE id = :id', ['id' => $id]) !== false;
    }

    /** Próxima posição livre dentro da categoria — o padrão do campo "ordem" ao criar. */
    public static function proximaOrdem(string $categoria): int
    {
        $max = Database::value(
            'SELECT MAX(sort_order) FROM achievements WHERE category = :c',
            ['c' => $categoria]
        );
        return $max === null || $max === false ? 0 : min(65535, (int)$max + 1);
    }

    /* =====================================================================================
       ESCRITA — conquista e recompensas mudam JUNTAS ou não mudam
       ===================================================================================== */

    /**
     * @param array<string,mixed> $dados campos já validados pelo controller
     * @param list<array{kind:string,amount:int,item_id:int|null}> $recompensas
     */
    public static function criar(array $dados, array $recompensas): int
    {
        return (int)Database::transaction(static function () use ($dados, $recompensas): int {
            $id = Database::insert('achievements', self::colunas($dados));
            self::gravarRecompensas($id, $recompensas);
            return $id;
        });
    }

    /**
     * @param array<string,mixed> $dados
     * @param list<array{kind:string,amount:int,item_id:int|null}> $recompensas
     */
    public static function atualizar(int $id, array $dados, array $recompensas): void
    {
        Database::transaction(static function () use ($id, $dados, $recompensas): void {
            Database::run(
                'UPDATE achievements
                    SET slug = :slug, name = :name, category = :category, description = :description,
                        icon = :icon, target = :target, sort_order = :sort_order
                  WHERE id = :id',
                self::colunas($dados) + ['id' => $id]
            );
            // Recompensa não tem identidade estável na tela (o operador reordena e apaga
            // linhas à vontade), então o conjunto é reescrito inteiro dentro da transação.
            Database::run('DELETE FROM achievement_rewards WHERE achievement_id = :id', ['id' => $id]);
            self::gravarRecompensas($id, $recompensas);
        });
    }

    /**
     * Apaga a conquista. NÃO decide se pode: quem decide é o controller, com uso().
     * As recompensas caem por ON DELETE CASCADE (fk_ar_ach).
     */
    public static function excluir(int $id): void
    {
        Database::run('DELETE FROM achievements WHERE id = :id', ['id' => $id]);
    }

    /* =====================================================================================
       INTERNO
       ===================================================================================== */

    /**
     * O SELECT que todas as leituras compartilham: a conquista mais as três contagens que a
     * tela precisa mostrar em cada linha.
     */
    private static function baseSql(string $onde): string
    {
        return 'SELECT a.id, a.slug, a.name, a.category, a.description, a.icon, a.target, a.sort_order,
                       COALESCE(u.jogadores, 0)    AS jogadores,
                       COALESCE(u.desbloqueios, 0) AS desbloqueios,
                       COALESCE(u.progresso, 0)    AS progresso,
                       COALESCE(r.recompensas, 0)  AS n_recompensas
                  FROM achievements a
                  LEFT JOIN (SELECT achievement_id,
                                    COUNT(*) AS jogadores,
                                    SUM(completed_at IS NOT NULL) AS desbloqueios,
                                    SUM(completed_at IS NULL AND progress > 0) AS progresso
                               FROM player_achievements
                              GROUP BY achievement_id) u ON u.achievement_id = a.id
                  LEFT JOIN (SELECT achievement_id, COUNT(*) AS recompensas
                               FROM achievement_rewards
                              GROUP BY achievement_id) r ON r.achievement_id = a.id
                 WHERE ' . $onde;
    }

    /**
     * Monta o WHERE da listagem. Cada filtro é uma cláusula independente e todo valor sai
     * como parâmetro nomeado.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtros(string $busca, string $categoria, string $estado): array
    {
        $onde = ['1 = 1'];
        $p    = [];

        if ($busca !== '') {
            // Busca no que o operador enxerga: nome, slug e o texto da descrição.
            // Três placeholders com nomes DIFERENTES para o mesmo valor: o PDO deste projeto
            // roda com prepared statements nativos (ATTR_EMULATE_PREPARES=false), e aí repetir
            // o mesmo nome na consulta estoura "Invalid parameter number".
            $onde[] = '(a.name LIKE :q1 OR a.slug LIKE :q2 OR a.description LIKE :q3)';
            $alvo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $busca) . '%';
            $p['q1'] = $alvo;
            $p['q2'] = $alvo;
            $p['q3'] = $alvo;
        }

        if ($categoria !== '') {
            $onde[] = 'a.category = :cat';
            $p['cat'] = $categoria;
        }

        $temConclusao = 'EXISTS (SELECT 1 FROM player_achievements p
                                  WHERE p.achievement_id = a.id AND p.completed_at IS NOT NULL)';
        $temProgresso = 'EXISTS (SELECT 1 FROM player_achievements p
                                  WHERE p.achievement_id = a.id AND p.progress > 0)';

        $onde[] = match ($estado) {
            self::ESTADO_DESBLOQUEADA => $temConclusao,
            self::ESTADO_PROGRESSO    => 'NOT ' . $temConclusao . ' AND ' . $temProgresso,
            self::ESTADO_PARADA       => 'NOT ' . $temConclusao . ' AND NOT ' . $temProgresso,
            default                   => '1 = 1',
        };

        return [implode(' AND ', $onde), $p];
    }

    /**
     * Recompensas de várias conquistas de uma vez, já com o nome do item resolvido.
     *
     * @param list<int> $ids
     * @return array<int,list<array<string,mixed>>>
     */
    private static function recompensasDe(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }

        // A lista do IN é montada com (int) — não há string de usuário neste caminho.
        $sql = 'SELECT r.id, r.achievement_id, r.kind, r.amount, r.item_id,
                       i.name AS item_nome, i.category AS item_categoria
                  FROM achievement_rewards r
                  LEFT JOIN items i ON i.id = r.item_id
                 WHERE r.achievement_id IN (' . implode(',', $ids) . ')
                 ORDER BY r.achievement_id ASC, r.id ASC';

        $fora = [];
        foreach (Database::all($sql) as $l) {
            $fora[(int)$l['achievement_id']][] = [
                'id'             => (int)$l['id'],
                'kind'           => (string)$l['kind'],
                'amount'         => (int)$l['amount'],
                'item_id'        => $l['item_id'] === null ? null : (int)$l['item_id'],
                'item_nome'      => $l['item_nome'] === null ? null : (string)$l['item_nome'],
                'item_categoria' => $l['item_categoria'] === null ? null : (string)$l['item_categoria'],
            ];
        }
        return $fora;
    }

    /**
     * Tipos do PHP no lugar dos do PDO e o estado derivado calculado UMA vez, aqui — para a
     * view não repetir a mesma conta em três colunas.
     *
     * @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function normalizar(array $l): array
    {
        $desbloqueios = (int)$l['desbloqueios'];
        $progresso    = (int)$l['progresso'];

        return [
            'id'            => (int)$l['id'],
            'slug'          => (string)$l['slug'],
            'name'          => (string)$l['name'],
            'category'      => (string)$l['category'],
            'description'   => $l['description'] === null ? '' : (string)$l['description'],
            'icon'          => $l['icon'] === null ? '' : (string)$l['icon'],
            'target'        => (int)$l['target'],
            'sort_order'    => (int)$l['sort_order'],
            'jogadores'     => (int)$l['jogadores'],
            'desbloqueios'  => $desbloqueios,
            'progresso'     => $progresso,
            'n_recompensas' => (int)$l['n_recompensas'],
            'estado'        => $desbloqueios > 0
                ? self::ESTADO_DESBLOQUEADA
                : ($progresso > 0 ? self::ESTADO_PROGRESSO : self::ESTADO_PARADA),
            // Uma conquista já conquistada por alguém não pode ser apagada: o histórico do
            // jogador cairia junto por CASCADE. A tela lê este campo para desarmar o botão,
            // e o controller confere de novo antes de gravar.
            'pode_excluir'  => $desbloqueios === 0,
            'recompensas'   => [],
        ];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function colunas(array $d): array
    {
        return [
            'slug'        => (string)$d['slug'],
            'name'        => (string)$d['name'],
            'category'    => (string)$d['category'],
            'description' => ($d['description'] ?? '') === '' ? null : (string)$d['description'],
            'icon'        => ($d['icon'] ?? '') === '' ? null : (string)$d['icon'],
            'target'      => (int)$d['target'],
            'sort_order'  => (int)$d['sort_order'],
        ];
    }

    /** @param list<array{kind:string,amount:int,item_id:int|null}> $recompensas */
    private static function gravarRecompensas(int $id, array $recompensas): void
    {
        foreach ($recompensas as $r) {
            Database::run(
                'INSERT INTO achievement_rewards (achievement_id, kind, amount, item_id)
                 VALUES (:a, :k, :m, :i)',
                [
                    'a' => $id,
                    'k' => (string)$r['kind'],
                    'm' => (int)$r['amount'],
                    'i' => $r['item_id'] === null ? null : (int)$r['item_id'],
                ]
            );
        }
    }
}
