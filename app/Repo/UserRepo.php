<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Contas de usuário e criação do jogador associado. Um novo jogador nasce nível 0 / 0 XP,
 * mas com dinheiro para comprar na loja e um kit inicial mínimo no stash.
 */
final class UserRepo
{
    /**
     * Usuário por username OU e-mail (case-insensitive pela collation da tabela).
     *
     * O PDO roda com ATTR_EMULATE_PREPARES = false: o MySQL não aceita o MESMO placeholder
     * nomeado duas vezes na mesma consulta (SQLSTATE[HY093] Invalid parameter number).
     * Por isso o valor é ligado a dois placeholders distintos.
     *
     * @return array<string,mixed>|null
     */
    public static function findByLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        return Database::one(
            'SELECT * FROM users WHERE username = :u OR email = :e LIMIT 1',
            ['u' => $login, 'e' => $login]
        );
    }

    public static function usernameTaken(string $username): bool
    {
        return (int)Database::value('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $username]) > 0;
    }

    public static function emailTaken(string $email): bool
    {
        return (int)Database::value('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $email]) > 0;
    }

    public static function verify(array $user, string $password): bool
    {
        return password_verify($password, (string)$user['password_hash']);
    }

    /** Cria usuário + jogador (nível 0, com dinheiro) + kit inicial. @return int user id */
    public static function create(string $username, string $email, string $password): int
    {
        return Database::transaction(static function () use ($username, $email, $password): int {
            $uid = Database::insert('users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $health = [
                'saude' => 100, 'energia' => 100, 'hidratacao' => 100, 'fome' => 100, 'temperatura' => 36.6,
                'limbs' => ['cabeca' => 35, 'torax' => 85, 'estomago' => 70, 'braco_esq' => 60, 'braco_dir' => 60, 'perna_esq' => 65, 'perna_dir' => 65],
                'limbs_max' => ['cabeca' => 35, 'torax' => 85, 'estomago' => 70, 'braco_esq' => 60, 'braco_dir' => 60, 'perna_esq' => 65, 'perna_dir' => 65],
            ];
            Database::insert('players', [
                'user_id'      => $uid,
                'display_name' => $username,
                'level'        => 0,
                'xp'           => 0,
                'xp_next'      => 1000,
                'credits'      => 150000,   // dinheiro inicial para a loja
                'cash'         => 2000,
                'points'       => 1000,
                'clan'         => 'Resistência',
                'class'        => 'ASSALTO',
                'class_icon'   => 'class-assault',
                'avatar'       => 'portraits/op-assault.jpg',
                'player_rank'  => 'RECRUTA',
                'season_level' => 0,
                'skill_points' => 0,
                'health'       => json_encode($health, JSON_UNESCAPED_UNICODE),
                'survival'     => json_encode(['weight_max' => 60], JSON_UNESCAPED_UNICODE),
            ]);

            // progresso zerado de missões/conquistas (para conciliar por id desde o começo)
            foreach (Database::all('SELECT id, locked FROM missions') as $m) {
                Database::insert('player_missions', ['user_id' => $uid, 'mission_id' => $m['id'],
                    'status' => $m['locked'] ? 'bloqueada' : 'disponivel', 'progress' => 0]);
            }
            foreach (Database::all('SELECT id FROM achievements') as $a) {
                Database::insert('player_achievements', ['user_id' => $uid, 'achievement_id' => $a['id'], 'progress' => 0]);
            }

            // kit inicial no stash
            self::giveStarterKit($uid);
            return $uid;
        });
    }

    private static function giveStarterKit(int $userId): void
    {
        // a Glock já vinha no kit, mas sem carregador não dá para atirar com ela
        $slugs = ['glock17', 'glock-mag', 'knife-bayonet', 'ak-mag', 'medkit', 'bandage', 'water-bottle'];
        $x = 0; $y = 0;
        foreach ($slugs as $slug) {
            $item = Database::one('SELECT id, grid_w, grid_h, durability_max, mag_capacity FROM items WHERE slug = :s', ['s' => $slug]);
            if ($item === null) { continue; }
            Database::insert('player_items', [
                'user_id'        => $userId,
                'item_id'        => (int)$item['id'],
                'container'      => 'stash',
                'pos_x'          => $x,
                'pos_y'          => $y,
                'quantity'       => 1,
                'durability_cur' => $item['durability_max'] !== null ? (int)$item['durability_max'] : null,
                'ammo_cur'       => (int)($item['mag_capacity'] ?? 0) ?: null,
                'acquired_via'   => 'inicial',
            ]);
            $x += (int)$item['grid_w'];
            if ($x >= InventoryRepo::STASH_COLS - 1) { $x = 0; $y += 2; }
        }
    }
}
