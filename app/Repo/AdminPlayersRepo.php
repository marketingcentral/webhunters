<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Consultas da tela ADMIN · JOGADORES (/admin/jogadores).
 *
 * Separado de AdminRepo de propósito: AdminRepo é a guarda de acesso e o dashboard — coisas
 * que TODA tela do painel usa. Aqui mora só o que governa conta de jogador: a listagem
 * filtrada, o dossiê de um jogador e as três escritas da tela (papel/situação, banir,
 * revogar). Quem for escrever /admin/banimentos reaproveita `banAtivo`, `historicoBans`,
 * `aplicarBan` e `revogarBan` sem arrastar a listagem junto.
 *
 * `password_hash` NÃO é selecionado por nenhum método deste arquivo. Não é descuido evitado,
 * é regra: a tela não mostra e não edita senha, então o dado nem chega à camada de cima —
 * o que não é lido não vaza por um var_dump esquecido.
 *
 * Esquema: users/players/player_* (db/schema.sql) + users.role, users.status e bans
 * (db/patch_v6_admin.php). Nada aqui exige coluna nova.
 */
final class AdminPlayersRepo
{
    /** Linhas por página da listagem. */
    public const POR_PAGINA = 25;

    /** Papéis possíveis em users.role, do menos para o mais poderoso. */
    public const PAPEIS = ['player', 'admin', 'owner'];

    /** Situações possíveis em users.status. */
    public const SITUACOES = ['active', 'banned', 'suspended'];

    /**
     * Ordenações aceitas em ?ordem=. A URL nunca entra na cláusula ORDER BY: ela escolhe uma
     * CHAVE, e a chave escolhe um trecho de SQL escrito aqui. Concatenar o parâmetro seria
     * injeção mesmo com prepared statement no resto da consulta.
     */
    private const ORDENS = [
        'recentes' => 'u.created_at DESC, u.id DESC',
        'antigos'  => 'u.created_at ASC, u.id ASC',
        'acesso'   => 'u.last_login IS NULL, u.last_login DESC',
        'nome'     => 'u.username ASC',
        'nivel'    => 'p.level DESC, p.xp DESC, u.id ASC',
        'xp'       => 'p.xp DESC, u.id ASC',
    ];

    /** Tipos de punição aceitos em bans.kind. */
    public const TIPOS_BAN = ['temp', 'perma'];

    /* =====================================================================================
       LISTAGEM
       ===================================================================================== */

    /** @return list<string> Chaves válidas de ordenação, para o controller validar ?ordem=. */
    public static function ordens(): array
    {
        return array_keys(self::ORDENS);
    }

    /**
     * Página da listagem já filtrada, com o total para a paginação.
     *
     * O total sai de um COUNT com o MESMO WHERE — e não de count() sobre as linhas —, porque
     * a paginação precisa saber quantas contas o filtro encontrou, não quantas couberam na
     * página. Os dois usam o mesmo par (SQL, parâmetros) montado uma vez só.
     *
     * @param array{q?:string,papel?:string,situacao?:string,ordem?:string,pagina?:int} $f
     * @return array{linhas:list<array<string,mixed>>,total:int,pagina:int,paginas:int,offset:int}
     */
    public static function listar(array $f): array
    {
        [$where, $par] = self::filtro($f);

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM users u LEFT JOIN players p ON p.user_id = u.id ' . $where,
            $par
        );

        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = min(max(1, (int)($f['pagina'] ?? 1)), $paginas);
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        $ordem = self::ORDENS[(string)($f['ordem'] ?? 'recentes')] ?? self::ORDENS['recentes'];

        /* LIMIT/OFFSET entram interpolados porque o PDO deste projeto roda com prepares
           NATIVOS (ATTR_EMULATE_PREPARES = false): um placeholder ali chegaria ao MySQL como
           string e a consulta morreria. São inteiros derivados de cast — nunca texto da URL. */
        $linhas = Database::all(
            'SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login, u.last_ip,
                    p.display_name, p.avatar, p.level, p.xp, p.credits, p.player_rank,
                    ps.pass_level,
                    b.id AS ban_id, b.kind AS ban_kind, b.expires_at AS ban_expires, b.reason AS ban_reason
               FROM users u
               LEFT JOIN players p       ON p.user_id = u.id
               LEFT JOIN player_season ps ON ps.user_id = u.id
               LEFT JOIN bans b          ON b.id = (
                    SELECT bb.id FROM bans bb
                     WHERE bb.user_id = u.id AND bb.revoked_at IS NULL
                       AND (bb.kind = \'perma\' OR bb.expires_at IS NULL OR bb.expires_at > NOW())
                     ORDER BY bb.created_at DESC, bb.id DESC LIMIT 1
               )
             ' . $where . '
             ORDER BY ' . $ordem . '
             LIMIT ' . self::POR_PAGINA . ' OFFSET ' . $offset,
            $par
        );

        return [
            'linhas'  => $linhas,
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'offset'  => $offset,
        ];
    }

    /**
     * Contagem por situação para as abas do topo. Ignora a busca e o papel de propósito: as
     * abas são o retrato do CADASTRO INTEIRO, e um contador que muda junto com o filtro que
     * ele mesmo aplica não serve de referência para nada.
     *
     * @return array{total:int,active:int,banned:int,suspended:int}
     */
    public static function contagemPorSituacao(): array
    {
        $out = ['total' => 0, 'active' => 0, 'banned' => 0, 'suspended' => 0];
        foreach (Database::all('SELECT status, COUNT(*) n FROM users GROUP BY status') as $r) {
            $s = (string)$r['status'];
            $n = (int)$r['n'];
            $out['total'] += $n;
            if (isset($out[$s])) {
                $out[$s] = $n;
            }
        }
        return $out;
    }

    /**
     * Quatro números do cabeçalho da tela. `equipe` são as contas com acesso ao painel —
     * o único recorte da lista que o operador precisa checar sem abrir filtro nenhum.
     *
     * @return array{contas:int,equipe:int,novos7:int,punidos:int}
     */
    public static function resumo(): array
    {
        return [
            'contas'  => (int)Database::value('SELECT COUNT(*) FROM users'),
            'equipe'  => (int)Database::value("SELECT COUNT(*) FROM users WHERE role IN ('admin','owner')"),
            'novos7'  => (int)Database::value('SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)'),
            'punidos' => (int)Database::value(
                "SELECT COUNT(DISTINCT user_id) FROM bans
                  WHERE revoked_at IS NULL AND (kind = 'perma' OR expires_at IS NULL OR expires_at > NOW())"
            ),
        ];
    }

    /**
     * WHERE compartilhado pela listagem e pelo COUNT.
     *
     * @param array<string,mixed> $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtro(array $f): array
    {
        $cond = [];
        $par  = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // LIKE com escape manual: nome com '%' ou '_' é busca literal, não curinga.
            $alvo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            /* Três placeholders com o MESMO valor, e não um :q repetido: o PDO deste projeto
               roda com prepares NATIVOS, e nesse modo reaproveitar um nome em mais de um
               ponto da consulta estoura em HY093 (Invalid parameter number). */
            $par['q1'] = $par['q2'] = $par['q3'] = $alvo;
            $cond[] = "(u.username LIKE :q1 ESCAPE '\\\\'"
                    . " OR u.email LIKE :q2 ESCAPE '\\\\'"
                    . " OR p.display_name LIKE :q3 ESCAPE '\\\\')";
        }

        $papel = (string)($f['papel'] ?? '');
        if (in_array($papel, self::PAPEIS, true)) {
            $cond[] = 'u.role = :papel';
            $par['papel'] = $papel;
        }

        $situacao = (string)($f['situacao'] ?? '');
        if (in_array($situacao, self::SITUACOES, true)) {
            $cond[] = 'u.status = :situacao';
            $par['situacao'] = $situacao;
        }

        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $par];
    }

    /* =====================================================================================
       DOSSIÊ DE UM JOGADOR
       ===================================================================================== */

    /**
     * Conta + ficha de jogador + passe da temporada. Devolve null quando o id não existe —
     * quem chama transforma isso em 404, e não em tela meio vazia.
     *
     * @return array<string,mixed>|null
     */
    public static function conta(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::one(
            'SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login, u.last_ip,
                    p.display_name, p.avatar, p.level, p.xp, p.xp_next, p.credits, p.cash, p.points,
                    p.clan, p.class, p.player_rank, p.season_level, p.skill_points,
                    p.operational_state, p.hunter_rank, p.hunter_role, p.survivor_since,
                    ps.pass_level, ps.pass_xp, ps.week_done, ps.week_goal, ps.week_xp, ps.streak_days,
                    s.number AS season_number, s.pass_name, s.xp_per_level
               FROM users u
               LEFT JOIN players p        ON p.user_id = u.id
               LEFT JOIN player_season ps ON ps.user_id = u.id
               LEFT JOIN seasons s        ON s.id = ps.season_id
              WHERE u.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Inventário resumido: o painel não é o inventário do jogo, então não desenha a grade —
     * mostra quanto o jogador carrega, onde, e as peças de maior valor. É o suficiente para
     * responder "esta conta está com item impossível?", que é a pergunta de moderação.
     *
     * @return array{itens:int,peso:float,valor:int,containers:list<array<string,mixed>>,destaques:list<array<string,mixed>>}
     */
    public static function inventario(int $id): array
    {
        $tot = Database::one(
            'SELECT COALESCE(SUM(pi.quantity),0) itens,
                    COALESCE(SUM(i.weight_kg * pi.quantity),0) peso,
                    COALESCE(SUM(i.base_price * pi.quantity),0) valor
               FROM player_items pi JOIN items i ON i.id = pi.item_id
              WHERE pi.user_id = :id',
            ['id' => $id]
        ) ?? [];

        return [
            'itens' => (int)($tot['itens'] ?? 0),
            'peso'  => (float)($tot['peso'] ?? 0),
            'valor' => (int)($tot['valor'] ?? 0),
            'containers' => Database::all(
                'SELECT pi.container, COUNT(*) linhas, COALESCE(SUM(pi.quantity),0) itens,
                        COALESCE(SUM(i.weight_kg * pi.quantity),0) peso
                   FROM player_items pi JOIN items i ON i.id = pi.item_id
                  WHERE pi.user_id = :id
                  GROUP BY pi.container
                  ORDER BY itens DESC, pi.container ASC',
                ['id' => $id]
            ),
            'destaques' => Database::all(
                'SELECT i.name, i.rarity, i.category, pi.quantity, pi.container,
                        (i.base_price * pi.quantity) AS valor
                   FROM player_items pi JOIN items i ON i.id = pi.item_id
                  WHERE pi.user_id = :id
                  ORDER BY valor DESC, i.name ASC
                  LIMIT 6',
                ['id' => $id]
            ),
        ];
    }

    /**
     * Missões do jogador. `status = 'locked'` fica de fora: missão travada é ausência de
     * progresso, e listar dez linhas "0%" empurra para baixo as que contam.
     *
     * @return list<array<string,mixed>>
     */
    public static function missoes(int $id): array
    {
        return Database::all(
            "SELECT m.name, m.type, m.difficulty, m.act, COALESCE(m.target,0) alvo,
                    pm.status, pm.progress, pm.updated_at
               FROM player_missions pm JOIN missions m ON m.id = pm.mission_id
              WHERE pm.user_id = :id AND pm.status <> 'locked'
              ORDER BY FIELD(pm.status,'active','done','available','disponivel'), pm.updated_at DESC
              LIMIT 12",
            ['id' => $id]
        );
    }

    /**
     * Conquistas: o cartão mostra as concluídas e o quanto falta no resto, então precisa das
     * duas contagens além das linhas.
     *
     * @return array{feitas:int,total:int,linhas:list<array<string,mixed>>}
     */
    public static function conquistas(int $id): array
    {
        return [
            'feitas' => (int)Database::value(
                'SELECT COUNT(*) FROM player_achievements WHERE user_id = :id AND completed_at IS NOT NULL',
                ['id' => $id]
            ),
            'total' => (int)Database::value('SELECT COUNT(*) FROM achievements'),
            'linhas' => Database::all(
                'SELECT a.name, a.category, a.target, pa.progress, pa.completed_at
                   FROM player_achievements pa JOIN achievements a ON a.id = pa.achievement_id
                  WHERE pa.user_id = :id
                  ORDER BY pa.completed_at IS NULL, pa.completed_at DESC, pa.progress DESC
                  LIMIT 8',
                ['id' => $id]
            ),
        ];
    }

    /** @return list<array<string,mixed>> Reputação por facção. */
    public static function faccoes(int $id): array
    {
        return Database::all(
            'SELECT faction, reputation, tier, contract_active, locked_until, updated_at
               FROM player_factions WHERE user_id = :id
              ORDER BY reputation DESC, faction ASC',
            ['id' => $id]
        );
    }

    /**
     * Últimas partidas + o agregado da carreira. O agregado NÃO é a soma das linhas
     * mostradas: são as 8 últimas na tabela e a carreira inteira no rodapé.
     *
     * @return array{linhas:list<array<string,mixed>>,total:int,extracoes:int,abates:int,ganhos:int}
     */
    public static function partidas(int $id): array
    {
        $ag = Database::one(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(result = 'extraido'),0) extracoes,
                    COALESCE(SUM(kills),0) abates,
                    COALESCE(SUM(earnings),0) ganhos
               FROM player_matches WHERE user_id = :id",
            ['id' => $id]
        ) ?? [];

        return [
            'linhas' => Database::all(
                'SELECT pm.result, pm.kills, pm.xp, pm.earnings, pm.played_at, mp.name AS mapa
                   FROM player_matches pm LEFT JOIN maps mp ON mp.id = pm.map_id
                  WHERE pm.user_id = :id
                  ORDER BY pm.played_at DESC, pm.id DESC
                  LIMIT 8',
                ['id' => $id]
            ),
            'total'     => (int)($ag['total'] ?? 0),
            'extracoes' => (int)($ag['extracoes'] ?? 0),
            'abates'    => (int)($ag['abates'] ?? 0),
            'ganhos'    => (int)($ag['ganhos'] ?? 0),
        ];
    }

    /* =====================================================================================
       PUNIÇÕES
       ===================================================================================== */

    /**
     * O banimento em vigor, se houver. "Em vigor" é: não revogado E (permanente OU com prazo
     * ainda no futuro). Um temporário vencido não é ban ativo — é histórico.
     *
     * @return array<string,mixed>|null
     */
    public static function banAtivo(int $id): ?array
    {
        return Database::one(
            "SELECT b.*, ua.username AS admin_nome
               FROM bans b LEFT JOIN users ua ON ua.id = b.admin_id
              WHERE b.user_id = :id AND b.revoked_at IS NULL
                AND (b.kind = 'perma' OR b.expires_at IS NULL OR b.expires_at > NOW())
              ORDER BY b.created_at DESC, b.id DESC LIMIT 1",
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> Histórico completo de punições da conta. */
    public static function historicoBans(int $id): array
    {
        return Database::all(
            'SELECT b.id, b.reason, b.kind, b.expires_at, b.created_at, b.revoked_at,
                    ua.username AS admin_nome, ur.username AS revogou_nome
               FROM bans b
               LEFT JOIN users ua ON ua.id = b.admin_id
               LEFT JOIN users ur ON ur.id = b.revoked_by
              WHERE b.user_id = :id
              ORDER BY b.created_at DESC, b.id DESC
              LIMIT 10',
            ['id' => $id]
        );
    }

    /**
     * Aplica a punição: grava em `bans` E deixa users.status coerente, na mesma transação.
     * As duas coisas juntas ou nenhuma — meia punição (linha em bans e conta liberada, ou o
     * contrário) é pior que nenhuma, porque a tela e a porta de entrada passam a discordar.
     *
     * Quem escreve `users.status` é BanRepo::sincronizar(), e não um UPDATE fixo aqui: essa
     * função é a ÚNICA dona da coluna quando o motivo é punição. Antes, esta tela gravava
     * 'banned' na mão e a tela /admin/banimentos gravava pelo BanRepo — duas regras
     * paralelas que discordavam assim que uma conta tinha suspensão e ban ao mesmo tempo.
     */
    public static function aplicarBan(int $userId, int $adminId, string $motivo, string $tipo, ?string $expira): int
    {
        return (int)Database::transaction(static function () use ($userId, $adminId, $motivo, $tipo, $expira): int {
            $id = Database::insert('bans', [
                'user_id'    => $userId,
                'admin_id'   => $adminId,
                'reason'     => $motivo,
                'kind'       => $tipo,
                'expires_at' => $tipo === 'perma' ? null : $expira,
            ]);
            BanRepo::sincronizar($userId);
            return $id;
        });
    }

    /**
     * Revoga o banimento e recalcula a situação da conta, também em transação.
     * Só revoga o que ainda está em vigor: revogar duas vezes não reescreve quem revogou.
     *
     * O recálculo é por BanRepo::sincronizar() em vez de `status = 'active'` fixo porque a
     * conta pode ter OUTRA punição em vigor (uma suspensão aplicada em /admin/banimentos):
     * revogar um ban não pode liberar quem continua suspenso.
     */
    public static function revogarBan(int $banId, int $userId, int $adminId): bool
    {
        return (bool)Database::transaction(static function () use ($banId, $userId, $adminId): bool {
            $n = Database::run(
                'UPDATE bans SET revoked_at = NOW(), revoked_by = :admin
                  WHERE id = :id AND user_id = :user AND revoked_at IS NULL',
                ['admin' => $adminId, 'id' => $banId, 'user' => $userId]
            )->rowCount();

            if ($n === 0) {
                return false;
            }
            BanRepo::sincronizar($userId);
            return true;
        });
    }

    /**
     * Devolve à ativa as contas cuja punição com PRAZO já venceu.
     *
     * Existe porque não há cron neste projeto: sem esta varredura, "banir por 3 dias" seria
     * na prática "banir para sempre" na LISTAGEM — o prazo passaria e users.status
     * continuaria 'banned'. (Na porta de entrada quem trata o vencimento é
     * App\Core\AccountGuard, ao autenticar; aqui é só para a tela não mostrar uma punição
     * que já acabou.) É idempotente e roda na abertura da tela; devolve quantas contas
     * liberou, para o controller registrar na auditoria SÓ quando algo mudou de fato.
     *
     * Cobre 'suspended' junto com 'banned': a suspensão também tem prazo, e antes ela nunca
     * vencia por aqui. O EXISTS final limita o alcance a contas que TÊM histórico de
     * punição — uma conta marcada na mão, sem linha em `bans`, não é liberada por engano.
     */
    public static function liberarVencidos(): int
    {
        return Database::run(
            "UPDATE users u SET u.status = 'active'
              WHERE u.status IN ('banned', 'suspended')
                AND NOT EXISTS (
                    SELECT 1 FROM bans b
                     WHERE b.user_id = u.id AND b.revoked_at IS NULL
                       AND (b.expires_at IS NULL OR b.expires_at > NOW())
                )
                AND EXISTS (SELECT 1 FROM bans b2 WHERE b2.user_id = u.id)"
        )->rowCount();
    }

    /* =====================================================================================
       ESCRITA NA CONTA
       ===================================================================================== */

    /** Papel e situação. Nenhuma outra coluna de `users` é escrita por esta tela. */
    public static function atualizarConta(int $id, string $papel, string $situacao): void
    {
        Database::run(
            'UPDATE users SET role = :r, status = :s WHERE id = :id',
            ['r' => $papel, 's' => $situacao, 'id' => $id]
        );
    }

    /**
     * O rastro que a conta deixou no jogo. É o que decide se ela pode ser APAGADA: linha de
     * partida, compra e punição é registro histórico do mundo — apagar a conta apagaria a
     * prova, e é por isso que a tela bloqueia em vez de perguntar de novo.
     *
     * @return array<string,int> rótulo interno => quantidade (só o que for > 0)
     */
    public static function referencias(int $id): array
    {
        $consultas = [
            'partidas'   => 'SELECT COUNT(*) FROM player_matches WHERE user_id = :id',
            'compras'    => 'SELECT COUNT(*) FROM player_purchases WHERE user_id = :id',
            'punicoes'   => 'SELECT COUNT(*) FROM bans WHERE user_id = :id',
            'itens'      => 'SELECT COUNT(*) FROM player_items WHERE user_id = :id',
            'missoes'    => 'SELECT COUNT(*) FROM player_missions WHERE user_id = :id',
            'conquistas' => 'SELECT COUNT(*) FROM player_achievements WHERE user_id = :id',
        ];

        $out = [];
        foreach ($consultas as $chave => $sql) {
            $n = (int)Database::value($sql, ['id' => $id]);
            if ($n > 0) {
                $out[$chave] = $n;
            }
        }
        return $out;
    }

    /**
     * Remove a conta e a ficha de jogador. Só é chamado depois de `referencias()` voltar
     * vazio — ou seja, para conta que nunca jogou. As tabelas player_* são varridas mesmo
     * assim: se uma delas ganhar linha entre a checagem e o DELETE, o banco não fica com
     * órfão apontando para um user_id que não existe mais.
     */
    public static function excluirConta(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            foreach ([
                'player_items', 'player_missions', 'player_achievements', 'player_factions',
                'player_matches', 'player_season', 'player_settings', 'player_skills',
                'player_challenges', 'player_purchases', 'player_safe_roles',
                'player_bonus_claims', 'player_bug_reports', 'players',
            ] as $tabela) {
                Database::run('DELETE FROM `' . $tabela . '` WHERE user_id = :id', ['id' => $id]);
            }
            Database::run('DELETE FROM users WHERE id = :id', ['id' => $id]);
        });
    }

    /* =====================================================================================
       REGRAS DE HIERARQUIA (usadas pelo controller e pela view)
       ===================================================================================== */

    /** Peso do papel: jogador 0, administrador 1, dono 2. Vale para comparar autoridade. */
    public static function peso(string $papel): int
    {
        $i = array_search($papel, self::PAPEIS, true);
        return $i === false ? 0 : (int)$i;
    }

    /**
     * Um operador só age sobre conta de papel ESTRITAMENTE inferior ao seu, e nunca sobre a
     * própria. É a regra que impede o admin de rebaixar o colega, de se auto-promover e de
     * banir quem o baniria — sem ela, "não rebaixe a si mesmo" seria contornável em dois
     * cliques (rebaixo o outro admin, ele não me rebaixa).
     */
    public static function podeAgirSobre(array $operador, array $alvo): bool
    {
        if ((int)$operador['id'] === (int)$alvo['id']) {
            return false;
        }
        return self::peso((string)$operador['role']) > self::peso((string)$alvo['role']);
    }
}
