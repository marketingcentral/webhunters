<?php declare(strict_types=1);

/** Tela JOGAR SOLO / MULTIPLAYER (referência 02). Mapas vêm de mapas.php; desafios de desafios.php. */
return [
    'title'    => 'JOGAR SOLO',
    'subtitle' => 'MULTIPLAYER',
    'intro'    => 'Entre em uma partida rápida e lute contra outros jogadores.',
    'modes_title' => 'MODO DE JOGO',
    'modes' => [
        ['slug' => 'partida-rapida',     'icon' => 'jg-quick', 'icon_type' => 'svg', 'title' => 'PARTIDA RÁPIDA',     'desc' => 'Mapa e modo aleatório.',            'locked' => false, 'active' => true],
        ['slug' => 'mata-mata',          'icon' => 'jg-team',  'icon_type' => 'svg', 'title' => 'MATA-MATA EM EQUIPE', 'desc' => '6v6 - Elimine a equipe inimiga.',   'locked' => true,  'active' => false],
        ['slug' => 'dominacao',          'icon' => 'jg-dom',   'icon_type' => 'svg', 'title' => 'DOMINAÇÃO',           'desc' => 'Capture e defenda os objetivos.',   'locked' => true,  'active' => false],
        ['slug' => 'zona-de-conflito',   'icon' => 'jg-zone',  'icon_type' => 'svg', 'title' => 'ZONA DE CONFLITO',    'desc' => 'Controle a zona e ganhe pontos.',   'locked' => true,  'active' => false],
        ['slug' => 'caca',               'icon' => 'jg-hunt',  'icon_type' => 'svg', 'title' => 'CAÇA',                'desc' => 'Elimine alvos e complete contratos.', 'locked' => true, 'active' => false],
    ],
    'banner' => [
        'title' => 'PARTIDA RÁPIDA',
        'desc'  => 'Entre em uma partida rápida com jogadores de todo o mundo.',
        'image' => 'bg/jogar-banner.jpg',
        'icon'  => 'emblem-hex',
    ],
    'maps_title'  => 'SELEÇÃO DE MAPAS',
    'maps_action' => 'VER TODOS OS MAPAS',
    /** Símbolo local (defs de pages/jogar.php) dentro do hexágono de cada card, por modo de jogo. */
    'mode_icons' => [
        'MATA-MATA EM EQUIPE' => 'jg-team',
        'DOMINAÇÃO'           => 'jg-hx-shield',
        'ZONA DE CONFLITO'    => 'jg-hx-target',
    ],
    'prefs_title' => 'PREFERÊNCIAS DE PARTIDA',
    'prefs' => [
        ['key' => 'fill',      'label' => 'PREENCHIMENTO DE EQUIPE', 'type' => 'toggle', 'value' => true,  'text' => 'Ativado'],
        ['key' => 'crossplay', 'label' => 'CROSSPLAY',               'type' => 'toggle', 'value' => true,  'text' => 'Ativado'],
        ['key' => 'region',    'label' => 'REGIÃO DE BUSCA',         'type' => 'select', 'value' => 'sa',  'text' => 'América do Sul (32ms)',
         'options' => ['América do Sul (32ms)', 'América do Norte (86ms)', 'Europa (154ms)', 'Ásia (212ms)']],
        ['key' => 'sbmm',      'label' => 'FILTRO DE HABILIDADE',    'type' => 'toggle', 'value' => true,  'text' => 'Ativado'],
    ],
    'cta'       => 'BUSCAR PARTIDA',
    'eta_label' => 'TEMPO ESTIMADO:',
    'eta'       => '< 30s',
    'squad' => [
        'title'      => 'ESQUADRÃO',
        'count'      => 1,
        'max'        => 4,
        'add_label'  => 'CONVIDAR JOGADOR',
        'members' => [
            ['name' => 'HUNTER_07', 'level' => 23, 'avatar' => 'portraits/op-assault.jpg', 'leader' => true, 'online' => true, 'self' => true],
        ],
    ],
    'challenges_title'  => 'DESAFIOS DIÁRIOS',
    'challenges_reset'  => 'REINICIA EM:',
    'challenges_action' => 'VER TODOS OS DESAFIOS',
    'daily_reward' => [
        'title'      => 'RECOMPENSA DIÁRIA',
        'next_label' => 'PRÓXIMA RECOMPENSA EM:',
        'next_in'    => '12h 45m',
        'name'       => 'Caixa de Suprimentos',
        'desc'       => 'Contém itens e recursos aleatórios.',
        'image'      => 'items/supply-crate.png',
        'cta'        => 'RESGATAR RECOMPENSA',
        'available'  => true,
    ],
];
