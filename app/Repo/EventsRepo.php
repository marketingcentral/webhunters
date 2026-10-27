<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Tudo o que a tela /admin/eventos lê e escreve. Três catálogos que o operador governa:
 *
 *   `challenges` + `challenge_bonuses` — os desafios da tela /desafios do jogo
 *   `news`                             — as notícias da HUD
 *   `system_notices`                   — os avisos do painel "MENSAGENS DO SISTEMA"
 *
 * Aqui vive TODO o SQL da tela. O controller decide o que perguntar, a view desenha; nenhum
 * dos dois monta consulta. Quem quiser conferir um número da tela abre este arquivo e roda a
 * consulta na mão — foi assim que a seção "verificação" do relatório foi escrita.
 *
 * Duas regras que valem para o arquivo inteiro:
 *
 * 1. LIMIT/OFFSET entram por INTERPOLAÇÃO DE INTEIRO, nunca por placeholder. O PDO do projeto
 *    roda com EMULATE_PREPARES desligado e manda todo parâmetro como string — `LIMIT '20'` é
 *    erro de sintaxe no MySQL. Os valores passam por (int) e por um teto antes de virar texto,
 *    então não existe caminho de injeção; todo o RESTO é parâmetro ligado.
 * 2. Nome de coluna em ORDER BY nunca vem cru da URL: a ordenação é escolhida dentro de um
 *    mapa fechado (ORDENS_*), e o que não estiver no mapa cai no padrão.
 */
final class EventsRepo
{
    /** Abas de desafio aceitas — as mesmas de ChallengeRepo::SCOPES, que é quem lê no jogo. */
    public const ESCOPOS = ['diarios', 'semanais', 'temporada'];

    /**
     * Cores de aviso que o JOGO sabe pintar: assets/css/pages/mensagens.css define
     * `.sys--gold/.sys--amber/.sys--red/.sys--cyan` e usa lime como padrão da variável.
     * Uma cor fora desta lista não quebra nada — simplesmente sai lime na HUD, o que faria o
     * painel prometer um destaque que o jogador nunca veria. Por isso a lista é fechada.
     */
    public const CORES_AVISO = ['lime', 'gold', 'amber', 'red', 'cyan'];

    /** Quantas linhas por página em qualquer uma das três abas. */
    public const POR_PAGINA = 12;

    /**
     * ORDER BY permitido em cada aba: chave da URL => expressão SQL (sem direção).
     * A chave `_id` de cada mapa é o desempate final, nunca oferecido na interface.
     */
    private const ORDENS_DESAFIOS = [
        'ordem'  => 'FIELD(c.scope, "diarios", "semanais", "temporada"), c.sort_order, c.id',
        'nome'   => 'c.label',
        'xp'     => 'c.xp',
        'cred'   => 'c.credits',
        'alvo'   => 'c.target',
        'jog'    => 'jogadores',
        '_id'    => 'c.id',
    ];
    private const ORDENS_NOTICIAS = [
        'data'   => 'n.published IS NULL, n.published',
        'ordem'  => 'n.sort_order, n.id',
        'nome'   => 'n.title',
        'cat'    => 'n.category IS NULL, n.category',
        '_id'    => 'n.id',
    ];
    private const ORDENS_AVISOS = [
        'criado' => 'a.created_at',
        'ordem'  => 'a.user_id IS NOT NULL, a.sort_order, a.id',
        'nome'   => 'a.title',
        'dest'   => 'a.user_id IS NOT NULL, u.username',
        '_id'    => 'a.id',
    ];

    // =====================================================================================
    // CONTAGENS DE CABEÇALHO
    // =====================================================================================

    /**
     * Os números das abas e dos KPIs. Uma consulta por catálogo, todas de COUNT — a tela abre
     * com isto mesmo quando a lista visível está filtrada a zero, e é o que impede a aba
     * vazia de parecer catálogo vazio.
     *
     * @return array{desafios:int,bonus:int,noticias:int,publicadas:int,rascunhos:int,avisos:int,globais:int,destacados:int}
     */
    public static function resumo(): array
    {
        $n = Database::one(
            'SELECT (SELECT COUNT(*) FROM challenges)                               AS desafios,
                    (SELECT COUNT(*) FROM challenges WHERE highlight = 1)           AS destacados,
                    (SELECT COUNT(*) FROM challenge_bonuses)                        AS bonus,
                    (SELECT COUNT(*) FROM news)                                     AS noticias,
                    (SELECT COUNT(*) FROM news WHERE published IS NOT NULL)         AS publicadas,
                    (SELECT COUNT(*) FROM news WHERE published IS NULL)             AS rascunhos,
                    (SELECT COUNT(*) FROM system_notices)                           AS avisos,
                    (SELECT COUNT(*) FROM system_notices WHERE user_id IS NULL)     AS globais'
        ) ?? [];

        return [
            'desafios'   => (int)($n['desafios']   ?? 0),
            'destacados' => (int)($n['destacados'] ?? 0),
            'bonus'      => (int)($n['bonus']      ?? 0),
            'noticias'   => (int)($n['noticias']   ?? 0),
            'publicadas' => (int)($n['publicadas'] ?? 0),
            'rascunhos'  => (int)($n['rascunhos']  ?? 0),
            'avisos'     => (int)($n['avisos']     ?? 0),
            'globais'    => (int)($n['globais']    ?? 0),
        ];
    }

    // =====================================================================================
    // DESAFIOS
    // =====================================================================================

    /**
     * Página de desafios já filtrada, ordenada e contada.
     *
     * `jogadores` e `concluidos` saem de `player_challenges` na mesma ida ao banco porque são
     * o que decide se a linha PODE ser excluída — perguntar depois, linha a linha, seria uma
     * consulta por botão desenhado.
     *
     * @param array{q?:string,escopo?:string,ord?:string,dir?:string,pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,paginas:int,pagina:int}
     */
    public static function desafios(array $f = []): array
    {
        [$where, $p] = self::filtroDesafios($f);
        $total  = (int)Database::value('SELECT COUNT(*) FROM challenges c' . $where, $p);
        [$pagina, $paginas, $offset] = self::janela($total, (int)($f['pagina'] ?? 1));

        $linhas = Database::all(
            'SELECT c.id, c.slug, c.scope, c.season_id, c.label, c.description, c.icon,
                    c.target, c.xp, c.credits, c.highlight, c.sort_order,
                    s.tag AS temporada,
                    (SELECT COUNT(*) FROM player_challenges pc WHERE pc.challenge_id = c.id) AS jogadores,
                    (SELECT COUNT(*) FROM player_challenges pc
                      WHERE pc.challenge_id = c.id AND pc.completed_at IS NOT NULL)          AS concluidos
               FROM challenges c
          LEFT JOIN seasons s ON s.id = c.season_id'
            . $where
            . self::ordenar(self::ORDENS_DESAFIOS, $f, 'ordem')
            . ' LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $p
        );

        return ['linhas' => $linhas, 'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filtroDesafios(array $f): array
    {
        $cond = [];
        $p    = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // Um placeholder POR COLUNA: com EMULATE_PREPARES desligado o PDO manda cada
            // `:nome` uma vez só para o servidor, e reusar o mesmo em três colunas devolve
            // "Invalid parameter number" em vez de resultado.
            $cond[] = '(c.label LIKE :q1 OR c.slug LIKE :q2 OR c.description LIKE :q3)';
            $p['q1'] = $p['q2'] = $p['q3'] = '%' . self::escaparLike($q) . '%';
        }
        $escopo = (string)($f['escopo'] ?? '');
        if (in_array($escopo, self::ESCOPOS, true)) {
            $cond[] = 'c.scope = :escopo';
            $p['escopo'] = $escopo;
        } elseif ($escopo === 'destaque') {
            $cond[] = 'c.highlight = 1';
        }
        return [$cond === [] ? '' : ' WHERE ' . implode(' AND ', $cond), $p];
    }

    /** @return array<string,mixed>|null */
    public static function desafio(int $id): ?array
    {
        return Database::one('SELECT * FROM challenges WHERE id = :id', ['id' => $id]);
    }

    /** Quantos jogadores têm progresso gravado neste desafio — é o que bloqueia a exclusão. */
    public static function progressoDoDesafio(int $id): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM player_challenges WHERE challenge_id = :id',
            ['id' => $id]
        );
    }

    public static function slugDesafioEmUso(string $slug, int $exceto = 0): bool
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM challenges WHERE slug = :s AND id <> :id',
            ['s' => $slug, 'id' => $exceto]
        ) > 0;
    }

    /** @param array<string,mixed> $d @return int id gravado */
    public static function salvarDesafio(array $d, int $id = 0): int
    {
        if ($id > 0) {
            Database::run(
                'UPDATE challenges
                    SET slug = :slug, scope = :scope, season_id = :season_id, label = :label,
                        description = :description, icon = :icon, target = :target, xp = :xp,
                        credits = :credits, highlight = :highlight, sort_order = :sort_order
                  WHERE id = :id',
                $d + ['id' => $id]
            );
            return $id;
        }
        return Database::insert('challenges', $d);
    }

    public static function excluirDesafio(int $id): void
    {
        Database::run('DELETE FROM challenges WHERE id = :id', ['id' => $id]);
    }

    // ---- bônus por escopo ---------------------------------------------------------------

    /**
     * Os bônus são um catálogo de TRÊS linhas com o escopo como chave primária — um por aba
     * do jogo. Por isso não têm busca nem paginação: a lista inteira sempre cabe na tela.
     *
     * @return list<array<string,mixed>>
     */
    public static function bonus(): array
    {
        return Database::all(
            'SELECT b.*, (SELECT COUNT(*) FROM challenges c WHERE c.scope = b.scope) AS desafios
               FROM challenge_bonuses b
              ORDER BY FIELD(b.scope, "diarios", "semanais", "temporada"), b.scope'
        );
    }

    /** @return array<string,mixed>|null */
    public static function bonusDoEscopo(string $escopo): ?array
    {
        return Database::one('SELECT * FROM challenge_bonuses WHERE scope = :s', ['s' => $escopo]);
    }

    /** @param array<string,mixed> $d */
    public static function salvarBonus(array $d, string $escopoOriginal = ''): void
    {
        if ($escopoOriginal !== '' && self::bonusDoEscopo($escopoOriginal) !== null) {
            // O escopo é a chave primária: editar não pode trocá-lo (a aba do jogo é ele).
            Database::run(
                'UPDATE challenge_bonuses
                    SET label = :label, title = :title, description = :description,
                        image = :image, xp = :xp, credits = :credits
                  WHERE scope = :scope',
                $d + ['scope' => $escopoOriginal]
            );
            return;
        }
        Database::insert('challenge_bonuses', $d);
    }

    public static function excluirBonus(string $escopo): void
    {
        Database::run('DELETE FROM challenge_bonuses WHERE scope = :s', ['s' => $escopo]);
    }

    /** Escopos que ainda NÃO têm bônus — é o que o formulário de criação pode oferecer. */
    public static function escoposSemBonus(): array
    {
        $tem = array_column(self::bonus(), 'scope');
        return array_values(array_diff(self::ESCOPOS, $tem));
    }

    // =====================================================================================
    // NOTÍCIAS
    // =====================================================================================

    /**
     * @param array{q?:string,estado?:string,cat?:string,ord?:string,dir?:string,pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,paginas:int,pagina:int}
     */
    public static function noticias(array $f = []): array
    {
        [$where, $p] = self::filtroNoticias($f);
        $total = (int)Database::value('SELECT COUNT(*) FROM news n' . $where, $p);
        [$pagina, $paginas, $offset] = self::janela($total, (int)($f['pagina'] ?? 1));

        $linhas = Database::all(
            'SELECT n.id, n.slug, n.title, n.category, n.excerpt, n.image, n.published, n.sort_order,
                    CHAR_LENGTH(COALESCE(n.body, "")) AS tamanho_corpo,
                    (SELECT COUNT(*) FROM maintenance_windows mw WHERE mw.news_slug = n.slug) AS janelas
               FROM news n'
            . $where
            . self::ordenar(self::ORDENS_NOTICIAS, $f, 'data', 'DESC')
            . ' LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $p
        );

        return ['linhas' => $linhas, 'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filtroNoticias(array $f): array
    {
        $cond = [];
        $p    = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $cond[] = '(n.title LIKE :q1 OR n.slug LIKE :q2 OR n.excerpt LIKE :q3)';
            $p['q1'] = $p['q2'] = $p['q3'] = '%' . self::escaparLike($q) . '%';
        }
        $estado = (string)($f['estado'] ?? '');
        if ($estado === 'publicada') {
            $cond[] = 'n.published IS NOT NULL';
        } elseif ($estado === 'rascunho') {
            $cond[] = 'n.published IS NULL';
        } elseif ($estado === 'futura') {
            $cond[] = 'n.published > CURDATE()';
        }
        $cat = trim((string)($f['cat'] ?? ''));
        if ($cat !== '') {
            $cond[] = 'n.category = :cat';
            $p['cat'] = $cat;
        }
        return [$cond === [] ? '' : ' WHERE ' . implode(' AND ', $cond), $p];
    }

    /** Categorias que EXISTEM no banco — o filtro nunca oferece uma opção que dá zero. */
    public static function categoriasDeNoticia(): array
    {
        return array_map(
            static fn(array $r): string => (string)$r['category'],
            Database::all('SELECT DISTINCT category FROM news WHERE category IS NOT NULL AND category <> "" ORDER BY category')
        );
    }

    /** @return array<string,mixed>|null */
    public static function noticia(int $id): ?array
    {
        return Database::one('SELECT * FROM news WHERE id = :id', ['id' => $id]);
    }

    public static function slugNoticiaEmUso(string $slug, int $exceto = 0): bool
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM news WHERE slug = :s AND id <> :id',
            ['s' => $slug, 'id' => $exceto]
        ) > 0;
    }

    /** Janelas de manutenção que apontam para este slug — é o que bloqueia a exclusão. */
    public static function janelasDaNoticia(string $slug): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM maintenance_windows WHERE news_slug = :s',
            ['s' => $slug]
        );
    }

    /** @param array<string,mixed> $d @return int id gravado */
    public static function salvarNoticia(array $d, int $id = 0): int
    {
        if ($id > 0) {
            Database::run(
                'UPDATE news
                    SET slug = :slug, title = :title, category = :category, excerpt = :excerpt,
                        body = :body, image = :image, published = :published, sort_order = :sort_order
                  WHERE id = :id',
                $d + ['id' => $id]
            );
            return $id;
        }
        return Database::insert('news', $d);
    }

    public static function excluirNoticia(int $id): void
    {
        Database::run('DELETE FROM news WHERE id = :id', ['id' => $id]);
    }

    // =====================================================================================
    // AVISOS DO SISTEMA
    // =====================================================================================

    /**
     * @param array{q?:string,destino?:string,ord?:string,dir?:string,pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,paginas:int,pagina:int}
     */
    public static function avisos(array $f = []): array
    {
        [$where, $p] = self::filtroAvisos($f);
        $total = (int)Database::value(
            'SELECT COUNT(*) FROM system_notices a LEFT JOIN users u ON u.id = a.user_id' . $where,
            $p
        );
        [$pagina, $paginas, $offset] = self::janela($total, (int)($f['pagina'] ?? 1));

        $linhas = Database::all(
            'SELECT a.id, a.user_id, a.icon, a.color, a.title, a.body, a.cta_label, a.cta_href,
                    a.fresh, a.created_at, a.sort_order, u.username
               FROM system_notices a
          LEFT JOIN users u ON u.id = a.user_id'
            . $where
            . self::ordenar(self::ORDENS_AVISOS, $f, 'criado', 'DESC')
            . ' LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $p
        );

        return ['linhas' => $linhas, 'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filtroAvisos(array $f): array
    {
        $cond = [];
        $p    = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $cond[] = '(a.title LIKE :q1 OR a.body LIKE :q2 OR u.username LIKE :q3)';
            $p['q1'] = $p['q2'] = $p['q3'] = '%' . self::escaparLike($q) . '%';
        }
        $destino = (string)($f['destino'] ?? '');
        if ($destino === 'global') {
            $cond[] = 'a.user_id IS NULL';
        } elseif ($destino === 'jogador') {
            $cond[] = 'a.user_id IS NOT NULL';
        } elseif ($destino === 'novo') {
            $cond[] = 'a.fresh = 1';
        }
        return [$cond === [] ? '' : ' WHERE ' . implode(' AND ', $cond), $p];
    }

    /** @return array<string,mixed>|null */
    public static function aviso(int $id): ?array
    {
        return Database::one(
            'SELECT a.*, u.username FROM system_notices a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE a.id = :id',
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $d @return int id gravado */
    public static function salvarAviso(array $d, int $id = 0): int
    {
        if ($id > 0) {
            Database::run(
                'UPDATE system_notices
                    SET user_id = :user_id, icon = :icon, color = :color, title = :title,
                        body = :body, cta_label = :cta_label, cta_href = :cta_href,
                        fresh = :fresh, sort_order = :sort_order
                  WHERE id = :id',
                $d + ['id' => $id]
            );
            return $id;
        }
        return Database::insert('system_notices', $d);
    }

    public static function excluirAviso(int $id): void
    {
        Database::run('DELETE FROM system_notices WHERE id = :id', ['id' => $id]);
    }

    /**
     * Resolve o destinatário digitado (username, sem diferenciar maiúsculas) para um id.
     * @return array<string,mixed>|null
     */
    public static function jogadorPorNome(string $username): ?array
    {
        return Database::one(
            'SELECT id, username, status FROM users WHERE username = :u LIMIT 1',
            ['u' => $username]
        );
    }

    /**
     * Sugestões do campo de destinatário. É `<datalist>`, não `<select>`: o painel precisa
     * funcionar com dez contas e com dez mil, e um select de dez mil não é uma escolha, é uma
     * rolagem. O nome digitado é sempre validado no servidor contra `users`.
     *
     * @return list<string>
     */
    public static function sugestoesDeJogador(int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        return array_map(
            static fn(array $r): string => (string)$r['username'],
            Database::all(
                'SELECT username FROM users ORDER BY last_login IS NULL, last_login DESC, id DESC LIMIT ' . $limite
            )
        );
    }

    // =====================================================================================
    // APOIO
    // =====================================================================================

    /** Temporadas para o seletor do desafio de escopo `temporada`. */
    public static function temporadas(): array
    {
        return Database::all('SELECT id, tag, pass_name, is_current FROM seasons ORDER BY number DESC, id DESC');
    }

    public static function temporadaExiste(int $id): bool
    {
        return (int)Database::value('SELECT COUNT(*) FROM seasons WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Página pedida x página que existe. Devolvido junto porque a barra de paginação e a
     * consulta precisam concordar: pedir ?p=99 numa lista de 2 páginas tem de mostrar a
     * última, e não uma tabela vazia com "página 99 de 2" no rodapé.
     *
     * @return array{0:int,1:int,2:int} [pagina, paginas, offset]
     */
    private static function janela(int $total, int $pedida): array
    {
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = max(1, min($paginas, $pedida > 0 ? $pedida : 1));
        return [$pagina, $paginas, ($pagina - 1) * self::POR_PAGINA];
    }

    /**
     * ORDER BY a partir de um mapa fechado. `$f['ord']` fora do mapa vira o padrão; a direção
     * é reduzida a ASC ou DESC. Nada da URL chega ao SQL como texto.
     *
     * @param array<string,string> $mapa
     * @param array<string,mixed>  $f
     */
    private static function ordenar(array $mapa, array $f, string $padrao, string $dirPadrao = 'ASC'): string
    {
        $chave = (string)($f['ord'] ?? '');
        if (str_starts_with($chave, '_') || !isset($mapa[$chave])) {
            $chave = $padrao;
            $dir   = $dirPadrao;
        } else {
            $dir = strtoupper((string)($f['dir'] ?? $dirPadrao)) === 'DESC' ? 'DESC' : 'ASC';
        }
        // O id entra como desempate final: sem ele, duas linhas de mesmo sort_order podem
        // trocar de lugar entre a página 1 e a página 2 e sumir da listagem.
        return ' ORDER BY ' . $mapa[$chave] . ' ' . $dir . ', ' . $mapa['_id'] . ' ASC';
    }

    /**
     * `%` e `_` digitados na busca são LITERAIS para quem procura, não curingas. Sem escapar,
     * buscar por "_" devolveria o catálogo inteiro.
     */
    private static function escaparLike(string $termo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo);
    }
}
