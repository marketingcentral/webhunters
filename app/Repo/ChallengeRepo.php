<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Desafios e temporada, conciliados por user_id.
 *
 * Catálogo: `seasons`, `season_tiers`, `challenges`, `challenge_bonuses`.
 * Estado do jogador: `player_challenges`, `player_season`, `player_bonus_claims`.
 *
 * Nada aqui devolve texto de prazo pronto: os prazos saem como SEGUNDOS restantes,
 * calculados a partir de DATETIME reais (fim do dia, próxima segunda, fim da temporada),
 * e a view/JS formatam. É o que faz a contagem regressiva da tela ser viva de verdade.
 */
final class ChallengeRepo
{
    /** Abas da tela, na ordem. */
    public const SCOPES = ['diarios', 'semanais', 'temporada'];

    /**
     * Desafios-META: o progresso deles NÃO é a coluna `player_challenges.progress`, e sim a
     * contagem real de desafios concluídos do próprio escopo (excluindo o meta). Ler a coluna
     * crua deixaria o card se contradizendo com a lista impressa logo acima dele na mesma tela
     * (ex.: "Conclua todos os desafios diários 1/3" com os três diários por concluir).
     */
    private const META_SLUGS = ['conclua-todos'];

    /** @var array<string,mixed> cache por request */
    private static array $cache = [];

    /** Temporada corrente (ou null se o catálogo ainda não foi populado). */
    public static function season(): ?array
    {
        return self::$cache['season'] ??= Database::one(
            'SELECT * FROM seasons WHERE is_current = 1 ORDER BY number DESC LIMIT 1'
        );
    }

    /** @return list<array<string,mixed>> recompensas do passe, em ordem de nível */
    public static function tiers(int $seasonId): array
    {
        return Database::all(
            'SELECT * FROM season_tiers WHERE season_id = :s ORDER BY level, sort_order',
            ['s' => $seasonId]
        );
    }

    /** @return array<string,array<string,mixed>> bônus indexado por escopo */
    public static function bonuses(): array
    {
        return self::$cache['bonuses'] ??= (static function (): array {
            $out = [];
            foreach (Database::all('SELECT * FROM challenge_bonuses') as $b) {
                $out[(string)$b['scope']] = $b;
            }
            return $out;
        })();
    }

    /**
     * Quadro completo do jogador: uma entrada por aba com itens, contagem e prazo.
     *
     * @return array<string,array{items:list<array<string,mixed>>,done:int,total:int,seconds:int}>
     */
    public static function board(int $userId): array
    {
        $deadlines = self::deadlines();
        $rows = Database::all(
            'SELECT c.*, pc.progress, pc.completed_at
               FROM challenges c
               LEFT JOIN player_challenges pc ON pc.challenge_id = c.id AND pc.user_id = :u
              ORDER BY FIELD(c.scope, :s1, :s2, :s3), c.sort_order, c.id',
            ['u' => $userId, 's1' => self::SCOPES[0], 's2' => self::SCOPES[1], 's3' => self::SCOPES[2]]
        );

        $board = [];
        foreach (self::SCOPES as $scope) {
            $board[$scope] = ['items' => [], 'done' => 0, 'total' => 0, 'seconds' => $deadlines[$scope]];
        }
        // 1ª passada: desafios normais, direto da coluna de progresso do jogador.
        // Os metas ficam de fora e são resolvidos depois, quando já se sabe quantos
        // desafios reais do escopo estão concluídos.
        $meta = [];
        foreach ($rows as $r) {
            $scope = (string)$r['scope'];
            if (!isset($board[$scope])) {
                continue;
            }
            $slug   = (string)$r['slug'];
            $target = max(1, (int)$r['target']);
            $isMeta = in_array($slug, self::META_SLUGS, true);
            $cur    = $isMeta ? 0 : min($target, max(0, (int)($r['progress'] ?? 0)));
            $done   = !$isMeta && $cur >= $target;

            $board[$scope]['items'][] = [
                'slug'    => $slug,
                'label'   => (string)$r['label'],
                'desc'    => (string)($r['description'] ?? ''),
                'icon'    => (string)($r['icon'] ?? 'flag'),
                'cur'     => $cur,
                'max'     => $target,
                'pct'     => (int)round($cur / $target * 100),
                'xp'      => (int)$r['xp'],
                'credits' => (int)$r['credits'],
                'hot'     => (int)$r['highlight'] === 1,
                'done'    => $done,
            ];
            $board[$scope]['total']++;
            if ($done) {
                $board[$scope]['done']++;
            }
            if ($isMeta) {
                $meta[] = [$scope, array_key_last($board[$scope]['items'])];
            }
        }

        // 2ª passada: cada meta recebe a contagem real de concluídos do seu escopo.
        foreach ($meta as [$scope, $idx]) {
            $item   = $board[$scope]['items'][$idx];
            $target = (int)$item['max'];
            $cur    = min($target, $board[$scope]['done']);   // metas nunca contam a si mesmos
            $done   = $cur >= $target;
            $board[$scope]['items'][$idx]['cur']  = $cur;
            $board[$scope]['items'][$idx]['pct']  = (int)round($cur / $target * 100);
            $board[$scope]['items'][$idx]['done'] = $done;
            if ($done) {
                $board[$scope]['done']++;
            }
        }
        return $board;
    }

    /**
     * Total de desafios concluídos do jogador no quadro inteiro, e o total do quadro.
     * É daqui que sai o anel "DESAFIOS CONCLUÍDOS" — derivado do MESMO quadro que a tela
     * imprime, nunca de uma coluna que pode envelhecer.
     *
     * @param array<string,array{done:int,total:int}> $board
     * @return array{0:int,1:int}
     */
    public static function boardTotals(array $board): array
    {
        $done = 0;
        $total = 0;
        foreach (self::SCOPES as $scope) {
            $done  += (int)($board[$scope]['done'] ?? 0);
            $total += (int)($board[$scope]['total'] ?? 0);
        }
        return [$done, $total];
    }

    /** Passe + estatísticas da semana do jogador (linha criada sob demanda). */
    public static function playerSeason(int $userId, int $seasonId): array
    {
        $row = Database::one(
            'SELECT * FROM player_season WHERE user_id = :u AND season_id = :s',
            ['u' => $userId, 's' => $seasonId]
        );
        return $row ?? [
            'user_id' => $userId, 'season_id' => $seasonId, 'pass_level' => 0, 'pass_xp' => 0,
            'week_done' => 0, 'week_goal' => 12, 'week_xp' => 0, 'streak_days' => 0,
        ];
    }

    /** Escopos já resgatados no período corrente: ['semanais' => true, ...] */
    public static function claimed(int $userId): array
    {
        $keys = self::periodKeys();
        $out  = [];
        foreach ($keys as $scope => $period) {
            $out[$scope] = (int)Database::value(
                'SELECT COUNT(*) FROM player_bonus_claims WHERE user_id = :u AND scope = :s AND period_key = :p',
                ['u' => $userId, 's' => $scope, 'p' => $period]
            ) > 0;
        }
        return $out;
    }

    /**
     * Resgata o bônus de uma aba: só quando todos os desafios do escopo estão concluídos
     * e o período ainda não foi resgatado. Credita XP + créditos ao jogador E move o
     * passe da temporada (pass_xp com transbordo de nível), o XP da semana e o anel —
     * a coluna direita da tela reage à única ação que ela oferece.
     *
     * @return array{ok:bool,error?:string,xp?:int,credits?:int}
     */
    public static function claimBonus(int $userId, string $scope): array
    {
        if ($userId <= 0 || !in_array($scope, self::SCOPES, true)) {
            return ['ok' => false, 'error' => 'Requisição inválida.'];
        }
        $bonus = self::bonuses()[$scope] ?? null;
        if ($bonus === null) {
            return ['ok' => false, 'error' => 'Bônus indisponível.'];
        }
        $full  = self::board($userId);
        $board = $full[$scope];
        if ($board['total'] === 0 || $board['done'] < $board['total']) {
            return ['ok' => false, 'error' => 'Conclua todos os desafios desta aba para resgatar o bônus.'];
        }
        $period = self::periodKeys()[$scope];
        $xp     = (int)$bonus['xp'];
        $credits = (int)$bonus['credits'];

        try {
            Database::run(
                'INSERT INTO player_bonus_claims (user_id, scope, period_key, xp, credits)
                 VALUES (:u, :s, :p, :x, :c)',
                ['u' => $userId, 's' => $scope, 'p' => $period, 'x' => $xp, 'c' => $credits]
            );
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'Este bônus já foi resgatado neste período.'];
            }
            throw $e;
        }

        PlayerRepo::adjustCurrency($userId, 'credits', $credits);
        PlayerRepo::addXp($userId, $xp);
        self::creditSeason($userId, $xp, $full);

        return ['ok' => true, 'xp' => $xp, 'credits' => $credits];
    }

    /**
     * Aplica o XP do bônus na linha do jogador NESTA temporada (nunca em todas as linhas
     * dele — daí o filtro por season_id): soma week_xp, soma pass_xp com transbordo de
     * nível pelo `seasons.xp_per_level` e reconcilia week_done com o quadro real.
     *
     * @param array<string,array{done:int,total:int}> $board quadro já calculado
     */
    private static function creditSeason(int $userId, int $xp, array $board): void
    {
        $season = self::season();
        if ($season === null) {
            return;
        }
        $seasonId = (int)$season['id'];
        $perLevel = max(1, (int)($season['xp_per_level'] ?? 10000));
        [$weekDone, $weekTotal] = self::boardTotals($board);

        $ps    = self::playerSeason($userId, $seasonId);
        $level = (int)$ps['pass_level'];
        $passXp = (int)$ps['pass_xp'] + $xp;
        while ($passXp >= $perLevel) {         // transbordo: 7.400 + 5.000 com 10.000/nível => nível +1, 2.400
            $passXp -= $perLevel;
            $level++;
        }

        Database::run(
            'INSERT INTO player_season (user_id, season_id, pass_level, pass_xp, week_done, week_goal, week_xp, streak_days)
             VALUES (:u, :s, :lv, :px, :wd, :wg, :wx, :st)
             ON DUPLICATE KEY UPDATE pass_level = VALUES(pass_level), pass_xp = VALUES(pass_xp),
               week_done = VALUES(week_done), week_goal = VALUES(week_goal), week_xp = VALUES(week_xp)',
            [
                'u'  => $userId,
                's'  => $seasonId,
                'lv' => $level,
                'px' => $passXp,
                'wd' => $weekDone,
                'wg' => max(1, $weekTotal, (int)$ps['week_goal']),
                'wx' => (int)$ps['week_xp'] + $xp,
                'st' => (int)$ps['streak_days'],
            ]
        );
    }

    /**
     * Segundos restantes de cada aba, a partir de prazos reais.
     * diarios = próxima meia-noite; semanais = próxima segunda 00:00; temporada = seasons.ends_at.
     *
     * @return array<string,int>
     */
    public static function deadlines(): array
    {
        return self::$cache['deadlines'] ??= (static function (): array {
            $now    = new DateTimeImmutable('now');
            $season = self::season();
            $end    = $season !== null
                ? new DateTimeImmutable((string)$season['ends_at'])
                : $now->modify('+18 days');
            return [
                'diarios'   => max(0, $now->modify('tomorrow midnight')->getTimestamp() - $now->getTimestamp()),
                'semanais'  => max(0, $now->modify('monday next week midnight')->getTimestamp() - $now->getTimestamp()),
                'temporada' => max(0, $end->getTimestamp() - $now->getTimestamp()),
            ];
        })();
    }

    /** Segundos até o fim do evento em destaque. */
    public static function eventSeconds(): int
    {
        $season = self::season();
        if ($season === null) {
            return 0;
        }
        return max(0, (new DateTimeImmutable((string)$season['event_ends_at']))->getTimestamp() - time());
    }

    /**
     * Chave do período corrente por escopo — impede resgatar o mesmo bônus duas vezes
     * no mesmo dia/semana/temporada.
     *
     * @return array<string,string>
     */
    public static function periodKeys(): array
    {
        $now    = new DateTimeImmutable('now');
        $season = self::season();
        return [
            'diarios'   => $now->format('Y-m-d'),
            'semanais'  => $now->format('o-\WW'),
            'temporada' => 'temporada-' . (string)($season['number'] ?? 0),
        ];
    }
}
