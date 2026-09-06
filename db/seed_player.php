<?php declare(strict_types=1);

use App\Core\Database;

/**
 * Cria o usuário demo HUNTER_07 (senha: caceEextraia2024), nível 0 mas com dinheiro para
 * comprar na loja, e um stash inicial de itens "adquiridos em partida" — os mesmos que
 * aparecem na tela de inventário de referência. O empacotamento na grade do stash é
 * calculado por um bin-packing simples (primeiro encaixe), respeitando grid_w/grid_h.
 *
 * @param array<string,int> $itemId  slug => item.id
 * @param array<string,array{id:int,status:string,progress:int}> $missionId
 * @param array<string,array{id:int,progress:int,done:bool,date:?string}> $achId
 */
function seed_demo_player(array $itemId, array $missionId, array $achId, callable $j): void
{
    $uid = Database::insert('users', [
        'username'      => 'HUNTER_07',
        'email'         => 'hunter07@extracthunters.game',
        'password_hash' => password_hash('caceEextraia2024', PASSWORD_DEFAULT),
    ]);

    $health = [
        'saude' => 100, 'energia' => 78, 'hidratacao' => 56, 'fome' => 62, 'temperatura' => 36.6,
        'limbs' => ['cabeca' => 35, 'torax' => 85, 'estomago' => 70, 'braco_esq' => 60, 'braco_dir' => 60, 'perna_esq' => 65, 'perna_dir' => 65],
        'limbs_max' => ['cabeca' => 35, 'torax' => 85, 'estomago' => 70, 'braco_esq' => 60, 'braco_dir' => 60, 'perna_esq' => 65, 'perna_dir' => 65],
    ];

    Database::insert('players', [
        'user_id'      => $uid,
        'display_name' => 'HUNTER_07',
        'level'        => 0,
        'xp'           => 0,
        'xp_next'      => 1000,
        'credits'      => 180000,   // dinheiro inicial para comprar na loja
        'cash'         => 4200,
        'points'       => 2500,
        'clan'         => 'Resistência',
        'class'        => 'ASSALTO',
        'class_icon'   => 'class-assault',
        'avatar'       => 'portraits/op-assault.jpg',
        'player_rank'  => 'RECRUTA',
        'season_level' => 0,
        'skill_points' => 0,
        'health'       => $j($health),
        'survival'     => $j(['weight_max' => 60]),
    ]);

    // --- inventário inicial (stash) ---
    // Itens "adquiridos em partida". Vão para o stash; o inventário Tarkov permite arrastar
    // para os slots de equipamento e containers.
    // [slug, quantidade] — empilháveis entram como UMA instância com quantidade
    $starter = [
        ['ak103',1], ['m4a1',1], ['glock17',1], ['knife-bayonet',1],
        ['att-reddot',1], ['att-suppressor',1], ['att-grip',1],
        ['headset-comtac',1], ['glasses-aviator',1], ['helmet-fast',1], ['gasmask-gp7',1], ['vest-83',1], ['tactical-rig',1], ['backpack-45l',1], ['armband-usec',1],
        ['ak-mag',1], ['ak-mag',1], ['ak-mag',1], ['m4-mag',1], ['m4-mag',1],
        ['medkit',1], ['bandage',3], ['analgesic',3], ['water-bottle',1], ['canned-food',3],
        ['m67-grenade',1], ['flashbang',2], ['radio',1], ['compass',1], ['multitool',1],
    ];

    // stash: mesma grade do InventoryRepo (STASH_COLS x STASH_ROWS)
    $COLS = \App\Repo\InventoryRepo::STASH_COLS; $ROWS = \App\Repo\InventoryRepo::STASH_ROWS;
    $grid = array_fill(0, $ROWS, array_fill(0, $COLS, false));
    $place = static function (int $w, int $h) use (&$grid, $COLS, $ROWS): ?array {
        for ($y = 0; $y <= $ROWS - $h; $y++) {
            for ($x = 0; $x <= $COLS - $w; $x++) {
                $free = true;
                for ($dy = 0; $dy < $h && $free; $dy++) {
                    for ($dx = 0; $dx < $w; $dx++) {
                        if ($grid[$y + $dy][$x + $dx]) { $free = false; break; }
                    }
                }
                if ($free) {
                    for ($dy = 0; $dy < $h; $dy++) { for ($dx = 0; $dx < $w; $dx++) { $grid[$y + $dy][$x + $dx] = true; } }
                    return [$x, $y];
                }
            }
        }
        return null;
    };

    // Equipado de saída: define ANTES do empacotamento para que estes itens não ocupem
    // (e depois liberem) espaço na grade do baú.
    $equipar = [
        'earpiece' => 'headset-comtac', 'helmet' => 'helmet-fast', 'mask' => 'gasmask-gp7',
        'armor' => 'vest-83', 'glasses' => 'glasses-aviator', 'armband' => 'armband-usec',
        'rig' => 'tactical-rig', 'backpack' => 'backpack-45l', 'primary' => 'ak103',
        'secondary' => 'm4a1', 'pistol' => 'glock17', 'knife' => 'knife-bayonet',
    ];
    $slotDoSlug = array_flip($equipar);
    $nosBolsos  = ['bandage' => 0, 'analgesic' => 1];   // slug => coluna do bolso

    // Empacota do MAIOR para o menor: em primeiro-encaixe na ordem original a mochila 5x5
    // chegava por último e não cabia mais — o item sumia do seed sem aviso.
    usort($starter, static function (array $a, array $b) use ($itemId): int {
        $area = static function (string $slug) use ($itemId): int {
            if (!isset($itemId[$slug])) { return 0; }
            $r = Database::one('SELECT grid_w, grid_h FROM items WHERE id = :id', ['id' => $itemId[$slug]]);
            return $r ? (int)$r['grid_w'] * (int)$r['grid_h'] : 0;
        };
        return $area($b[0]) <=> $area($a[0]);
    });

    $naoCoube = [];
    foreach ($starter as [$slug, $qty]) {
        if (!isset($itemId[$slug])) { continue; }
        $it = Database::one('SELECT grid_w, grid_h, durability_max, mag_capacity, ammo_caliber, category FROM items WHERE id = :id', ['id' => $itemId[$slug]]);
        [$w, $h] = [(int)$it['grid_w'], (int)$it['grid_h']];
        // destino: slot de equipamento, bolso ou grade do baú
        $destino = ['container' => 'stash', 'slot_key' => null, 'x' => null, 'y' => null];
        if (isset($slotDoSlug[$slug])) {
            $destino = ['container' => 'equip', 'slot_key' => $slotDoSlug[$slug], 'x' => null, 'y' => null];
            unset($slotDoSlug[$slug]);            // só a primeira instância vai para o slot
        } elseif (isset($nosBolsos[$slug])) {
            $destino = ['container' => 'pockets', 'slot_key' => null, 'x' => $nosBolsos[$slug], 'y' => 0];
            unset($nosBolsos[$slug]);
        } else {
            $pos = $place($w, $h);
            if ($pos === null) { $naoCoube[] = $slug; continue; }
            $destino['x'] = $pos[0]; $destino['y'] = $pos[1];
        }
        // munição carregada: carregadores começam cheios
        $ammoCur = null; $ammoItem = null;
        if ((int)($it['mag_capacity'] ?? 0) > 0) {
            $ammoCur = (int)$it['mag_capacity'];
            $ammoItem = $itemId['ammo-box'] ?? null;
        }
        Database::insert('player_items', [
            'user_id'        => $uid,
            'item_id'        => $itemId[$slug],
            'container'      => $destino['container'],
            'slot_key'       => $destino['slot_key'],
            'pos_x'          => $destino['x'],
            'pos_y'          => $destino['y'],
            'rotated'        => 0,
            'quantity'       => $qty,
            'durability_cur' => $it['durability_max'] !== null ? (int)$it['durability_max'] : null,
            'ammo_cur'       => $ammoCur,
            'ammo_item_id'   => $ammoItem,
            'acquired_via'   => 'inicial',
        ]);
    }

    // --- progresso de missões / conquistas do demo ---
    foreach ($missionId as $m) {
        Database::insert('player_missions', ['user_id' => $uid, 'mission_id' => $m['id'], 'status' => $m['status'], 'progress' => $m['progress']]);
    }
    foreach ($achId as $a) {
        Database::insert('player_achievements', ['user_id' => $uid, 'achievement_id' => $a['id'], 'progress' => $a['progress'], 'completed_at' => $a['done'] ? date('Y-m-d H:i:s') : null]);
    }
    // --- árvore de habilidades do demo (player_skills + pontos disponíveis) ---
    // A tela /habilidades lê SÓ do banco: sem estas linhas a árvore ficaria toda bloqueada.
    seed_demo_skills($uid);
    // algumas partidas registradas
    for ($k = 0; $k < 3; $k++) {
        Database::insert('player_matches', ['user_id' => $uid, 'map_id' => null, 'result' => ['extraido', 'extraido', 'abatido'][$k], 'kills' => [4, 7, 2][$k], 'xp' => [1200, 2100, 400][$k], 'earnings' => [3400, 5600, 0][$k]]);
    }

    echo "Usuário demo HUNTER_07 criado (id=$uid), nível 0, R$ 180.000 créditos, stash com " . count($starter) . " itens.\n";
}

/**
 * Progresso inicial da árvore de habilidades do jogador demo. Lê o estado de referência de
 * app/Data/habilidades.php (unlocked = nível máximo, progress = nível parcial) e grava em
 * player_skills; concede também pontos de habilidade para que APRENDER seja utilizável.
 */
function seed_demo_skills(int $uid, int $points = 12): void
{
    $file = dirname(__DIR__) . '/app/Data/habilidades.php';
    if (!is_file($file)) { return; }
    $hab = (array)require $file;

    $idBySlug = [];
    foreach (Database::all('SELECT id, slug, max_level FROM skills') as $s) {
        $idBySlug[(string)$s['slug']] = ['id' => (int)$s['id'], 'max' => (int)$s['max_level']];
    }

    $n = 0;
    foreach (($hab['trees'] ?? []) as $rows) {
        foreach ($rows as $row) {
            foreach ($row as $node) {
                $slug = (string)($node['slug'] ?? '');
                if ($slug === '' || !isset($idBySlug[$slug])) { continue; }
                $state = (string)($node['state'] ?? 'locked');
                $level = $state === 'unlocked'
                    ? $idBySlug[$slug]['max']
                    : ($state === 'progress' ? (int)($node['cur'] ?? 0) : 0);
                if ($level <= 0) { continue; }
                Database::insert('player_skills', [
                    'user_id' => $uid, 'skill_id' => $idBySlug[$slug]['id'], 'level' => $level,
                ]);
                $n++;
            }
        }
    }
    Database::run('UPDATE players SET skill_points = :p WHERE user_id = :u', ['p' => $points, 'u' => $uid]);
    echo "$n habilidades do jogador demo + $points pontos disponíveis.\n";
}
