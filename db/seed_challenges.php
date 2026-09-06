<?php declare(strict_types=1);

use App\Core\Database;

/**
 * Popula temporada + desafios (catálogo) e o estado do jogador demo, a partir de
 * app/Data/desafios.php — os mesmos números que a tela sempre mostrou.
 *
 * Prazos viram DATETIME reais para que a contagem regressiva da tela /desafios seja
 * calculada de verdade (agora -> ends_at) em vez de exibir um texto fixo.
 *
 * @param callable(string):array $data  leitor de app/Data/{slug}.php
 * @param array<string,int>      $itemId slug do item => items.id (para amarrar as recompensas do passe)
 */
function seed_challenges(callable $data, array $itemId): void
{
    $d = $data('desafios');
    if ($d === []) {
        echo "app/Data/desafios.php vazio — desafios não populados.\n";
        return;
    }

    $now = new DateTimeImmutable('now');
    $fmt = static fn(DateTimeImmutable $t): string => $t->format('Y-m-d H:i:s');

    // ---- temporada -------------------------------------------------------
    $featured = (array)($d['featured'] ?? []);
    $pass     = (array)($d['pass'] ?? []);
    $tag      = (string)($d['tag'] ?? 'TEMPORADA 04');
    $number   = (int)(preg_replace('/\D+/', '', $tag) ?: '4');

    $seasonId = Database::insert('seasons', [
        'slug'          => 'temporada-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
        'number'        => $number,
        'tag'           => $tag,
        'pass_name'     => (string)($pass['name'] ?? 'MATA FECHADA'),
        'pass_image'    => (string)($pass['image'] ?? 'ui/season-pass.jpg'),
        'xp_per_level'  => max(1, (int)($pass['xp_next'] ?? 10000)),
        'event_tag'     => (string)($featured['tag'] ?? 'EVENTO DE TEMPORADA'),
        'event_title'   => (string)($featured['title'] ?? ''),
        'event_desc'    => (string)($featured['desc'] ?? ''),
        'event_image'   => (string)($featured['image'] ?? ''),
        'event_ends_at' => $fmt($now->modify('+3 days 12 hours')),
        'starts_at'     => $fmt($now->modify('-42 days')),
        'ends_at'       => $fmt($now->modify('+18 days 4 hours')),
        'is_current'    => 1,
    ]);

    foreach ((array)($pass['tiers'] ?? []) as $i => $t) {
        $img  = (string)($t['image'] ?? '');
        $slug = basename($img, '.png');
        Database::insert('season_tiers', [
            'season_id'  => $seasonId,
            'level'      => (int)($t['level'] ?? 0),
            'name'       => (string)($t['name'] ?? ''),
            'rarity'     => ($t['rarity'] ?? null) !== null ? (string)$t['rarity'] : null,
            'image'      => $img,
            'item_id'    => $itemId[$slug] ?? null,
            'sort_order' => $i,
        ]);
    }

    // ---- catálogo de desafios (uma lista por aba) ------------------------
    $scopes = ['diarios' => 'daily', 'semanais' => 'weekly', 'temporada' => 'event'];
    $ids    = [];   // slug => ['id'=>int,'cur'=>int,'max'=>int]
    $total  = 0;
    foreach ($scopes as $scope => $key) {
        foreach ((array)($d[$key] ?? []) as $i => $c) {
            $slug = (string)($c['slug'] ?? ($scope . '-' . $i));
            $max  = max(1, (int)($c['max'] ?? 1));
            $cur  = min($max, max(0, (int)($c['cur'] ?? 0)));
            $ids[$slug] = [
                'id'  => Database::insert('challenges', [
                    'slug'        => $slug,
                    'scope'       => $scope,
                    'season_id'   => $scope === 'temporada' ? $seasonId : null,
                    'label'       => (string)($c['label'] ?? ''),
                    'description' => (string)($c['desc'] ?? ''),
                    'icon'        => (string)($c['icon'] ?? 'flag'),
                    'target'      => $max,
                    'xp'          => (int)($c['xp'] ?? 0),
                    'credits'     => (int)($c['credits'] ?? 0),
                    'highlight'   => !empty($c['hot']) ? 1 : 0,
                    'sort_order'  => $i,
                ]),
                'cur' => $cur,
                'max' => $max,
            ];
            $total++;
        }
    }

    foreach ((array)($d['bonus'] ?? []) as $scope => $b) {
        Database::insert('challenge_bonuses', [
            'scope'       => (string)$scope,
            'label'       => (string)($b['label'] ?? 'BÔNUS'),
            'title'       => (string)($b['title'] ?? ''),
            'description' => (string)($b['desc'] ?? ''),
            'image'       => (string)($b['image'] ?? ''),
            'xp'          => (int)($b['xp'] ?? 0),
            'credits'     => (int)($b['credits'] ?? 0),
        ]);
    }

    // ---- estado do jogador demo ------------------------------------------
    $uid = (int)Database::value('SELECT id FROM users ORDER BY id LIMIT 1');
    if ($uid <= 0) {
        echo "$total desafios de catálogo inseridos (sem jogador demo).\n";
        return;
    }

    foreach ($ids as $row) {
        Database::run(
            'INSERT INTO player_challenges (user_id, challenge_id, progress, completed_at)
             VALUES (:u, :c, :p, :d)
             ON DUPLICATE KEY UPDATE progress = VALUES(progress), completed_at = VALUES(completed_at)',
            [
                'u' => $uid,
                'c' => $row['id'],
                'p' => $row['cur'],
                'd' => $row['cur'] >= $row['max'] ? $fmt($now->modify('-1 day')) : null,
            ]
        );
    }

    $ring = (array)($d['ring'] ?? []);
    Database::run(
        'INSERT INTO player_season (user_id, season_id, pass_level, pass_xp, week_done, week_goal, week_xp, streak_days)
         VALUES (:u, :s, :lv, :xp, :wd, :wg, :wx, :st)
         ON DUPLICATE KEY UPDATE pass_level = VALUES(pass_level), pass_xp = VALUES(pass_xp),
           week_done = VALUES(week_done), week_goal = VALUES(week_goal),
           week_xp = VALUES(week_xp), streak_days = VALUES(streak_days)',
        [
            'u'  => $uid,
            's'  => $seasonId,
            'lv' => (int)($pass['level'] ?? 0),
            'xp' => (int)($pass['xp'] ?? 0),
            'wd' => (int)($ring['done'] ?? 0),
            'wg' => (int)($ring['total'] ?? 12),
            'wx' => (int)str_replace(['.', ' '], '', (string)($ring['stats'][0]['value'] ?? '0')),
            'st' => (int)preg_replace('/\D+/', '', (string)($ring['stats'][1]['value'] ?? '0')),
        ]
    );

    echo "$total desafios inseridos (temporada #$number, jogador demo $uid).\n";
}
