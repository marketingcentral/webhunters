<?php declare(strict_types=1);

namespace App\Api;

use App\Repo\ShopOfferRepo;

/**
 * AS DECLARAÇÕES DAS COLEÇÕES — o que a API expõe de cada tabela de conteúdo.
 *
 * Cada declaração é lida por App\Api\Tabela. O que está aqui é contrato público: o nome do
 * campo é o nome que o cliente manda, a regra é o que ele pode mandar, e o que não está
 * listado não existe para a API — nem para ler, nem para escrever.
 *
 * DUAS DECISÕES QUE ATRAVESSAM O ARQUIVO
 *
 * 1. `enum` só onde a lista é ESTRUTURAL, isto é, onde um valor novo quebraria o jogo porque
 *    o código ramifica nele (maps.kind decide se o mapa é região ou partida; challenges.scope
 *    decide em qual aba o desafio aparece). Onde a lista é apenas CONTEÚDO — a categoria de um
 *    item, a raridade, o rótulo de dificuldade — o tipo é texto com tamanho máximo. Travar um
 *    enum sobre o que hoje existe no banco impediria o jogo de crescer pela API: a primeira
 *    categoria nova de item seria recusada por um validador que ninguém lembrava de atualizar.
 *
 * 2. Campo com `padrao` é o que o MySQL exigiria de qualquer jeito (coluna NOT NULL sem
 *    DEFAULT). Declarar o padrão aqui troca um erro de driver em 500 por um valor sensato —
 *    e deixa o valor à vista de quem lê a referência, em vez de escondido no esquema.
 */
final class Catalogo
{
    /** Moedas aceitas: a mesma lista que a loja do jogo usa, não uma cópia. */
    private const MOEDAS = ShopOfferRepo::MOEDAS;

    /**
     * Todas as coleções de conteúdo, por nome de recurso da API.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function colecoes(): array
    {
        return [
            'missions'     => self::missoes(),
            'achievements' => self::conquistas(),
            'maps'         => self::mapas(),
            'items'        => self::itens(),
            'shop'         => self::loja(),
        ];
    }

    /** @return array<string,mixed> */
    public static function missoes(): array
    {
        return [
            'tabela'  => 'missions',
            'chave'   => 'id',
            'ordem'   => 'sort_order ASC, id ASC',
            'busca'   => ['slug', 'name', 'giver', 'region'],
            'filtros' => ['type' => 'type', 'origin' => 'origin', 'region' => 'region'],
            'campos'  => [
                'slug'             => ['tipo' => 'slug', 'max' => 64, 'obrigatorio' => true, 'unico' => true],
                'name'             => ['tipo' => 'texto', 'max' => 120, 'obrigatorio' => true],
                'giver'            => ['tipo' => 'texto', 'max' => 80, 'nulo' => true],
                'region'           => ['tipo' => 'texto', 'max' => 80, 'nulo' => true],
                // Estrutural: o jogo separa contrato de facção, diária, emergência e principal.
                'type'             => ['tipo' => 'enum', 'opcoes' => ['principal', 'contrato', 'diaria', 'emergencia'], 'obrigatorio' => true],
                'difficulty'       => ['tipo' => 'texto', 'max' => 16, 'nulo' => true],
                'duration_min'     => ['tipo' => 'int', 'min' => 0, 'max' => 32767, 'nulo' => true],
                'players_max'      => ['tipo' => 'int', 'min' => 1, 'max' => 127, 'nulo' => true],
                'level_req'        => ['tipo' => 'int', 'min' => 0, 'max' => 32767, 'nulo' => true],
                'objective'        => ['tipo' => 'texto', 'max' => 400, 'nulo' => true],
                'target'           => ['tipo' => 'int', 'min' => 0, 'max' => 32767, 'nulo' => true],
                'image'            => ['tipo' => 'texto', 'max' => 160, 'nulo' => true],
                'locked'           => ['tipo' => 'bool', 'padrao' => 0],
                'sort_order'       => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'padrao' => 0],
                // Estrutural: decide de quem é o contrato (facções do PRD v3).
                'origin'           => ['tipo' => 'enum', 'opcoes' => ['world', 'aegis', 'frontier', 'hunter_command', 'icarus'], 'padrao' => 'world'],
                'act'              => ['tipo' => 'int', 'min' => 0, 'max' => 255, 'nulo' => true],
                'requires_faction' => ['tipo' => 'enum', 'opcoes' => ['any', 'aegis', 'frontier'], 'nulo' => true],
                'requires_tier'    => ['tipo' => 'texto', 'max' => 24, 'nulo' => true],
                'requires_rep'     => ['tipo' => 'int', 'min' => 0, 'max' => 999999, 'nulo' => true],
                'squad_min'        => ['tipo' => 'int', 'min' => 1, 'max' => 255, 'padrao' => 1],
                'squad_max'        => ['tipo' => 'int', 'min' => 1, 'max' => 255, 'padrao' => 4],
                'human_activity'   => ['tipo' => 'texto', 'max' => 16, 'nulo' => true],
            ],
            'dependentes' => [
                ['tabela' => 'player_missions', 'coluna' => 'mission_id', 'rotulo' => 'progresso de jogadores'],
                ['tabela' => 'mission_rewards', 'coluna' => 'mission_id', 'rotulo' => 'recompensas'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function conquistas(): array
    {
        return [
            'tabela'  => 'achievements',
            'chave'   => 'id',
            'ordem'   => 'sort_order ASC, id ASC',
            'busca'   => ['slug', 'name', 'description'],
            'filtros' => ['category' => 'category'],
            'campos'  => [
                'slug'        => ['tipo' => 'slug', 'max' => 64, 'obrigatorio' => true, 'unico' => true],
                'name'        => ['tipo' => 'texto', 'max' => 120, 'obrigatorio' => true],
                'category'    => ['tipo' => 'texto', 'max' => 24, 'obrigatorio' => true],
                'description' => ['tipo' => 'texto', 'max' => 400, 'nulo' => true],
                // `icon` é LIGADURA do Material Symbols ([a-z0-9_]), não caminho de arquivo:
                // a HUD desenha `icon($nome)`. Um caminho aqui apareceria como texto cru na
                // tela do jogador. Por isso o tamanho é 48 e o formato é conferido pelo
                // padrão de texto — quem quiser imagem usa `image` numa tabela que a tenha.
                'icon'        => ['tipo' => 'texto', 'max' => 48, 'nulo' => true],
                'target'      => ['tipo' => 'int', 'min' => 1, 'max' => 999999, 'padrao' => 1],
                'sort_order'  => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'padrao' => 0],
            ],
            'dependentes' => [
                ['tabela' => 'player_achievements', 'coluna' => 'achievement_id', 'rotulo' => 'progresso de jogadores'],
                ['tabela' => 'achievement_rewards', 'coluna' => 'achievement_id', 'rotulo' => 'recompensas'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function mapas(): array
    {
        return [
            'tabela'  => 'maps',
            'chave'   => 'id',
            'ordem'   => 'sort_order ASC, id ASC',
            'busca'   => ['slug', 'name', 'mode'],
            'filtros' => ['kind' => 'kind'],
            'campos'  => [
                'slug'         => ['tipo' => 'slug', 'max' => 64, 'obrigatorio' => true, 'unico' => true],
                'name'         => ['tipo' => 'texto', 'max' => 80, 'obrigatorio' => true],
                // Estrutural: 'region' entra no mapa-múndi, 'match' entra na lista de partida.
                'kind'         => ['tipo' => 'enum', 'opcoes' => ['region', 'match'], 'obrigatorio' => true],
                'mode'         => ['tipo' => 'texto', 'max' => 40, 'nulo' => true],
                'players'      => ['tipo' => 'texto', 'max' => 16, 'nulo' => true],
                'level_min'    => ['tipo' => 'int', 'min' => 0, 'max' => 32767, 'nulo' => true],
                'level_max'    => ['tipo' => 'int', 'min' => 0, 'max' => 32767, 'nulo' => true],
                'locked'       => ['tipo' => 'bool', 'padrao' => 0],
                'image'        => ['tipo' => 'texto', 'max' => 160, 'nulo' => true],
                'description'  => ['tipo' => 'texto', 'max' => 400, 'nulo' => true],
                'unreal_level' => ['tipo' => 'texto', 'max' => 200, 'nulo' => true],
                'meta'         => ['tipo' => 'json', 'nulo' => true],
                'sort_order'   => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'padrao' => 0],
            ],
            'dependentes' => [
                ['tabela' => 'map_markers', 'coluna' => 'map_id', 'rotulo' => 'marcadores'],
            ],
        ];
    }

    /**
     * Marcadores de UM mapa. `pai` faz com que a coleção só exista dentro de /maps/{id}.
     *
     * @return array<string,mixed>
     */
    public static function marcadores(): array
    {
        return [
            'tabela'  => 'map_markers',
            'chave'   => 'id',
            'pai'     => 'map_id',
            'ordem'   => 'id ASC',
            'busca'   => ['label', 'kind'],
            'filtros' => ['kind' => 'kind'],
            'campos'  => [
                'kind'  => ['tipo' => 'texto', 'max' => 24, 'obrigatorio' => true],
                'label' => ['tipo' => 'texto', 'max' => 80, 'nulo' => true],
                // x e y são FRAÇÕES da imagem (0 a 1), não pixels: a mesma posição vale em
                // qualquer resolução. DECIMAL(6,4) no banco, então quatro casas.
                'x'     => ['tipo' => 'decimal', 'min' => 0, 'max' => 1, 'escala' => 4, 'obrigatorio' => true],
                'y'     => ['tipo' => 'decimal', 'min' => 0, 'max' => 1, 'escala' => 4, 'obrigatorio' => true],
                'meta'  => ['tipo' => 'json', 'nulo' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function itens(): array
    {
        return [
            'tabela'  => 'items',
            'chave'   => 'id',
            'ordem'   => 'name ASC, id ASC',
            'busca'   => ['slug', 'name', 'short_name'],
            'filtros' => ['category' => 'category', 'rarity' => 'rarity', 'slot_type' => 'slot_type'],
            'campos'  => [
                'slug'           => ['tipo' => 'slug', 'max' => 64, 'obrigatorio' => true, 'unico' => true],
                'name'           => ['tipo' => 'texto', 'max' => 120, 'obrigatorio' => true],
                'short_name'     => ['tipo' => 'texto', 'max' => 32, 'nulo' => true],
                'category'       => ['tipo' => 'texto', 'max' => 24, 'obrigatorio' => true],
                'subtype'        => ['tipo' => 'texto', 'max' => 32, 'nulo' => true],
                'slot_type'      => ['tipo' => 'texto', 'max' => 24, 'nulo' => true],
                // Tamanho na grade do inventário Tarkov: 1x1 é o mínimo real, não 0x0.
                'grid_w'         => ['tipo' => 'int', 'min' => 1, 'max' => 255, 'padrao' => 1],
                'grid_h'         => ['tipo' => 'int', 'min' => 1, 'max' => 255, 'padrao' => 1],
                'weight_kg'      => ['tipo' => 'decimal', 'min' => 0, 'max' => 999, 'escala' => 3, 'padrao' => 0],
                'rarity'         => ['tipo' => 'texto', 'max' => 16, 'padrao' => 'comum'],
                'image'          => ['tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
                'description'    => ['tipo' => 'texto', 'max' => 400, 'nulo' => true],
                'base_price'     => ['tipo' => 'int', 'min' => 0, 'max' => 99999999, 'padrao' => 0],
                'currency'       => ['tipo' => 'enum', 'opcoes' => self::MOEDAS, 'padrao' => 'credits'],
                'stackable'      => ['tipo' => 'bool', 'padrao' => 0],
                'max_stack'      => ['tipo' => 'int', 'min' => 1, 'max' => 65535, 'padrao' => 1],
                'durability_max' => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'nulo' => true],
                'grid_cols'      => ['tipo' => 'int', 'min' => 0, 'max' => 255, 'nulo' => true],
                'grid_rows'      => ['tipo' => 'int', 'min' => 0, 'max' => 255, 'nulo' => true],
                'ammo_caliber'   => ['tipo' => 'texto', 'max' => 24, 'nulo' => true],
                'mag_capacity'   => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'nulo' => true],
                'attach_slots'   => ['tipo' => 'json', 'nulo' => true],
                'fits_calibers'  => ['tipo' => 'json', 'nulo' => true],
                'meta'           => ['tipo' => 'json', 'nulo' => true],
            ],
            'dependentes' => [
                ['tabela' => 'player_items', 'coluna' => 'item_id', 'rotulo' => 'itens no inventário de jogadores'],
                ['tabela' => 'shop_offers', 'coluna' => 'item_id', 'rotulo' => 'ofertas na loja'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function loja(): array
    {
        return [
            'tabela'  => 'shop_offers',
            'chave'   => 'id',
            'ordem'   => 'sort_order ASC, id ASC',
            'busca'   => ['category', 'tag'],
            'filtros' => ['category' => 'category', 'currency' => 'currency'],
            'campos'  => [
                // `ref` confere que o item existe ANTES de gravar: este esquema não usa
                // FOREIGN KEY, então uma oferta apontando para item inexistente seria aceita
                // pelo banco e apareceria como um quadrado vazio na vitrine do jogador.
                'item_id'    => ['tipo' => 'ref', 'tabela' => 'items', 'obrigatorio' => true],
                'category'   => ['tipo' => 'texto', 'max' => 24, 'obrigatorio' => true],
                'price'      => ['tipo' => 'int', 'min' => 0, 'max' => ShopOfferRepo::PRECO_MAX, 'obrigatorio' => true],
                'currency'   => ['tipo' => 'enum', 'opcoes' => self::MOEDAS, 'padrao' => 'credits'],
                'discount'   => ['tipo' => 'int', 'min' => 0, 'max' => ShopOfferRepo::DESCONTO_MAX, 'nulo' => true],
                'tag'        => ['tipo' => 'texto', 'max' => ShopOfferRepo::TAG_MAX, 'nulo' => true],
                'featured'   => ['tipo' => 'bool', 'padrao' => 0],
                'sort_order' => ['tipo' => 'int', 'min' => 0, 'max' => ShopOfferRepo::ORDEM_MAX, 'padrao' => 0],
            ],
        ];
    }

    // ================================================================= eventos (3 coleções)

    /**
     * As três coleções de /api/v1/events. Agrupadas num recurso só porque é assim que o
     * painel as governa (a tela /admin/eventos tem as três abas) e porque as três respondem
     * à mesma pergunta: "o que está acontecendo no jogo esta semana".
     *
     * @return array<string, array<string,mixed>>
     */
    public static function eventos(): array
    {
        return [
            'challenges' => [
                'tabela'  => 'challenges',
                'chave'   => 'id',
                'ordem'   => 'sort_order ASC, id ASC',
                'busca'   => ['slug', 'label', 'description'],
                'filtros' => ['scope' => 'scope'],
                'campos'  => [
                    'slug'        => ['tipo' => 'slug', 'max' => 64, 'obrigatorio' => true, 'unico' => true],
                    // Estrutural: é a aba em que o desafio aparece na tela /desafios.
                    'scope'       => ['tipo' => 'enum', 'opcoes' => ['diarios', 'semanais', 'temporada'], 'obrigatorio' => true],
                    'season_id'   => ['tipo' => 'ref', 'tabela' => 'seasons', 'nulo' => true],
                    'label'       => ['tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
                    'description' => ['tipo' => 'texto', 'max' => 400, 'nulo' => true],
                    'icon'        => ['tipo' => 'texto', 'max' => 48, 'nulo' => true],
                    'target'      => ['tipo' => 'int', 'min' => 1, 'max' => 999999, 'padrao' => 1],
                    'xp'          => ['tipo' => 'int', 'min' => 0, 'max' => 9999999, 'padrao' => 0],
                    'credits'     => ['tipo' => 'int', 'min' => 0, 'max' => 9999999, 'padrao' => 0],
                    'highlight'   => ['tipo' => 'bool', 'padrao' => 0],
                    'sort_order'  => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'padrao' => 0],
                ],
                'dependentes' => [
                    ['tabela' => 'player_challenges', 'coluna' => 'challenge_id', 'rotulo' => 'progresso de jogadores'],
                ],
            ],

            'news' => [
                'tabela'  => 'news',
                'chave'   => 'id',
                'ordem'   => 'sort_order ASC, id DESC',
                'busca'   => ['slug', 'title', 'excerpt'],
                'filtros' => ['category' => 'category'],
                'campos'  => [
                    'slug'       => ['tipo' => 'slug', 'max' => 80, 'obrigatorio' => true, 'unico' => true],
                    'title'      => ['tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
                    'category'   => ['tipo' => 'texto', 'max' => 24, 'nulo' => true],
                    'excerpt'    => ['tipo' => 'texto', 'max' => 400, 'nulo' => true],
                    // `body` é TEXT no banco; o teto de 20000 é da API, para o corpo de uma
                    // notícia não virar um caminho de despejo de dados na tabela.
                    'body'       => ['tipo' => 'texto', 'max' => 20000, 'nulo' => true],
                    'image'      => ['tipo' => 'texto', 'max' => 160, 'nulo' => true],
                    'published'  => ['tipo' => 'data', 'nulo' => true],
                    'sort_order' => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'padrao' => 0],
                ],
            ],

            'notices' => [
                'tabela'  => 'system_notices',
                'chave'   => 'id',
                'ordem'   => 'sort_order ASC, id DESC',
                'busca'   => ['title', 'body'],
                'filtros' => ['color' => 'color'],
                'saida_extra' => ['created_at' => 'created_at'],
                'campos'  => [
                    // Aviso para UM jogador (id) ou para todos (nulo). É a única coleção de
                    // conteúdo que aponta para uma conta, e por isso o id é conferido.
                    'user_id'    => ['tipo' => 'ref', 'tabela' => 'users', 'nulo' => true],
                    'icon'       => ['tipo' => 'texto', 'max' => 48, 'padrao' => 'campaign'],
                    // Estrutural: vira classe de CSS na HUD (adm/hud conhecem estas quatro).
                    'color'      => ['tipo' => 'enum', 'opcoes' => ['lime', 'gold', 'amber', 'red'], 'padrao' => 'lime'],
                    'title'      => ['tipo' => 'texto', 'max' => 120, 'obrigatorio' => true],
                    'body'       => ['tipo' => 'texto', 'max' => 400, 'obrigatorio' => true],
                    'cta_label'  => ['tipo' => 'texto', 'max' => 40, 'nulo' => true],
                    'cta_href'   => ['tipo' => 'texto', 'max' => 120, 'nulo' => true],
                    'fresh'      => ['tipo' => 'bool', 'padrao' => 1],
                    'sort_order' => ['tipo' => 'int', 'min' => 0, 'max' => 65535, 'padrao' => 0],
                ],
            ],
        ];
    }
}
