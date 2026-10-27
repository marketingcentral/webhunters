<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * As leituras que a API precisa e que nenhuma tela do painel entrega no formato certo.
 *
 * POR QUE NÃO REAPROVEITAR AdminPlayersRepo::listar()
 * ---------------------------------------------------
 * Porque ele pagina por PÁGINA, com 25 linhas fixas — que é a decisão certa para uma tabela
 * desenhada e a errada para uma API, onde o cliente pede `?limit=200&offset=400` e precisa
 * receber exatamente isso. Adaptar aquele método para os dois usos o deixaria com dois modos
 * de paginação e transformaria qualquer ajuste da tela num risco para a API, e vice-versa.
 *
 * O que NÃO está aqui e é de propósito: nenhuma ESCRITA. A API altera conta por
 * AdminPlayersRepo::atualizarConta(), aplica punição por BanRepo::aplicar() e grava
 * parâmetro por AdminSettingsRepo::gravar() — os mesmos caminhos do painel. Reescrever a
 * regra de escrita aqui criaria duas versões dela, e a segunda seria a que esquece de
 * sincronizar `users.status` com a punição.
 *
 * `password_hash` não é selecionado por nenhum método deste arquivo. Mesma regra do
 * AdminPlayersRepo: o que não é lido não vaza.
 */
final class ApiGestaoRepo
{
    /**
     * Página da lista de jogadores, no vocabulário da API.
     *
     * @param array{q?:string, role?:string, status?:string} $f
     * @return array{linhas:list<array<string,mixed>>, total:int}
     */
    public static function jogadores(array $f, int $limite, int $deslocamento): array
    {
        $cond = [];
        $par  = [];

        $q = mb_substr(trim((string)($f['q'] ?? '')), 0, 60);
        if ($q !== '') {
            $termo = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            // Um placeholder por campo: com prepares nativos, repetir :q é "Invalid
            // parameter number". ESCAPE '!' para % e _ digitados serem texto, não coringa.
            $cond[] = "(u.username LIKE :q0 ESCAPE '!' OR u.email LIKE :q1 ESCAPE '!' OR p.display_name LIKE :q2 ESCAPE '!')";
            $par['q0'] = $par['q1'] = $par['q2'] = $termo;
        }

        // Lista branca nas duas: o valor da URL escolhe uma opção conhecida, nunca vira SQL.
        $papel = (string)($f['role'] ?? '');
        if (in_array($papel, AdminPlayersRepo::PAPEIS, true)) {
            $cond[] = 'u.role = :papel';
            $par['papel'] = $papel;
        }
        $situacao = (string)($f['status'] ?? '');
        if (in_array($situacao, AdminPlayersRepo::SITUACOES, true)) {
            $cond[] = 'u.status = :situacao';
            $par['situacao'] = $situacao;
        }

        $onde = $cond === [] ? '1' : implode(' AND ', $cond);

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM users u LEFT JOIN players p ON p.user_id = u.id WHERE ' . $onde,
            $par
        );

        $limite = max(1, min(200, $limite));
        $deslocamento = max(0, $deslocamento);

        // LIMIT/OFFSET interpolados: o PDO roda com prepares NATIVOS e um placeholder nessa
        // posição chegaria ao MySQL como texto. Os dois são int já presos a uma faixa.
        // `u.id` desempata a ordenação: sem ele, duas contas criadas no mesmo segundo trocam
        // de lugar entre páginas e uma delas some da listagem.
        $linhas = Database::all(
            'SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login,
                    p.display_name, p.level, p.xp, p.credits, p.cash, p.points, p.player_rank,
                    p.operational_state
               FROM users u
          LEFT JOIN players p ON p.user_id = u.id
              WHERE ' . $onde . '
           ORDER BY u.id DESC
              LIMIT ' . $limite . ' OFFSET ' . $deslocamento,
            $par
        );

        return ['linhas' => array_map(self::jogadorResumo(...), $linhas), 'total' => $total];
    }

    /**
     * Linha da listagem -> objeto da API, com os tipos certos.
     *
     * `email` fica FORA da listagem de propósito: uma varredura de /players devolveria a
     * base de e-mails inteira num único pedido, e listar não é o mesmo que consultar uma
     * conta. Quem precisa do e-mail pede /players/{id}, que é um acesso por vez e fica
     * registrado um a um no log da API.
     *
     * @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function jogadorResumo(array $l): array
    {
        return [
            'id'         => (int)$l['id'],
            'username'   => (string)$l['username'],
            'role'       => (string)$l['role'],
            'status'     => (string)$l['status'],
            'created_at' => (string)$l['created_at'],
            'last_login' => $l['last_login'] === null ? null : (string)$l['last_login'],
            'player'     => $l['display_name'] === null ? null : [
                'display_name'      => (string)$l['display_name'],
                'level'             => (int)$l['level'],
                'xp'                => (int)$l['xp'],
                'rank'              => (string)($l['player_rank'] ?? ''),
                'operational_state' => (string)($l['operational_state'] ?? ''),
                'wallet'            => [
                    'credits' => (int)$l['credits'],
                    'cash'    => (int)$l['cash'],
                    'points'  => (int)$l['points'],
                ],
            ],
        ];
    }

    /**
     * Uma conta inteira, no vocabulário da API. Null quando o id não existe.
     *
     * @return array<string,mixed>|null
     */
    public static function jogador(int $id): ?array
    {
        $c = AdminPlayersRepo::conta($id);
        if ($c === null) {
            return null;
        }
        $ban = AdminPlayersRepo::banAtivo($id);

        return [
            'id'         => (int)$c['id'],
            'username'   => (string)$c['username'],
            'email'      => (string)($c['email'] ?? ''),
            'role'       => (string)$c['role'],
            'status'     => (string)$c['status'],
            'created_at' => (string)$c['created_at'],
            'last_login' => $c['last_login'] === null ? null : (string)$c['last_login'],
            'last_ip'    => (string)($c['last_ip'] ?? ''),
            'player'     => $c['display_name'] === null ? null : [
                'display_name'      => (string)$c['display_name'],
                'level'             => (int)$c['level'],
                'xp'                => (int)$c['xp'],
                'xp_next'           => (int)$c['xp_next'],
                'rank'              => (string)($c['player_rank'] ?? ''),
                'clan'              => (string)($c['clan'] ?? ''),
                'class'             => (string)($c['class'] ?? ''),
                'skill_points'      => (int)$c['skill_points'],
                'operational_state' => (string)($c['operational_state'] ?? ''),
                'hunter_rank'       => (string)($c['hunter_rank'] ?? ''),
                'hunter_role'       => (string)($c['hunter_role'] ?? ''),
                'wallet'            => [
                    'credits' => (int)$c['credits'],
                    'cash'    => (int)$c['cash'],
                    'points'  => (int)$c['points'],
                ],
            ],
            // A punição vigente viaja junto com a conta porque `status = banned` sozinho não
            // diz nem por quê nem até quando — e essas são as duas perguntas de quem consulta.
            'ban'        => $ban === null ? null : [
                'id'         => (int)$ban['id'],
                'kind'       => (string)$ban['kind'],
                'reason'     => (string)($ban['reason'] ?? ''),
                'expires_at' => $ban['expires_at'] === null ? null : (string)$ban['expires_at'],
                'created_at' => (string)$ban['created_at'],
            ],
        ];
    }

    /**
     * Página do histórico de punições, com limit/offset.
     *
     * A expressão de estado vem de BanRepo::ESTADO — a MESMA que a tela /admin/banimentos
     * usa. Reescrevê-la aqui criaria duas definições de "ban ativo", e a primeira diferença
     * entre elas apareceria como um jogador que o painel diz estar banido e a API diz que não.
     *
     * @param array{user_id?:int, state?:string, kind?:string} $f
     * @return array{linhas:list<array<string,mixed>>, total:int}
     */
    public static function banimentos(array $f, int $limite, int $deslocamento): array
    {
        $cond = [];
        $par  = [];

        if ((int)($f['user_id'] ?? 0) > 0) {
            $cond[] = 'b.user_id = :uid';
            $par['uid'] = (int)$f['user_id'];
        }
        if (in_array((string)($f['kind'] ?? ''), array_keys(BanRepo::TIPOS), true)) {
            $cond[] = 'b.kind = :kind';
            $par['kind'] = (string)$f['kind'];
        }
        // O estado é DERIVADO (não há coluna): o filtro compara com a mesma expressão do
        // SELECT, e não com um campo — é por isso que ele entra num HAVING-like via CASE.
        $estado = (string)($f['state'] ?? '');
        if (in_array($estado, BanRepo::ESTADOS, true)) {
            $cond[] = '(' . BanRepo::ESTADO . ') = :estado';
            $par['estado'] = $estado;
        }

        $onde = $cond === [] ? '1' : implode(' AND ', $cond);

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM bans b JOIN users u ON u.id = b.user_id WHERE ' . $onde,
            $par
        );

        $limite = max(1, min(200, $limite));
        $deslocamento = max(0, $deslocamento);

        $linhas = Database::all(
            'SELECT b.id, b.user_id, b.admin_id, b.reason, b.kind, b.expires_at, b.created_at,
                    b.revoked_at, b.revoked_by, b.revoke_reason,
                    ' . BanRepo::ESTADO . ' AS estado,
                    u.username AS alvo_nome, a.username AS admin_nome, r.username AS revogador_nome
               FROM bans b
               JOIN users u ON u.id = b.user_id
          LEFT JOIN users a ON a.id = b.admin_id
          LEFT JOIN users r ON r.id = b.revoked_by
              WHERE ' . $onde . '
           ORDER BY b.created_at DESC, b.id DESC
              LIMIT ' . $limite . ' OFFSET ' . $deslocamento,
            $par
        );

        return ['linhas' => array_map(self::banimento(...), $linhas), 'total' => $total];
    }

    /**
     * Um ban pelo id, no formato da API. Null quando não existe.
     *
     * @return array<string,mixed>|null
     */
    public static function banimento(array|int $l): ?array
    {
        if (is_int($l)) {
            $l = Database::one(
                'SELECT b.*, ' . BanRepo::ESTADO . ' AS estado,
                        u.username AS alvo_nome, a.username AS admin_nome, r.username AS revogador_nome
                   FROM bans b
                   JOIN users u ON u.id = b.user_id
              LEFT JOIN users a ON a.id = b.admin_id
              LEFT JOIN users r ON r.id = b.revoked_by
                  WHERE b.id = :id LIMIT 1',
                ['id' => $l]
            );
            if ($l === null) {
                return null;
            }
        }

        return [
            'id'            => (int)$l['id'],
            'user_id'       => (int)$l['user_id'],
            'username'      => (string)($l['alvo_nome'] ?? ''),
            'kind'          => (string)$l['kind'],
            'state'         => (string)($l['estado'] ?? ''),
            'reason'        => (string)($l['reason'] ?? ''),
            'expires_at'    => $l['expires_at'] === null ? null : (string)$l['expires_at'],
            'created_at'    => (string)$l['created_at'],
            // Quem aplicou pode ter sido um administrador (nome) ou uma chave da API
            // (admin_id nulo). A API devolve o que existe; não inventa autor.
            'applied_by'    => $l['admin_nome'] === null ? null : (string)$l['admin_nome'],
            'revoked_at'    => $l['revoked_at'] === null ? null : (string)$l['revoked_at'],
            'revoked_by'    => $l['revogador_nome'] === null ? null : (string)$l['revogador_nome'],
            'revoke_reason' => (string)($l['revoke_reason'] ?? ''),
        ];
    }

    /**
     * Progresso: temporada, missões e conquistas.
     *
     * Os totais vêm do catálogo inteiro, e não só do que o jogador já tocou: "12 de 40" é a
     * resposta útil; "12" sozinho não diz se é começo ou fim.
     *
     * @return array<string,mixed>|null
     */
    public static function progresso(int $id): ?array
    {
        $c = AdminPlayersRepo::conta($id);
        if ($c === null) {
            return null;
        }

        $m = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'done') AS concluidas,
                    SUM(status = 'active') AS ativas
               FROM player_missions WHERE user_id = :id",
            ['id' => $id]
        ) ?? [];

        $a = Database::one(
            'SELECT COUNT(*) AS total, SUM(completed_at IS NOT NULL) AS concluidas
               FROM player_achievements WHERE user_id = :id',
            ['id' => $id]
        ) ?? [];

        return [
            'user_id' => $id,
            'season'  => $c['pass_level'] === null ? null : [
                'number'      => (int)($c['season_number'] ?? 0),
                'pass_name'   => (string)($c['pass_name'] ?? ''),
                'pass_level'  => (int)$c['pass_level'],
                'pass_xp'     => (int)$c['pass_xp'],
                'xp_per_level'=> (int)($c['xp_per_level'] ?? 0),
                'week_done'   => (int)$c['week_done'],
                'week_goal'   => (int)$c['week_goal'],
                'streak_days' => (int)$c['streak_days'],
            ],
            'missions' => [
                'catalog'    => (int)Database::value('SELECT COUNT(*) FROM missions'),
                'started'    => (int)($m['total'] ?? 0),
                'completed'  => (int)($m['concluidas'] ?? 0),
                'active'     => (int)($m['ativas'] ?? 0),
            ],
            'achievements' => [
                'catalog'   => (int)Database::value('SELECT COUNT(*) FROM achievements'),
                'started'   => (int)($a['total'] ?? 0),
                'completed' => (int)($a['concluidas'] ?? 0),
            ],
        ];
    }
}
