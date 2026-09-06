<?php declare(strict_types=1);

/**
 * Patch ADITIVO para o HUD in-game. Não apaga nada e pode rodar quantas vezes quiser.
 *   php db/patch_municao.php [--dry-run]
 *
 * Faz duas coisas:
 *   1. insere no catálogo os itens de db/catalog.php que ainda não existem (hoje: o
 *      carregador 9x19 da Glock — sem ele a pistola não tem como ter munição);
 *   2. para cada arma JÁ EQUIPADA sem carregador, instala um carregador compatível que o
 *      próprio jogador já tenha no inventário. Não cria loot: só move o que é dele.
 *
 * Existe porque db/seed.php é destrutivo (apaga contas). Mudança de catálogo em banco
 * com jogador real entra por aqui.
 */

require __DIR__ . '/cli_only.php';
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$dry = in_array('--dry-run', $argv, true);
$log = static function (string $m) use ($dry): void { echo ($dry ? '[dry] ' : '') . $m . "\n"; };

// ---------------------------------------------------------------- 1) catálogo
$catalog = require __DIR__ . '/catalog.php';
$j = static fn(mixed $v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);

$novos = 0;
foreach ($catalog as $slug => $c) {
    if (Database::value('SELECT id FROM items WHERE slug = :s', ['s' => $slug])) {
        continue;
    }
    $log("catálogo: inserindo item novo `$slug`");
    $novos++;
    if ($dry) { continue; }
    Database::insert('items', [
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
        'description'    => $c['desc'] ?? null,
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
    ]);
}
$log($novos === 0 ? 'catálogo: nada novo a inserir' : "catálogo: $novos item(ns) inserido(s)");

// -------------------------------------------------- 2) carregar armas equipadas
$armas = Database::all(
    'SELECT pi.id, pi.user_id, pi.slot_key, it.slug, it.ammo_caliber, it.attach_slots
       FROM player_items pi JOIN items it ON it.id = pi.item_id
      WHERE pi.container = "equip" AND pi.slot_key IN ("primary", "secondary", "pistol")
        AND it.ammo_caliber IS NOT NULL'
);

$instalados = 0;
foreach ($armas as $a) {
    $armaId = (int)$a['id'];
    $uid    = (int)$a['user_id'];

    $jaTem = Database::value(
        'SELECT mag.id FROM player_items mag JOIN items it ON it.id = mag.item_id
          WHERE mag.parent_id = :p AND mag.container = "attach" AND it.mag_capacity IS NOT NULL',
        ['p' => $armaId]
    );
    if ($jaTem) { continue; }

    // qual bocal aceita carregador nesta arma?
    $bocal = null;
    foreach ((array)json_decode((string)$a['attach_slots'], true) as $b) {
        if (in_array('mag', (array)($b['accepts'] ?? []), true)) { $bocal = (string)$b['key']; break; }
    }
    if ($bocal === null) {
        $log("jogador $uid / {$a['slug']}: arma não tem bocal de carregador, pulando");
        continue;
    }

    // um carregador do mesmo calibre que o jogador já possua e que não esteja em outra arma
    $mag = Database::one(
        'SELECT pi.id, it.mag_capacity FROM player_items pi JOIN items it ON it.id = pi.item_id
          WHERE pi.user_id = :u AND it.ammo_caliber = :c AND it.mag_capacity IS NOT NULL
            AND pi.container <> "attach"
          ORDER BY pi.id LIMIT 1',
        ['u' => $uid, 'c' => $a['ammo_caliber']]
    );
    if ($mag === null) {
        $log("jogador $uid / {$a['slug']} ({$a['ammo_caliber']}): sem carregador compatível no inventário");
        continue;
    }

    $log("jogador $uid: instalando carregador #{$mag['id']} em {$a['slug']} (slot {$a['slot_key']}, bocal $bocal)");
    $instalados++;
    if ($dry) { continue; }
    Database::run(
        'UPDATE player_items SET container = "attach", parent_id = :p, slot_key = :s,
                pos_x = NULL, pos_y = NULL, rotated = 0,
                ammo_cur = COALESCE(ammo_cur, :cap)
          WHERE id = :id',
        ['p' => $armaId, 's' => $bocal, 'cap' => (int)$mag['mag_capacity'], 'id' => (int)$mag['id']]
    );
}
$log($instalados === 0 ? 'armas: nenhuma precisava de carregador' : "armas: $instalados carregador(es) instalado(s)");
echo "\nPatch concluído" . ($dry ? ' (dry-run, nada gravado)' : '') . ".\n";
