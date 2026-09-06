<?php declare(strict_types=1);

/**
 * Popula o banco a partir de db/catalog.php e dos app/Data/*.php (os mesmos dados que já
 * aparecem nas telas). Cria também um usuário demo (HUNTER_07) nível 0 com dinheiro e um
 * stash inicial. Idempotente: TRUNCATE + reinsere. Uso:
 *   "C:/wamp64/bin/php/php8.3.28/php.exe" db/seed.php
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/Core/Config.php';
require __DIR__ . '/../app/Core/Database.php';
require __DIR__ . '/../app/Repo/CatalogRepo.php';
require __DIR__ . '/../app/Repo/PlayerRepo.php';
require __DIR__ . '/../app/Repo/InventoryRepo.php';

use App\Core\Config;
use App\Core\Database;

Config::boot(dirname(__DIR__));
$ROOT = dirname(__DIR__);
$pdo  = Database::pdo();

/** @return array<string,mixed> */
$data = static function (string $slug) use ($ROOT): array {
    $f = $ROOT . '/app/Data/' . $slug . '.php';
    return is_file($f) ? (array)require $f : [];
};
$j = static fn(mixed $v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);

// seed TRUNCA `users` e `players`: mesma trava do migrate (ver db/guard.php)
require __DIR__ . '/guard.php';
guard_dados_do_jogador($pdo, $argv, 'db/seed.php');

echo "Limpando tabelas...\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['squad_history','friends','player_bonus_claims','player_challenges','player_season','challenge_bonuses','challenges','season_tiers','seasons','message_entries','message_threads','system_notices','maintenance_windows','player_matches','player_achievements','player_missions','player_skills','player_purchases','player_items','players','users','shop_offers','achievement_rewards','achievements','mission_rewards','missions','map_markers','maps','news','skills','equipment_slots','items'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// ============================================================================
// ITENS (catálogo curado + attachments + munição)
// ============================================================================
$catalog = require __DIR__ . '/catalog.php';
$itemId  = [];   // slug => id

$defaultDesc = static fn(array $c): ?string => $c['desc'] ?? null;
foreach ($catalog as $slug => $c) {
    $itemId[$slug] = Database::insert('items', [
        'slug'           => $slug,
        'name'           => $c['name'],
        'short_name'     => $c['short'] ?? null,
        'category'       => $c['cat'],
        'subtype'        => $c['sub'] ?? null,
        'slot_type'      => $c['slot'] ?? null,
        'grid_w'         => $c['w'] ?? 1,
        'grid_h'         => $c['h'] ?? 1,
        'weight_kg'      => $c['kg'] ?? 0,
        'rarity'         => $c['rarity'] ?? 'comum',
        'image'          => 'items/' . $slug . '.png',
        'description'    => $defaultDesc($c),
        'base_price'     => $c['price'] ?? 0,
        'currency'       => $c['cur'] ?? 'credits',
        'stackable'      => isset($c['stack']) ? 1 : 0,
        'max_stack'      => $c['stack'] ?? 1,
        'durability_max' => $c['dur'] ?? null,
        'grid_cols'      => $c['cols'] ?? null,
        'grid_rows'      => $c['rows'] ?? null,
        'ammo_caliber'   => $c['cal'] ?? null,
        'mag_capacity'   => $c['mag'] ?? null,
        'attach_slots'   => $j($c['attach'] ?? null),
        'fits_calibers'  => isset($c['cal']) && in_array($c['cat'], ['weapon', 'pistol'], true) ? $j([$c['cal']]) : null,
    ]);
}

// attachments (mira, silenciador, empunhaduras, tático) — imagens geradas por db/gen_attachments.mjs
$attach = [
    ['slug' => 'att-reddot',    'name' => 'Red Dot EKP-8', 'short' => 'RED DOT',   'sub' => 'sight',      'desc' => 'Mira de ponto vermelho. Aquisição rápida de alvos.', 'price' => 6400,  'w' => 1, 'h' => 1, 'kg' => 0.2],
    ['slug' => 'att-scope',     'name' => 'Luneta 4x PSO', 'short' => 'LUNETA',    'sub' => 'sight',      'desc' => 'Luneta 4x para engajamentos de média distância.',   'price' => 9800,  'w' => 2, 'h' => 1, 'kg' => 0.5],
    ['slug' => 'att-suppressor','name' => 'Silenciador PBS-4', 'short' => 'SILENC.', 'sub' => 'suppressor', 'desc' => 'Reduz o ruído e o clarão do disparo.',              'price' => 12200, 'w' => 2, 'h' => 1, 'kg' => 0.4],
    ['slug' => 'att-grip',      'name' => 'Empunhadura RK-2', 'short' => 'GRIP',    'sub' => 'grip',       'desc' => 'Empunhadura ergonômica. Reduz o recuo.',            'price' => 3600,  'w' => 1, 'h' => 1, 'kg' => 0.1],
    ['slug' => 'att-foregrip',  'name' => 'Grip Frontal Tático', 'short' => 'FOREGRIP', 'sub' => 'foregrip', 'desc' => 'Grip frontal. Melhora a estabilidade.',           'price' => 3200,  'w' => 1, 'h' => 1, 'kg' => 0.1],
    ['slug' => 'att-flashlight','name' => 'Lanterna Tática X400', 'short' => 'LANTERNA', 'sub' => 'tactical', 'desc' => 'Lanterna tática com laser integrado.',            'price' => 4100,  'w' => 1, 'h' => 1, 'kg' => 0.15],
];
foreach ($attach as $a) {
    $itemId[$a['slug']] = Database::insert('items', [
        'slug' => $a['slug'], 'name' => $a['name'], 'short_name' => $a['short'], 'category' => 'attachment',
        'subtype' => $a['sub'], 'grid_w' => $a['w'], 'grid_h' => $a['h'], 'weight_kg' => $a['kg'],
        'image' => 'items/' . $a['slug'] . '.png', 'description' => $a['desc'], 'base_price' => $a['price'],
        'currency' => 'credits', 'rarity' => 'incomum',
    ]);
}
echo count($itemId) . " itens inseridos.\n";

// ============================================================================
// SLOTS DE EQUIPAMENTO
// ============================================================================
$slots = [
    // ordem e nomes do layout GEAR (Tarkov): fone/cabeça/rosto, braçadeira/colete/óculos,
    // bandoleira+coldre, costas+bainha, e os contêineres (rig/mochila) na coluna do meio
    ['earpiece', 'FONE',          ['earpiece'], 1],
    ['helmet',   'CAPACETE',      ['helmet'],   2],
    ['mask',     'MÁSCARA',       ['mask'],     3],
    ['armband',  'BRAÇADEIRA',    ['armband'],  4],
    ['armor',    'COLETE',        ['armor', 'clothing'], 5],
    ['glasses',  'ÓCULOS',        ['glasses'],  6],
    ['primary',  'NA BANDOLEIRA', ['weapon'],   7],
    ['pistol',   'COLDRE',        ['pistol'],   8],
    ['secondary','NAS COSTAS',    ['weapon'],   9],
    ['knife',    'BAINHA',        ['knife'],    10],
    ['rig',      'COLETE TÁTICO', ['rig'],      11],
    ['backpack', 'MOCHILA',       ['backpack'], 12],
];
foreach ($slots as [$k, $label, $accepts, $ord]) {
    Database::insert('equipment_slots', ['slot_key' => $k, 'label' => $label, 'accepts' => $j($accepts), 'sort_order' => $ord]);
}

// ============================================================================
// MAPAS (regiões do mundo + mapas de partida) + marcadores
// ============================================================================
$mapId = [];
$mapa  = $data('mapa');
// intervalo de nível a partir do rótulo "1-10" / "40+" (colunas), preservando o rótulo em meta.
$lvlRange = static function (?string $s): array {
    $s = trim((string)$s);
    if ($s === '') { return [null, null]; }
    if (preg_match('/^(\d+)\s*-\s*(\d+)/', $s, $m)) { return [(int)$m[1], (int)$m[2]]; }
    if (preg_match('/^(\d+)\s*\+?/', $s, $m)) { return [(int)$m[1], null]; }
    return [null, null];
};
foreach (($mapa['regions'] ?? []) as $i => $r) {
    $key = (string)($r['slug'] ?? $i);
    [$lmin, $lmax] = $lvlRange($r['level'] ?? null);
    $mapId['region:' . $key] = Database::insert('maps', [
        'slug' => 'regiao-' . $key, 'name' => $r['name'] ?? $r['label'] ?? 'Região', 'kind' => 'region',
        'level_min' => $lmin, 'level_max' => $lmax,
        'locked' => !empty($r['locked']) ? 1 : 0, 'image' => isset($r['image']) ? preg_replace('#^assets/img/#', '', (string)$r['image']) : 'regions/region-floresta-verde.jpg',
        'description' => $r['desc'] ?? null, 'sort_order' => $i,
        // meta: tudo o que a HUD do mapa consome além das colunas (rótulo de nível, ícone, blocos da coluna direita).
        'meta' => $j([
            'key'         => $key,
            'icon'        => $r['icon'] ?? 'pine',
            'level'       => (string)($r['level'] ?? ''),
            'info'        => array_values($r['info'] ?? []),
            'extractions' => array_values($r['extractions'] ?? []),
            'event'       => $r['event'] ?? null,
        ]),
    ]);
}
// marcadores por região (cada conjunto ancorado no map_id da sua região). x/y guardados em 0..1.
foreach (($mapa['markers'] ?? []) as $regionSlug => $list) {
    $mid = $mapId['region:' . $regionSlug] ?? null;
    if ($mid === null || !is_array($list)) { continue; }
    foreach ($list as $m) {
        Database::insert('map_markers', [
            'map_id' => $mid,
            'kind'   => $m['glyph'] ?? $m['kind'] ?? 'loot',
            'label'  => $m['name'] ?? null,
            'x'      => (float)($m['x'] ?? 0) / 100,
            'y'      => (float)($m['y'] ?? 0) / 100,
            'meta'   => $j([
                'id'     => $m['id'] ?? null,
                'glyph'  => $m['glyph'] ?? null,
                'color'  => $m['color'] ?? 'white',
                'name'   => $m['name'] ?? null,
                'label'  => $m['label'] ?? false,
                'narrow' => !empty($m['narrow']),
                'pulse'  => !empty($m['pulse']),
                'risk'   => $m['risk'] ?? null,
                'danger' => ($m['color'] ?? '') === 'red',
            ]),
        ]);
    }
}
// mapas de partida
foreach (($data('mapas')['items'] ?? []) as $i => $m) {
    $mapId['match:' . ($m['slug'] ?? $i)] = Database::insert('maps', [
        'slug' => 'mapa-' . ($m['slug'] ?? $i), 'name' => $m['name'] ?? 'Mapa', 'kind' => 'match',
        'mode' => $m['mode'] ?? null, 'players' => $m['players'] ?? null,
        'image' => isset($m['image']) ? preg_replace('#^assets/img/#', '', (string)$m['image']) : null,
        'description' => $m['desc'] ?? null,
        // Level da Unreal que este mapa carrega. Nenhum level de partida existe ainda no
        // projeto: todos apontam para MainMenu, que é o alvo do teste de integração.
        'unreal_level' => $m['unreal_level'] ?? '/Game/FPS_Controller/Maps/MainMenu',
        'meta' => $j([
            'icon' => $m['icon'] ?? null, 'biome' => $m['biome'] ?? null, 'best' => $m['best'] ?? null,
            'size' => $m['size'] ?? null, 'weather' => $m['weather'] ?? null,
            'best_by' => $m['best_by'] ?? null, 'your_best' => $m['your_best'] ?? null,
            'selected' => !empty($m['selected']),
        ]),
        'sort_order' => $i,
    ]);
}
echo count($mapId) . " mapas inseridos.\n";

// ============================================================================
// MISSÕES + recompensas
// ============================================================================
$missionId = [];
foreach (($data('missoes')['items'] ?? []) as $i => $m) {
    $mid = Database::insert('missions', [
        'slug' => $m['slug'] ?? ('missao-' . $i), 'name' => $m['title'] ?? 'Missão', 'giver' => $m['faction'] ?? null,
        'region' => $m['region'] ?? null, 'type' => $m['type'] ?? 'contrato', 'difficulty' => $m['difficulty'] ?? null,
        'duration_min' => (int)filter_var($m['duration'] ?? '', FILTER_SANITIZE_NUMBER_INT) ?: null,
        'players_max' => (int)($m['players'] ?? 0) ?: null, 'level_req' => $m['level_req'] ?? null,
        'objective' => $m['objective'] ?? null, 'target' => isset($m['progress']['total']) ? (int)$m['progress']['total'] : null,
        'image' => isset($m['image']) ? preg_replace('#^assets/img/#', '', (string)$m['image']) : null,
        'locked' => (($m['status'] ?? '') === 'bloqueada') ? 1 : 0, 'sort_order' => $i,
    ]);
    $missionId[$m['slug'] ?? $i] = ['id' => $mid, 'status' => $m['status'] ?? 'disponivel', 'progress' => (int)($m['progress']['current'] ?? 0)];
    foreach (($m['rewards'] ?? []) as $rw) {
        Database::insert('mission_rewards', ['mission_id' => $mid, 'kind' => $rw['kind'] ?? $rw['type'] ?? 'xp', 'amount' => (int)($rw['amount'] ?? $rw['value'] ?? 0)]);
    }
    if (!empty($m['reward_item'])) {
        $ri = $m['reward_item'];
        $slug = isset($ri['image']) ? basename((string)$ri['image'], '.png') : null;
        if ($slug && isset($itemId[$slug])) {
            Database::insert('mission_rewards', ['mission_id' => $mid, 'kind' => 'item', 'amount' => 1, 'item_id' => $itemId[$slug]]);
        }
    }
}
echo count($missionId) . " missões inseridas.\n";

// ============================================================================
// CONQUISTAS + recompensas
// ============================================================================
$achId = [];
foreach (($data('conquistas')['achievements'] ?? $data('conquistas')['items'] ?? []) as $i => $a) {
    $target = 1; $prog = 0;
    if (isset($a['status']) && is_array($a['status']) && isset($a['status']['total'])) { $target = (int)$a['status']['total']; $prog = (int)($a['status']['current'] ?? 0); }
    $aid = Database::insert('achievements', [
        'slug' => $a['slug'] ?? ('conq-' . $i), 'name' => $a['title'] ?? 'Conquista', 'category' => $a['category'] ?? 'combate',
        'description' => $a['desc'] ?? null, 'icon' => $a['icon'] ?? null, 'target' => $target, 'sort_order' => $i,
    ]);
    $completed = ($a['status'] ?? '') === 'concluida' || !empty($a['unlocked_at']) || (is_string($a['status'] ?? null) && str_contains((string)($a['status'] ?? ''), 'CONCLU'));
    $achId[$a['slug'] ?? $i] = ['id' => $aid, 'progress' => $completed ? $target : $prog, 'done' => $completed, 'date' => $a['date'] ?? $a['unlocked_at'] ?? null];
    foreach (($a['rewards'] ?? []) as $rw) {
        Database::insert('achievement_rewards', ['achievement_id' => $aid, 'kind' => $rw['kind'] ?? $rw['type'] ?? 'xp', 'amount' => (int)($rw['amount'] ?? $rw['value'] ?? 0)]);
    }
    if (!empty($a['reward_item'])) {
        $slug = isset($a['reward_item']['image']) ? basename((string)$a['reward_item']['image'], '.png') : null;
        if ($slug && isset($itemId[$slug])) {
            Database::insert('achievement_rewards', ['achievement_id' => $aid, 'kind' => 'item', 'amount' => 1, 'item_id' => $itemId[$slug]]);
        }
    }
}
echo count($achId) . " conquistas inseridas.\n";

// ============================================================================
// OFERTAS DA LOJA (mapeia por slug da imagem -> item)
// ============================================================================
$offers = 0;
foreach (($data('loja')['items'] ?? []) as $i => $o) {
    $img = $o['img'] ?? $o['image'] ?? '';
    $slug = $img ? basename((string)$img, '.png') : null;
    if (!$slug || !isset($itemId[$slug])) { continue; }
    Database::insert('shop_offers', [
        'item_id' => $itemId[$slug], 'category' => $o['category'] ?? 'destaques',
        'price' => (int)($o['price'] ?? 0), 'currency' => $o['currency'] ?? 'credits',
        'discount' => $o['discount'] ?? null, 'tag' => $o['tag'] ?? ($o['badge'] ?? null),
        'featured' => !empty($o['featured']) ? 1 : 0, 'sort_order' => $i,
    ]);
    $offers++;
}
echo "$offers ofertas de loja inseridas.\n";

// ============================================================================
// HABILIDADES (catálogo) + NOTÍCIAS
// ============================================================================
require __DIR__ . '/seed_skills.php';
$skills = seed_skills_catalog();   // icone, arte, step/unit/effect derivados do catalogo
$news = 0;
foreach (($data('noticias')['items'] ?? []) as $i => $n) {
    Database::insert('news', [
        'slug' => $n['slug'] ?? ('noticia-' . $i), 'title' => $n['title'] ?? 'Notícia', 'category' => $n['category'] ?? $n['tag'] ?? null,
        'excerpt' => $n['excerpt'] ?? $n['text'] ?? null, 'body' => isset($n['body']) ? $j($n['body']) : null,
        'image' => isset($n['image']) ? preg_replace('#^assets/img/#', '', (string)$n['image']) : null,
        'published' => isset($n['date']) ? date('Y-m-d', strtotime(str_replace('/', '-', (string)$n['date'])) ?: time()) : null, 'sort_order' => $i,
    ]);
    $news++;
}
echo "$skills habilidades, $news notícias inseridas.\n";

// ============================================================================
// USUÁRIO DEMO — nível 0, com dinheiro, stash inicial
// ============================================================================
require __DIR__ . '/seed_player.php';
seed_demo_player($itemId, $missionId, $achId, $j);

// ============================================================================
// SOCIAL — lista de amigos e esquadrao recente do jogador demo
// ============================================================================
require __DIR__ . '/seed_friends.php';
$demoUid = (int)Database::value('SELECT id FROM users ORDER BY id LIMIT 1');
if ($demoUid > 0) {
    echo seed_friends($demoUid) . " linhas sociais (amigos + esquadrao) inseridas.\n";
}

// ============================================================================
// MENSAGENS (caixa por jogador) + janelas de manutenção (catálogo)
// ============================================================================
require __DIR__ . '/seed_messages.php';
$mensagens = $data('mensagens');
$janela    = seed_maintenance($mensagens);
foreach (Database::all('SELECT id FROM users ORDER BY id') as $u) {
    seed_messages((int)$u['id'], $mensagens, $janela);
}

// ============================================================================
// TEMPORADA + DESAFIOS (tela /desafios)
// ============================================================================
require __DIR__ . '/seed_challenges.php';
seed_challenges($data, $itemId);

// ============================================================================
// SUPORTE (FAQ, categorias, status dos servidores, chamados) — tela /suporte
// ============================================================================
require __DIR__ . '/seed_support.php';
seed_support((int)Database::value('SELECT id FROM users ORDER BY id LIMIT 1'));

echo "\nSeed concluído.\n";
