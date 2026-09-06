<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Estado do jogador conciliado por user_id. Converte a linha `players` para o formato que
 * as views já esperam (currencies aninhado, xp_next, etc.) e concentra as mutações de
 * economia/progresso (creditar, debitar, ganhar XP).
 */
final class PlayerRepo
{
    /** @return array<string,mixed>|null formato de view */
    public static function forUser(int $userId): ?array
    {
        $p = Database::one(
            'SELECT pl.*, u.username, u.email FROM players pl JOIN users u ON u.id = pl.user_id WHERE pl.user_id = :id',
            ['id' => $userId]
        );
        if ($p === null) {
            return null;
        }
        $health   = json_decode((string)($p['health'] ?? 'null'), true) ?: [];
        $survival = json_decode((string)($p['survival'] ?? 'null'), true) ?: [];

        $stats = Database::one(
            'SELECT COUNT(*) matches,
                    COALESCE(SUM(kills),0) kills,
                    COALESCE(SUM(result="extraido"),0) extractions,
                    COALESCE(SUM(result="abatido"),0) deaths
             FROM player_matches WHERE user_id = :id',
            ['id' => $userId]
        ) ?? [];

        return [
            'id'             => (int)$p['user_id'],
            'username'       => (string)$p['username'],
            'name'           => (string)$p['display_name'],
            'level'          => (int)$p['level'],
            'xp'             => (int)$p['xp'],
            'xp_next'        => (int)$p['xp_next'],
            'avatar'         => (string)($p['avatar'] ?? 'portraits/op-assault.jpg'),
            'clan'           => (string)($p['clan'] ?? ''),
            'class'          => (string)($p['class'] ?? ''),
            'class_icon'     => (string)($p['class_icon'] ?? 'class-assault'),
            'rank'           => (string)($p['player_rank'] ?? ''),
            'season_level'   => (int)$p['season_level'],
            'skill_points'   => (int)$p['skill_points'],
            'currencies'     => [
                'credits' => (int)$p['credits'],
                'cash'    => (int)$p['cash'],
                'points'  => (int)$p['points'],
            ],
            'health'         => $health,
            'survival'       => $survival,
            'match_stats'    => [
                'matches'     => (int)($stats['matches'] ?? 0),
                'kills'       => (int)($stats['kills'] ?? 0),
                'extractions' => (int)($stats['extractions'] ?? 0),
                'deaths'      => (int)($stats['deaths'] ?? 0),
            ],
            'friends_online' => 8,
            // conversas não lidas reais deste jogador (o envelope do topbar acende com isso)
            'unread_mail'    => self::unreadMail($userId),
            'notifications'  => 0,
            'status'         => 'online',
        ];
    }

    /** Conversas não lidas do jogador; 0 se a caixa de mensagens ainda não estiver instalada. */
    private static function unreadMail(int $userId): int
    {
        try {
            return (int)Database::value('SELECT COUNT(*) FROM message_threads WHERE user_id = :id AND unread = 1', ['id' => $userId]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Saldo de uma moeda. */
    public static function balance(int $userId, string $currency): int
    {
        $col = self::currencyColumn($currency);
        return (int)Database::value("SELECT `$col` FROM players WHERE user_id = :id", ['id' => $userId]);
    }

    /** Credita (delta>0) ou debita (delta<0) uma moeda; devolve false se faltar saldo. */
    public static function adjustCurrency(int $userId, string $currency, int $delta): bool
    {
        $col = self::currencyColumn($currency);
        if ($delta < 0) {
            $stmt = Database::run(
                "UPDATE players SET `$col` = `$col` + :d WHERE user_id = :id AND `$col` >= :need",
                ['d' => $delta, 'id' => $userId, 'need' => -$delta]
            );
            return $stmt->rowCount() > 0;
        }
        Database::run("UPDATE players SET `$col` = `$col` + :d WHERE user_id = :id", ['d' => $delta, 'id' => $userId]);
        return true;
    }

    public static function addXp(int $userId, int $amount): void
    {
        $p = Database::one('SELECT level, xp, xp_next FROM players WHERE user_id = :id', ['id' => $userId]);
        if ($p === null) { return; }
        $level = (int)$p['level']; $xp = (int)$p['xp'] + $amount; $next = (int)$p['xp_next'] ?: 1000;
        while ($xp >= $next) { $xp -= $next; $level++; $next = 1000 + $level * 500; }
        Database::run('UPDATE players SET level = :l, xp = :x, xp_next = :n WHERE user_id = :id',
            ['l' => $level, 'x' => $xp, 'n' => $next, 'id' => $userId]);
    }

    private static function currencyColumn(string $currency): string
    {
        return match ($currency) {
            'cash'   => 'cash',
            'points' => 'points',
            default  => 'credits',
        };
    }
}
