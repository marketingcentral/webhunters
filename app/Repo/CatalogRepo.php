<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * Leitura do catálogo (definições do jogo, iguais para todos). Cada método devolve linhas
 * já decodificadas (JSON -> array) prontas para a view. Cacheado por requisição.
 */
final class CatalogRepo
{
    /** @var array<string,mixed> */
    private static array $cache = [];

    /** @return list<array<string,mixed>> */
    public static function items(): array
    {
        return self::cached('items', static fn(): array => array_map([self::class, 'decodeItem'],
            Database::all('SELECT * FROM items ORDER BY category, name')));
    }

    /** @return array<int,array<string,mixed>> indexado por id */
    public static function itemsById(): array
    {
        return self::cached('itemsById', static function (): array {
            $out = [];
            foreach (self::items() as $it) { $out[(int)$it['id']] = $it; }
            return $out;
        });
    }

    /** @return array<string,mixed>|null */
    public static function item(int $id): ?array
    {
        return self::itemsById()[$id] ?? null;
    }

    /** @return list<array<string,mixed>> ofertas da loja com o item embutido */
    public static function shopOffers(): array
    {
        return self::cached('shop', static function (): array {
            $rows = Database::all(
                'SELECT o.*, i.name, i.short_name, i.image, i.category AS item_category, i.rarity,
                        i.description, i.grid_w, i.grid_h, i.weight_kg
                 FROM shop_offers o JOIN items i ON i.id = o.item_id
                 ORDER BY o.category, o.sort_order, o.id'
            );
            return $rows;
        });
    }

    /** @return list<array<string,mixed>> */
    public static function maps(string $kind): array
    {
        return self::cached('maps:' . $kind, static function () use ($kind): array {
            $rows = Database::all('SELECT * FROM maps WHERE kind = :k ORDER BY sort_order, id', ['k' => $kind]);
            foreach ($rows as &$r) { $r['meta'] = json_decode((string)($r['meta'] ?? 'null'), true) ?: []; }
            return $rows;
        });
    }

    /**
     * Bases Governamentais (Alpha, Bravo, Charlie) com capacidade e estado — tabela do patch v3.
     * Sem a tabela (patch ainda não aplicado) devolve as três com o padrão canônico, para a
     * HUD não ficar sem base.
     * @return list<array{slug:string,name:string,short:string,capacity:int,status:string,map_id:?int}>
     */
    public static function governmentBases(): array
    {
        return self::cached('gov_bases', static function (): array {
            try {
                $rows = Database::all('SELECT slug, name, map_id, capacity, status FROM government_bases ORDER BY sort_order, id');
            } catch (\Throwable) {
                $rows = [];
            }
            if ($rows === []) {
                $rows = [
                    ['slug' => 'alpha',   'name' => 'BASE HUNTER ALPHA',   'map_id' => null, 'capacity' => 4, 'status' => 'OPERACIONAL'],
                    ['slug' => 'bravo',   'name' => 'BASE HUNTER BRAVO',   'map_id' => null, 'capacity' => 4, 'status' => 'OPERACIONAL'],
                    ['slug' => 'charlie', 'name' => 'BASE HUNTER CHARLIE', 'map_id' => null, 'capacity' => 4, 'status' => 'OPERACIONAL'],
                ];
            }
            foreach ($rows as &$r) {
                $r['capacity'] = (int)$r['capacity'];
                $r['map_id']   = $r['map_id'] !== null ? (int)$r['map_id'] : null;
                $r['short']    = (string)preg_replace('/^BASE HUNTER /u', '', (string)$r['name']);
            }
            return $rows;
        });
    }

    /**
     * Estado de mundo de uma região (maps.meta do patch v3), já com os padrões canônicos
     * quando a chave ainda não existe.
     * @param array<string,mixed> $row linha de maps (com meta decodificado)
     * @return array{persistent:bool,capacity:array{total:int,hunters:int,mercenaries:int},infestation:int,veyra_activity:string,human_activity:string,weather:string,sector_state:string}
     */
    public static function regionState(array $row): array
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $cap  = is_array($meta['capacity'] ?? null) ? $meta['capacity'] : [];
        return [
            'persistent'     => (bool)($meta['persistent'] ?? true),
            'capacity'       => [
                'total'       => (int)($cap['total'] ?? \App\Core\GameRules::REGION_CAPACITY['total']),
                'hunters'     => (int)($cap['hunters'] ?? \App\Core\GameRules::REGION_CAPACITY['hunters']),
                'mercenaries' => (int)($cap['mercenaries'] ?? \App\Core\GameRules::REGION_CAPACITY['mercenaries']),
            ],
            'infestation'    => (int)($meta['infestation'] ?? 0),
            'veyra_activity' => (string)($meta['veyra_activity'] ?? 'MODERADA'),
            'human_activity' => (string)($meta['human_activity'] ?? 'MODERADA'),
            'weather'        => (string)($meta['weather'] ?? ''),
            'sector_state'   => (string)($meta['sector_state'] ?? 'ATIVO'),
        ];
    }

    /** @return list<array<string,mixed>> marcadores da região */
    public static function mapMarkers(int $mapId): array
    {
        $rows = Database::all('SELECT * FROM map_markers WHERE map_id = :m ORDER BY id', ['m' => $mapId]);
        foreach ($rows as &$r) { $r['meta'] = json_decode((string)($r['meta'] ?? 'null'), true) ?: []; }
        return $rows;
    }

    /** @return list<array<string,mixed>> missões com recompensas embutidas */
    public static function missions(): array
    {
        return self::cached('missions', static function (): array {
            $rows = Database::all('SELECT * FROM missions ORDER BY sort_order, id');
            foreach ($rows as &$m) {
                $m['rewards'] = Database::all(
                    'SELECT r.kind, r.amount, r.item_id, i.name AS item_name, i.image AS item_image
                     FROM mission_rewards r LEFT JOIN items i ON i.id = r.item_id WHERE r.mission_id = :id',
                    ['id' => $m['id']]
                );
            }
            return $rows;
        });
    }

    /** @return list<array<string,mixed>> conquistas com recompensas */
    public static function achievements(): array
    {
        return self::cached('achievements', static function (): array {
            $rows = Database::all('SELECT * FROM achievements ORDER BY sort_order, id');
            foreach ($rows as &$a) {
                $a['rewards'] = Database::all(
                    'SELECT r.kind, r.amount, r.item_id, i.name AS item_name, i.image AS item_image
                     FROM achievement_rewards r LEFT JOIN items i ON i.id = r.item_id WHERE r.achievement_id = :id',
                    ['id' => $a['id']]
                );
            }
            return $rows;
        });
    }

    /** @return list<array<string,mixed>> */
    public static function skills(): array
    {
        return self::cached('skills', static fn(): array => Database::all('SELECT * FROM skills ORDER BY category, tier, col'));
    }

    /**
     * Notícias PUBLICADAS, na ordem da vitrine.
     *
     * O filtro por `published` existe porque o painel (/admin/eventos, aba Notícias) chama
     * de RASCUNHO a notícia sem data e diz isso na tela ao salvar. Sem o filtro aqui, o
     * rascunho já estava no ar para o jogador e "publicar" não significava nada — o texto
     * aparecia no instante em que era digitado. Data futura também espera a sua vez.
     *
     * @return list<array<string,mixed>>
     */
    public static function news(): array
    {
        return self::cached('news', static function (): array {
            $rows = Database::all(
                'SELECT * FROM news WHERE published IS NOT NULL AND published <= CURDATE()
                  ORDER BY sort_order, id'
            );
            foreach ($rows as &$n) {
                $b = json_decode((string)($n['body'] ?? 'null'), true);
                $n['body'] = is_array($b) ? $b : (($n['body'] ?? '') !== '' ? [(string)$n['body']] : []);
            }
            return $rows;
        });
    }

    /** @return list<array<string,mixed>> definição dos slots de equipamento */
    public static function equipmentSlots(): array
    {
        return self::cached('slots', static function (): array {
            $rows = Database::all('SELECT * FROM equipment_slots ORDER BY sort_order');
            foreach ($rows as &$s) { $s['accepts'] = json_decode((string)$s['accepts'], true) ?: []; }
            return $rows;
        });
    }

    /** @param array<string,mixed> $row */
    public static function decodeItem(array $row): array
    {
        foreach (['attach_slots', 'fits_calibers', 'meta'] as $k) {
            if (isset($row[$k]) && is_string($row[$k])) {
                $row[$k] = json_decode($row[$k], true);
            }
        }
        return $row;
    }

    private static function cached(string $key, callable $fn): mixed
    {
        return self::$cache[$key] ??= $fn();
    }
}
