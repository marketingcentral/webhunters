<?php declare(strict_types=1);

/**
 * Tela PERFIL do jogador (layout hud).
 *
 * Chaves consumidas pela view `app/Views/pages/perfil.php`:
 *   title, portrait, joined, playtime, faction, faction_rank, reputation,
 *   operator (cartão do operador), stats_title/stats (grade de estatísticas),
 *   achievements_title/achievements (3 hexágonos), weapons_title/weapons,
 *   classes_title/classes, recent_title/recent, badges_title/badges, actions.
 */
return [
    'title' => 'PERFIL',
    'portrait' => 'portraits/op-assault.jpg',
    'joined' => '14/02/2024',
    'playtime' => '187h 42m',
    'faction' => 'HUNTER COMMAND',
    'faction_rank' => 'RECRUTA',
    'reputation' => 34,

    /* --- cartão do operador (coluna esquerda) --------------------------- */
    'operator' => [
        'title' => 'OPERADOR',
        'class' => 'ASSAULT',
        'callsign' => 'INDICATIVO',
        'status' => 'EM PRONTIDÃO',
        'squad' => 'ESQUADRÃO LOBO CINZENTO',
        'session_seconds' => 2712,
        'meta' => [
            ['label' => 'ORGANIZAÇÃO',        'value' => 'HUNTER COMMAND', 'icon' => 'handshake', 'color' => 'lime'],
            ['label' => 'PATENTE',       'value' => 'RECRUTA', 'icon' => 'military_tech', 'color' => 'gold'],
            ['label' => 'TEMPO DE JOGO', 'value' => '187h 42m', 'icon' => 'schedule', 'color' => ''],
            ['label' => 'EM CAMPO DESDE','value' => '14/02/2024', 'icon' => 'flag', 'color' => ''],
        ],
    ],

    /* --- estatísticas (centro, números em mono) -------------------------
       'metric' liga a célula a um valor real do banco (player_matches), preenchido
       no ProfileController: kd, extractions, matches, wins, kills. As células sem
       'metric' (precisão, revividos, distância) não têm origem no schema e ficam
       como layout. stats_meta é derivado da temporada do jogador. */
    'stats_title' => 'ESTATÍSTICAS',
    'stats_meta' => 'TEMPORADA 04',
    'stats' => [
        ['label' => 'K/D',              'n' => 2.4,  'dec' => 1, 'sub' => '+0,3 NA TEMPORADA', 'icon' => 'swords',           'color' => 'lime', 'hot' => true, 'metric' => 'kd'],
        ['label' => 'EXTRAÇÕES',        'n' => 268,  'sub' => '65% DE SUCESSO',                'icon' => 'flag',             'color' => 'lime', 'hot' => true, 'metric' => 'extractions'],
        ['label' => 'PRECISÃO',         'n' => 41,   'suffix' => '%', 'sub' => '533 NA CABEÇA','icon' => 'my_location',      'color' => 'cyan'],
        ['label' => 'PARTIDAS',         'n' => 412,  'sub' => '187h 42m EM CAMPO',             'icon' => 'sports_esports',   'color' => '', 'metric' => 'matches'],
        ['label' => 'VITÓRIAS',         'n' => 187,  'sub' => '45% DAS PARTIDAS',              'icon' => 'military_tech',    'color' => 'gold', 'metric' => 'wins'],
        ['label' => 'ELIMINAÇÕES',      'n' => 1873, 'sub' => '4,5 POR PARTIDA',               'icon' => 'skull',            'color' => '', 'metric' => 'kills'],
        ['label' => 'ALIADOS REVIVIDOS','n' => 96,   'sub' => 'SUPORTE DE CAMPO',              'icon' => 'medical_services', 'color' => ''],
        ['label' => 'DISTÂNCIA',        'n' => 1284, 'unit' => 'km', 'sub' => 'TERRITÓRIO PERCORRIDO', 'icon' => 'explore',  'color' => ''],
    ],

    /* --- conquistas recentes (3 hexágonos) ------------------------------ */
    'achievements_title' => 'CONQUISTAS RECENTES',
    'achievements_action' => 'VER TODAS',
    'achievements' => [
        ['slug' => 'sinal-perdido',      'label' => 'SINAL PERDIDO',      'desc' => 'Restaure a comunicação do posto norte.', 'icon' => 'cell_tower',  'color' => 'lime', 'date' => '12/05/2024', 'xp' => 6500],
        ['slug' => 'cacador-experiente', 'label' => 'CAÇADOR EXPERIENTE', 'desc' => 'Elimine 100 inimigos em campanha.',               'icon' => 'skull',      'color' => 'amber', 'date' => '10/05/2024', 'xp' => 4200],
        ['slug' => 'sobrevivente',       'label' => 'SOBREVIVENTE',       'desc' => 'Sobreviva 10 dias em uma única campanha.',        'icon' => 'backpack',   'color' => 'gold', 'date' => '08/05/2024', 'xp' => 3800],
    ],

    /* --- armas favoritas ------------------------------------------------ */
    'weapons_title' => 'ARMAS FAVORITAS',
    'weapons_action' => 'INVENTÁRIO',
    'weapons' => [
        [
            'slug' => 'ak103', 'name' => 'AK-103', 'type' => 'FUZIL DE ASSALTO', 'img' => 'items/ak103.png',
            'caliber' => '7.62x39', 'kills' => 1042, 'accuracy' => 43, 'usage' => 62, 'main' => true,
        ],
        [
            'slug' => 'm4a1', 'name' => 'M4A1', 'type' => 'CARABINA', 'img' => 'items/m4a1.png',
            'caliber' => '5.56x45', 'kills' => 618, 'accuracy' => 38, 'usage' => 27, 'main' => false,
        ],
    ],

    /* --- classes (coluna direita) --------------------------------------- */
    'classes_title' => 'FUNÇÕES HUNTER',
    'classes' => [
        ['slug' => 'assault', 'label' => 'ASSAULT',         'icon' => 'class-assault', 'level' => 23, 'pct' => 46, 'main' => true],
        ['slug' => 'scout',   'label' => 'RASTREADOR',         'icon' => 'class-scout',   'level' => 14, 'pct' => 24, 'main' => false],
        ['slug' => 'support', 'label' => 'MEDICAL',         'icon' => 'class-support', 'level' => 11, 'pct' => 18, 'main' => false],
        ['slug' => 'sniper',  'label' => 'SNIPER', 'icon' => 'class-sniper',  'level' => 8,  'pct' => 12, 'main' => false],
    ],

    /* --- atividade recente ---------------------------------------------- */
    'recent_title' => 'ATIVIDADE RECENTE',
    'recent' => [
        ['type' => 'achievement', 'icon' => 'military_tech',  'text' => 'Conquista desbloqueada: SINAL PERDIDO',       'time' => '12/05/2024 16:42', 'color' => 'gold'],
        ['type' => 'extraction',  'icon' => 'flag',           'text' => 'Extração bem-sucedida na Floresta Verde',     'time' => '12/05/2024 16:40', 'color' => 'lime'],
        ['type' => 'level',       'icon' => 'star',           'text' => 'Nível 23 alcançado',                          'time' => '11/05/2024 22:18', 'color' => 'lime'],
        ['type' => 'purchase',    'icon' => 'shopping_cart',  'text' => 'Comprou CAPACETE FAST MT',                    'time' => '10/05/2024 19:05', 'color' => 'cyan'],
        ['type' => 'death',       'icon' => 'skull',          'text' => 'Eliminado por Tenente_Kz no Porto Industrial','time' => '09/05/2024 21:33', 'color' => 'red'],
    ],

    /* --- insígnias ------------------------------------------------------ */
    'badges_title' => 'INSÍGNIAS',
    'badges' => [
        ['slug' => 'veterano',   'label' => 'VETERANO',   'icon' => 'military_tech',    'rarity' => 'gold'],
        ['slug' => 'cacador',    'label' => 'CAÇADOR',    'icon' => 'skull',            'rarity' => 'lime'],
        ['slug' => 'explorador', 'label' => 'EXPLORADOR', 'icon' => 'explore',          'rarity' => 'cyan'],
        ['slug' => 'medico',     'label' => 'MÉDICO',     'icon' => 'medical_services', 'rarity' => 'lime'],
    ],

    'actions' => ['edit' => 'EDITAR PERFIL', 'settings' => 'CONFIGURAÇÕES DA CONTA'],
];
