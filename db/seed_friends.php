<?php declare(strict_types=1);

/**
 * Popula `friends` e `squad_history` a partir de app/Data/amigos.php, para um jogador.
 *
 * Pode rodar sozinho (cria as tabelas se ainda não existirem e semeia TODOS os usuários):
 *   "C:/wamp64/bin/php/php8.3.28/php.exe" db/seed_friends.php
 * ou ser incluído por db/seed.php, que chama seed_friends($uid) depois de criar o jogador.
 */

use App\Core\Database;

if (!function_exists('seed_friends_tables')) {
    /** DDL idempotente (mesma definição de db/schema.sql). */
    function seed_friends_tables(): void
    {
        Database::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS friends (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  friend_user_id INT UNSIGNED NULL,
  name           VARCHAR(40)  NOT NULL,
  level          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  class          VARCHAR(24)  NULL,
  class_icon     VARCHAR(32)  NULL,
  avatar         VARCHAR(160) NULL,
  relation       VARCHAR(16)  NOT NULL DEFAULT 'amigo',
  presence       VARCHAR(16)  NOT NULL DEFAULT 'offline',
  activity       VARCHAR(120) NULL,
  party_slots    VARCHAR(12)  NULL,
  matches        INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen      DATETIME NULL,
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_friends_name (user_id, name),
  KEY idx_friends_user (user_id, relation),
  CONSTRAINT fk_friends_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_friends_other FOREIGN KEY (friend_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
        Database::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS squad_history (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  mate_name  VARCHAR(40)  NOT NULL,
  avatar     VARCHAR(160) NULL,
  map_name   VARCHAR(60)  NOT NULL,
  result     VARCHAR(16)  NOT NULL DEFAULT 'extraido',
  played_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_squad_user (user_id, played_at),
  CONSTRAINT fk_squad_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }
}

if (!function_exists('seed_friends')) {
    /** Recria a lista social do jogador $uid a partir de app/Data/amigos.php. */
    function seed_friends(int $uid): int
    {
        $data = (array)require dirname(__DIR__) . '/app/Data/amigos.php';

        Database::run('DELETE FROM friends WHERE user_id = :u', ['u' => $uid]);
        Database::run('DELETE FROM squad_history WHERE user_id = :u', ['u' => $uid]);

        // As datas usam o relógio do MySQL (DATE_SUB(NOW(), ...)), o mesmo que o
        // FriendsRepo consulta com TIMESTAMPDIFF — sem depender do fuso do PHP.
        $sqlFriend =
            'INSERT INTO friends (user_id,name,level,class,class_icon,avatar,relation,presence,
                                  activity,party_slots,matches,last_seen,created_at,sort_order)
             VALUES (:u,:n,:l,:c,:ci,:a,:r,:pr,:act,:party,:m,
                     DATE_SUB(NOW(), INTERVAL :seen MINUTE),
                     DATE_SUB(NOW(), INTERVAL :age MINUTE), :so)';

        $n = 0;
        foreach ((array)($data['items'] ?? []) as $i => $f) {
            $seen = (int)($f['seen_min'] ?? 0);
            Database::run($sqlFriend, [
                'u' => $uid, 'n' => (string)$f['name'], 'l' => (int)$f['level'],
                'c' => (string)($f['class'] ?? ''), 'ci' => (string)($f['class_icon'] ?? 'class-assault'),
                'a' => (string)($f['avatar'] ?? 'portraits/op-assault.jpg'),
                'r' => 'amigo', 'pr' => (string)($f['status'] ?? 'offline'),
                'act' => (string)($f['activity'] ?? ''), 'party' => $f['party'] ?? null,
                'm' => (int)($f['matches'] ?? 0), 'seen' => $seen, 'age' => $seen, 'so' => $i,
            ]);
            $n++;
        }

        foreach ((array)($data['pending'] ?? []) as $i => $p) {
            $in   = ($p['direction'] ?? 'in') === 'in';
            $seen = (int)($p['seen_min'] ?? 0);
            Database::run($sqlFriend, [
                'u' => $uid, 'n' => (string)$p['name'], 'l' => (int)$p['level'],
                'c' => (string)($p['class'] ?? ''), 'ci' => (string)($p['class_icon'] ?? 'class-assault'),
                'a' => (string)($p['avatar'] ?? 'portraits/op-assault.jpg'),
                'r' => $in ? 'pedido_recebido' : 'pedido_enviado', 'pr' => 'offline',
                'act' => (string)($p['activity'] ?? ''), 'party' => null,
                'm' => (int)($p['matches'] ?? 0), 'seen' => $seen, 'age' => $seen, 'so' => 500 + $i,
            ]);
            $n++;
        }

        $s = 0;
        foreach ((array)($data['squad']['items'] ?? []) as $i => $q) {
            Database::run(
                'INSERT INTO squad_history (user_id,mate_name,avatar,map_name,result,played_at,sort_order)
                 VALUES (:u,:n,:a,:map,:res, DATE_SUB(NOW(), INTERVAL :min MINUTE), :so)',
                [
                    'u' => $uid, 'n' => (string)$q['name'],
                    'a' => (string)($q['avatar'] ?? 'portraits/op-assault.jpg'),
                    'map' => (string)$q['map'], 'res' => !empty($q['ok']) ? 'extraido' : 'abatido',
                    'min' => (int)($q['played_min'] ?? 0), 'so' => $i,
                ]
            );
            $s++;
        }

        return $n + $s;
    }
}

// -------- execução direta: semeia todos os usuários existentes ---------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require __DIR__ . '/../app/Core/Config.php';
    require __DIR__ . '/../app/Core/Database.php';
    App\Core\Config::boot(dirname(__DIR__));

    seed_friends_tables();
    $users = Database::all('SELECT id, username FROM users ORDER BY id');
    foreach ($users as $u) {
        $n = seed_friends((int)$u['id']);
        echo "usuario {$u['username']} (#{$u['id']}): $n linhas sociais\n";
    }
    echo count($users) . " usuario(s) semeado(s).\n";
}
