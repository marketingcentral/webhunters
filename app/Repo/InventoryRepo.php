<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Inventário estilo Tarkov, persistido em player_items. Conceito de "container":
 *   stash  -> grade principal do baú (STASH_COLS x STASH_ROWS)
 *   equip  -> equipado num slot do personagem (slot_key)
 *   grid   -> dentro de outro item container (parent_id + pos na grade do pai)
 *   attach -> instalado numa arma (parent_id + slot_key do bocal)
 *
 * A camada valida: compatibilidade de tipo (slot/bocal), limites e sobreposição na grade,
 * e rotação (troca largura/altura). Toda mutação é atômica.
 */
final class InventoryRepo
{
    public const STASH_COLS = 8;
    public const STASH_ROWS = 11;
    public const POCKET_COLS = 4;    // bolsos do personagem (4 células 1x1)

    // ------------------------------------------------------------------ leitura

    /** Árvore completa do inventário do jogador, pronta para a view/JSON. */
    public static function tree(int $userId): array
    {
        $rows  = Database::all(
            'SELECT pi.*, it.slug, it.name, it.short_name, it.category, it.subtype, it.slot_type,
                    it.grid_w, it.grid_h, it.weight_kg, it.rarity, it.image, it.durability_max,
                    it.grid_cols, it.grid_rows, it.ammo_caliber, it.mag_capacity, it.attach_slots,
                    it.fits_calibers, it.stackable, it.max_stack, it.description
             FROM player_items pi JOIN items it ON it.id = pi.item_id
             WHERE pi.user_id = :id ORDER BY pi.id',
            ['id' => $userId]
        );
        $items = [];
        foreach ($rows as $r) {
            $items[(int)$r['id']] = self::shape($r);
        }

        $slots = [];
        foreach (CatalogRepo::equipmentSlots() as $s) {
            $slots[$s['slot_key']] = ['def' => $s, 'item' => null];
        }
        $stash = []; $pockets = [];
        foreach ($items as $id => $it) {
            if ($it['container'] === 'equip' && isset($slots[$it['slot_key']])) {
                $slots[$it['slot_key']]['item'] = &$items[$id];
            } elseif ($it['container'] === 'stash') {
                $stash[] = &$items[$id];
            } elseif ($it['container'] === 'pockets') {
                $pockets[] = &$items[$id];
            }
        }
        // aninha grid/attach nos pais
        foreach ($items as $id => $it) {
            if (in_array($it['container'], ['grid', 'attach'], true) && $it['parent_id'] && isset($items[$it['parent_id']])) {
                $key = $it['container'] === 'attach' ? 'attachments' : 'contents';
                $items[$it['parent_id']][$key][] = &$items[$id];
            }
        }
        unset($it);

        return [
            'stash'   => ['cols' => self::STASH_COLS, 'rows' => self::STASH_ROWS, 'items' => array_values($stash)],
            'pockets' => ['cols' => self::POCKET_COLS, 'rows' => 1, 'items' => array_values($pockets)],
            'slots'   => $slots,
            'weight'  => self::weight($userId),
            'value'   => self::totalValue($userId),
        ];
    }

    /** @param array<string,mixed> $r */
    private static function shape(array $r): array
    {
        return [
            'id'          => (int)$r['id'],
            'item_id'     => (int)$r['item_id'],
            'slug'        => $r['slug'],
            'name'        => $r['name'],
            'short'       => $r['short_name'],
            'category'    => $r['category'],
            'subtype'     => $r['subtype'],
            'slot_type'   => $r['slot_type'],
            'container'   => $r['container'],
            'slot_key'    => $r['slot_key'],
            'parent_id'   => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'x'           => $r['pos_x'] !== null ? (int)$r['pos_x'] : null,
            'y'           => $r['pos_y'] !== null ? (int)$r['pos_y'] : null,
            'w'           => (int)$r['grid_w'],
            'h'           => (int)$r['grid_h'],
            'rotated'     => (bool)$r['rotated'],
            'weight'      => (float)$r['weight_kg'],
            'rarity'      => $r['rarity'],
            'image'       => $r['image'],
            'quantity'    => (int)$r['quantity'],
            'stackable'   => (bool)$r['stackable'],
            'max_stack'   => (int)$r['max_stack'],
            'durability'  => $r['durability_cur'] !== null ? (int)$r['durability_cur'] : null,
            'durability_max' => $r['durability_max'] !== null ? (int)$r['durability_max'] : null,
            'ammo_cur'    => $r['ammo_cur'] !== null ? (int)$r['ammo_cur'] : null,
            'mag_capacity'=> $r['mag_capacity'] !== null ? (int)$r['mag_capacity'] : null,
            'ammo_caliber'=> $r['ammo_caliber'],
            'grid_cols'   => $r['grid_cols'] !== null ? (int)$r['grid_cols'] : null,
            'grid_rows'   => $r['grid_rows'] !== null ? (int)$r['grid_rows'] : null,
            'attach_slots'=> is_string($r['attach_slots']) ? json_decode($r['attach_slots'], true) : null,
            'description' => $r['description'],
            'contents'    => [],
            'attachments' => [],
        ];
    }

    public static function weight(int $userId): float
    {
        return (float)Database::value(
            'SELECT COALESCE(SUM(it.weight_kg * pi.quantity),0) FROM player_items pi JOIN items it ON it.id = pi.item_id WHERE pi.user_id = :id',
            ['id' => $userId]
        );
    }

    public static function totalValue(int $userId): int
    {
        return (int)Database::value(
            'SELECT COALESCE(SUM(it.base_price * pi.quantity),0) FROM player_items pi JOIN items it ON it.id = pi.item_id WHERE pi.user_id = :id',
            ['id' => $userId]
        );
    }

    /** Uma instância do jogador (com dados do catálogo). @return array<string,mixed>|null */
    public static function instance(int $userId, int $instanceId): ?array
    {
        $r = Database::one(
            'SELECT pi.*, it.slug, it.name, it.short_name, it.category, it.subtype, it.slot_type,
                    it.grid_w, it.grid_h, it.weight_kg, it.rarity, it.image, it.durability_max,
                    it.grid_cols, it.grid_rows, it.ammo_caliber, it.mag_capacity, it.attach_slots,
                    it.fits_calibers, it.stackable, it.max_stack, it.description
             FROM player_items pi JOIN items it ON it.id = pi.item_id
             WHERE pi.user_id = :u AND pi.id = :id',
            ['u' => $userId, 'id' => $instanceId]
        );
        return $r ? self::shape($r) : null;
    }

    // ------------------------------------------------------------------ validação

    /** Dimensões da grade de um destino (stash | grid dentro de um container). @return array{int,int}|null */
    public static function gridDims(int $userId, string $container, ?int $parentId): ?array
    {
        if ($container === 'stash') {
            return [self::STASH_COLS, self::STASH_ROWS];
        }
        // BOLSOS: 4 células fixas do personagem. Não dependem de nenhum item equipado,
        // por isso são um container próprio em vez de um "grid" com pai.
        if ($container === 'pockets') {
            return [self::POCKET_COLS, 1];
        }
        if ($container === 'grid' && $parentId) {
            $p = self::instance($userId, $parentId);
            if ($p && $p['grid_cols'] && $p['grid_rows']) {
                return [(int)$p['grid_cols'], (int)$p['grid_rows']];
            }
        }
        return null;
    }

    /**
     * Pode colocar a instância $inst na posição (x,y) do destino sem sair da grade nem
     * sobrepor outra instância? $excludeId ignora a própria peça ao mover dentro do mesmo container.
     */
    public static function canPlace(int $userId, array $inst, string $container, ?int $parentId, int $x, int $y, bool $rotated, ?int $excludeId = null): bool
    {
        $dims = self::gridDims($userId, $container, $parentId);
        if ($dims === null) { return false; }
        [$cols, $rows] = $dims;
        $w = $rotated ? (int)$inst['h'] : (int)$inst['w'];
        $h = $rotated ? (int)$inst['w'] : (int)$inst['h'];
        if ($x < 0 || $y < 0 || $x + $w > $cols || $y + $h > $rows) { return false; }
        // recipiente não pode entrar em si mesmo
        if ($parentId && (int)$inst['id'] === (int)$parentId) { return false; }

        $occ = self::occupancy($userId, $container, $parentId, $excludeId);
        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $w; $dx++) {
                if (isset($occ[($y + $dy) . ':' . ($x + $dx)])) { return false; }
            }
        }
        return true;
    }

    /** Mapa de células ocupadas "row:col" => instanceId. */
    private static function occupancy(int $userId, string $container, ?int $parentId, ?int $excludeId): array
    {
        $sql = 'SELECT pi.id, pi.pos_x, pi.pos_y, pi.rotated, it.grid_w, it.grid_h
                FROM player_items pi JOIN items it ON it.id = pi.item_id
                WHERE pi.user_id = :u AND pi.container = :c AND pi.pos_x IS NOT NULL';
        $args = ['u' => $userId, 'c' => $container];
        if ($container === 'grid') { $sql .= ' AND pi.parent_id = :p'; $args['p'] = $parentId; }
        $occ = [];
        foreach (Database::all($sql, $args) as $r) {
            if ($excludeId !== null && (int)$r['id'] === $excludeId) { continue; }
            $w = $r['rotated'] ? (int)$r['grid_h'] : (int)$r['grid_w'];
            $h = $r['rotated'] ? (int)$r['grid_w'] : (int)$r['grid_h'];
            for ($dy = 0; $dy < $h; $dy++) {
                for ($dx = 0; $dx < $w; $dx++) {
                    $occ[((int)$r['pos_y'] + $dy) . ':' . ((int)$r['pos_x'] + $dx)] = (int)$r['id'];
                }
            }
        }
        return $occ;
    }

    /** Primeira posição livre para (w,h) num destino de grade. @return array{int,int,bool}|null [x,y,rotated] */
    public static function firstFree(int $userId, string $container, ?int $parentId, int $w, int $h): ?array
    {
        $dims = self::gridDims($userId, $container, $parentId);
        if ($dims === null) { return null; }
        [$cols, $rows] = $dims;
        $occ = self::occupancy($userId, $container, $parentId, null);
        foreach ([[false, $w, $h], [true, $h, $w]] as [$rot, $ww, $hh]) {
            for ($y = 0; $y <= $rows - $hh; $y++) {
                for ($x = 0; $x <= $cols - $ww; $x++) {
                    $free = true;
                    for ($dy = 0; $dy < $hh && $free; $dy++) {
                        for ($dx = 0; $dx < $ww; $dx++) {
                            if (isset($occ[($y + $dy) . ':' . ($x + $dx)])) { $free = false; break; }
                        }
                    }
                    if ($free) { return [$x, $y, $rot]; }
                }
            }
        }
        return null;
    }

    /** O slot de equipamento aceita a categoria do item? */
    public static function slotAccepts(string $slotKey, string $category): bool
    {
        foreach (CatalogRepo::equipmentSlots() as $s) {
            if ($s['slot_key'] === $slotKey) {
                return in_array($category, $s['accepts'], true);
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ mutações

    /**
     * Move/posiciona uma instância. $dest = ['container'=>stash|grid|equip|attach, 'parent_id'=>?, 'slot_key'=>?, 'x'=>?, 'y'=>?, 'rotated'=>?].
     * @return array{ok:bool,error?:string}
     */
    public static function move(int $userId, int $instanceId, array $dest): array
    {
        return Database::transaction(function () use ($userId, $instanceId, $dest): array {
            $inst = self::instance($userId, $instanceId);
            if ($inst === null) { return ['ok' => false, 'error' => 'Item não encontrado.']; }
            $container = (string)($dest['container'] ?? 'stash');
            // whitelist: o valor vem do cliente e é gravado na coluna — nada fora desta lista
            if (!in_array($container, ['stash', 'pockets', 'grid', 'equip', 'attach'], true)) {
                return ['ok' => false, 'error' => 'Destino inválido.'];
            }
            // 0 e "" nunca são um pai válido: normaliza para null (a FK só aceita id real ou NULL)
            $parentId  = !empty($dest['parent_id']) ? (int)$dest['parent_id'] : null;
            $slotKey   = ($dest['slot_key'] ?? '') !== '' ? (string)$dest['slot_key'] : null;
            $rotated   = !empty($dest['rotated']);
            if ($container === 'grid' && $parentId === null) {
                return ['ok' => false, 'error' => 'Container de destino inválido.'];
            }

            if ($container === 'equip') {
                if ($slotKey === null || !self::slotAccepts($slotKey, (string)$inst['category'])) {
                    return ['ok' => false, 'error' => 'Este item não vai neste slot.'];
                }
                // desequipa o que estiver no slot (manda pro stash)
                $current = Database::one('SELECT id FROM player_items WHERE user_id=:u AND container="equip" AND slot_key=:s AND id<>:me',
                    ['u' => $userId, 's' => $slotKey, 'me' => $instanceId]);
                if ($current) { self::sendToStash($userId, (int)$current['id']); }
                Database::run('UPDATE player_items SET container="equip", slot_key=:s, parent_id=NULL, pos_x=NULL, pos_y=NULL, rotated=0 WHERE id=:id AND user_id=:u',
                    ['s' => $slotKey, 'id' => $instanceId, 'u' => $userId]);
                return ['ok' => true];
            }

            if ($container === 'attach') {
                return self::installAttachment($userId, $instanceId, $parentId, $slotKey);
            }

            // stash ou grid: exige posição válida
            $x = isset($dest['x']) ? (int)$dest['x'] : null;
            $y = isset($dest['y']) ? (int)$dest['y'] : null;
            if ($x === null || $y === null) {
                $ff = self::firstFree($userId, $container, $parentId, (int)$inst['w'], (int)$inst['h']);
                if ($ff === null) { return ['ok' => false, 'error' => 'Sem espaço no destino.']; }
                [$x, $y, $rotated] = $ff;
            }
            if (!self::canPlace($userId, $inst, $container, $parentId, $x, $y, $rotated, $instanceId)) {
                return ['ok' => false, 'error' => 'Não cabe aqui.'];
            }
            Database::run(
                'UPDATE player_items SET container=:c, parent_id=:p, slot_key=NULL, pos_x=:x, pos_y=:y, rotated=:r WHERE id=:id AND user_id=:u',
                ['c' => $container, 'p' => $parentId, 'x' => $x, 'y' => $y, 'r' => $rotated ? 1 : 0, 'id' => $instanceId, 'u' => $userId]
            );
            return ['ok' => true];
        });
    }

    /** Instala um attachment numa arma, validando o bocal. @return array{ok:bool,error?:string} */
    public static function installAttachment(int $userId, int $instanceId, ?int $weaponId, ?string $slotKey): array
    {
        $att = self::instance($userId, $instanceId);
        $weapon = $weaponId ? self::instance($userId, $weaponId) : null;
        if ($att === null || $weapon === null) { return ['ok' => false, 'error' => 'Alvo inválido.']; }
        if (!in_array($weapon['category'], ['weapon', 'pistol'], true)) { return ['ok' => false, 'error' => 'Só armas recebem acessórios.']; }
        $bocais = $weapon['attach_slots'] ?? [];
        $slot = null;
        foreach ($bocais as $b) { if (($b['key'] ?? null) === $slotKey) { $slot = $b; break; } }
        if ($slot === null) { return ['ok' => false, 'error' => 'Bocal inexistente.']; }
        $accepts = $slot['accepts'] ?? [];
        if (!in_array((string)$att['subtype'], $accepts, true) && !in_array((string)$att['category'], $accepts, true)) {
            return ['ok' => false, 'error' => 'Acessório incompatível com este bocal.'];
        }
        // bocal já ocupado? devolve o antigo ao stash
        $busy = Database::one('SELECT id FROM player_items WHERE user_id=:u AND container="attach" AND parent_id=:p AND slot_key=:s AND id<>:me',
            ['u' => $userId, 'p' => $weaponId, 's' => $slotKey, 'me' => $instanceId]);
        if ($busy) { self::sendToStash($userId, (int)$busy['id']); }
        Database::run('UPDATE player_items SET container="attach", parent_id=:p, slot_key=:s, pos_x=NULL, pos_y=NULL, rotated=0 WHERE id=:id AND user_id=:u',
            ['p' => $weaponId, 's' => $slotKey, 'id' => $instanceId, 'u' => $userId]);
        return ['ok' => true];
    }

    /** Manda uma instância de volta ao primeiro espaço livre do stash (fallback: (0,0)). */
    // ------------------------------------------------------------------ munição (HUD in-game)

    /**
     * Carregador instalado na arma equipada num slot ('primary', 'pistol', ...).
     * A munição de uma arma vive no CARREGADOR anexado, não na arma — por isso o
     * caminho é slot -> arma -> attachment com mag_capacity.
     *
     * @return array<string,mixed>|null
     */
    public static function magazineOf(int $userId, string $slotKey): ?array
    {
        return Database::one(
            'SELECT mag.*, it.mag_capacity, it.ammo_caliber, it.name, it.short_name
               FROM player_items arma
               JOIN player_items mag ON mag.parent_id = arma.id AND mag.container = "attach" AND mag.user_id = arma.user_id
               JOIN items it ON it.id = mag.item_id
              WHERE arma.user_id = :u AND arma.container = "equip" AND arma.slot_key = :s
                AND it.mag_capacity IS NOT NULL
              ORDER BY mag.id LIMIT 1',
            ['u' => $userId, 's' => $slotKey]
        );
    }

    /**
     * Grava a munição do carregador. Trunca no intervalo [0, capacidade] — o jogo é a
     * autoridade sobre quantos tiros saíram, mas não sobre o tamanho do carregador.
     *
     * @return array{ok:bool,error?:string,current?:int,capacity?:int}
     */
    public static function setAmmo(int $userId, string $slotKey, int $current): array
    {
        $mag = self::magazineOf($userId, $slotKey);
        if ($mag === null) {
            return ['ok' => false, 'error' => 'Nenhum carregador nesta arma.'];
        }
        $cap = (int)$mag['mag_capacity'];
        $cur = max(0, min($cap, $current));
        Database::run(
            'UPDATE player_items SET ammo_cur = :a WHERE id = :id AND user_id = :u',
            ['a' => $cur, 'id' => (int)$mag['id'], 'u' => $userId]
        );
        return ['ok' => true, 'current' => $cur, 'capacity' => $cap];
    }

    /**
     * Munição de reserva: soma o que existe em OUTROS carregadores do mesmo calibre,
     * em qualquer lugar do inventário (baú, bolsos, contêineres, outras armas).
     */
    public static function reserveFor(int $userId, ?string $caliber, int $exceptInstanceId = 0): int
    {
        if ($caliber === null || $caliber === '') {
            return 0;
        }
        return (int)Database::value(
            'SELECT COALESCE(SUM(pi.ammo_cur), 0)
               FROM player_items pi JOIN items it ON it.id = pi.item_id
              WHERE pi.user_id = :u AND it.ammo_caliber = :c
                AND it.mag_capacity IS NOT NULL AND pi.id <> :ex',
            ['u' => $userId, 'c' => $caliber, 'ex' => $exceptInstanceId]
        );
    }

    public static function sendToStash(int $userId, int $instanceId): void
    {
        $inst = self::instance($userId, $instanceId);
        if ($inst === null) { return; }
        $ff = self::firstFree($userId, 'stash', null, (int)$inst['w'], (int)$inst['h']) ?? [0, 0, false];
        Database::run('UPDATE player_items SET container="stash", parent_id=NULL, slot_key=NULL, pos_x=:x, pos_y=:y, rotated=:r WHERE id=:id AND user_id=:u',
            ['x' => $ff[0], 'y' => $ff[1], 'r' => $ff[2] ? 1 : 0, 'id' => $instanceId, 'u' => $userId]);
    }

    /**
     * Compra uma oferta da loja: debita a moeda e cria a instância no stash.
     * @return array{ok:bool,error?:string,instance_id?:int}
     */
    public static function buy(int $userId, int $offerId): array
    {
        return Database::transaction(function () use ($userId, $offerId): array {
            $offer = Database::one('SELECT o.*, i.grid_w, i.grid_h, i.durability_max, i.mag_capacity FROM shop_offers o JOIN items i ON i.id=o.item_id WHERE o.id=:id', ['id' => $offerId]);
            if ($offer === null) { return ['ok' => false, 'error' => 'Oferta indisponível.']; }
            $price = (int)$offer['price'];
            if ((int)($offer['discount'] ?? 0) > 0) { $price = (int)round($price * (100 - (int)$offer['discount']) / 100); }
            if (!PlayerRepo::adjustCurrency($userId, (string)$offer['currency'], -$price)) {
                return ['ok' => false, 'error' => 'Saldo insuficiente.'];
            }
            $ff = self::firstFree($userId, 'stash', null, (int)$offer['grid_w'], (int)$offer['grid_h']) ?? [0, 0, false];
            $id = Database::insert('player_items', [
                'user_id'        => $userId,
                'item_id'        => (int)$offer['item_id'],
                'container'      => 'stash',
                'pos_x'          => $ff[0],
                'pos_y'          => $ff[1],
                'rotated'        => $ff[2] ? 1 : 0,
                'quantity'       => 1,
                'durability_cur' => $offer['durability_max'] !== null ? (int)$offer['durability_max'] : null,
                'ammo_cur'       => (int)($offer['mag_capacity'] ?? 0) ?: null,
                'acquired_via'   => 'loja',
            ]);
            Database::insert('player_purchases', [
                'user_id' => $userId, 'item_id' => (int)$offer['item_id'], 'quantity' => 1,
                'currency' => (string)$offer['currency'], 'price' => $price,
            ]);
            return ['ok' => true, 'instance_id' => $id];
        });
    }
}
