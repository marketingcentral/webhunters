<?php declare(strict_types=1);

/**
 * Lista de mapas multiplayer (grade 4x2; página /mapas).
 *
 * Consumido por:
 *   - PlayController@index  -> $data['maps'] da tela `jogar` (usa slug, name, mode, players, image, icon, selected)
 *   - PlayController@maps   -> tela `mapas` (usa tudo)
 *
 * NÃO remover as chaves usadas pela tela `jogar`.
 */
return [
    'title'    => 'MAPAS',
    'tag'      => 'TEMPORADA 3',
    'intro'    => 'Todos os campos de batalha disponíveis nesta temporada.',

    'grid_title'  => 'SELEÇÃO DE MAPAS',
    'detail_title' => 'INFORMAÇÕES DO MAPA',
    'cta'         => 'JOGAR NESTE MAPA',
    'cta_href'    => 'jogar',
    'alt_cta'     => 'MAPA TÁTICO',
    'alt_href'    => 'mapa',
    'filters_all' => 'TODOS',

    /* rotação da lista de mapas — contagem regressiva ao vivo (segundos) */
    'rotation_label' => 'ROTAÇÃO DE MAPAS EM',
    'rotation_secs'  => 45903,

    /* rótulos do painel de detalhe */
    'labels' => [
        'size'    => 'TAMANHO',
        'biome'   => 'BIOMA',
        'weather' => 'CLIMA',
        'best'    => 'MELHOR TEMPO',
        'record'  => 'RECORDE DO SERVIDOR',
        'yours'   => 'SEU MELHOR',
        'empty'   => 'NENHUM MAPA NESTE MODO',
    ],

    'items' => [
        [
            'slug' => 'vale-do-eco', 'name' => 'VALE DO ECO', 'mode' => 'MATA-MATA EM EQUIPE', 'players' => '6v6',
            'image' => 'maps/vale-do-eco.jpg', 'icon' => 'emblem-hex', 'selected' => true,
            'size' => 'MÉDIO', 'biome' => 'Floresta / Montanha', 'weather' => 'Nublado',
            'best' => '04:12', 'best_by' => 'V1P3R_88', 'your_best' => '05:47',
            'desc' => 'Vale florestado entre encostas íngremes, com torres de vigia e um heliporto central. Rotas longas favorecem duelos de média distância.',
        ],
        [
            'slug' => 'base-artica', 'name' => 'BASE ÁRTICA', 'mode' => 'DOMINAÇÃO', 'players' => '6v6',
            'image' => 'maps/base-artica.jpg', 'icon' => 'mk-base', 'selected' => false,
            'size' => 'GRANDE', 'biome' => 'Neve / Tundra', 'weather' => 'Nevasca',
            'best' => '06:38', 'best_by' => 'KOLD_RUN', 'your_best' => '08:02',
            'desc' => 'Complexo de pesquisa soterrado pela neve. Corredores fechados ligam os hangares e a nevasca cega quem cruza o pátio.',
        ],
        [
            'slug' => 'porto-industrial', 'name' => 'PORTO INDUSTRIAL', 'mode' => 'ZONA DE CONFLITO', 'players' => '6v6',
            'image' => 'maps/porto-industrial.jpg', 'icon' => 'emblem-hex', 'selected' => false,
            'size' => 'MÉDIO', 'biome' => 'Portuário', 'weather' => 'Chuva leve',
            'best' => '05:03', 'best_by' => 'DOCA_9', 'your_best' => '06:21',
            'desc' => 'Pátio de contêineres e guindastes sobre o cais. A verticalidade das pilhas cria ninhos de atirador e emboscadas curtas.',
        ],
        [
            'slug' => 'cidade-em-ruinas', 'name' => 'CIDADE EM RUÍNAS', 'mode' => 'MATA-MATA EM EQUIPE', 'players' => '6v6',
            'image' => 'maps/cidade-em-ruinas.jpg', 'icon' => 'emblem-hex', 'selected' => false,
            'size' => 'GRANDE', 'biome' => 'Urbano', 'weather' => 'Limpo',
            'best' => '07:15', 'best_by' => 'ASH_TOWER', 'your_best' => '07:58',
            'desc' => 'Quarteirões arrasados pelo bombardeio. Prédios sem fachada abrem linhas de tiro cruzadas e o subsolo liga os dois lados.',
        ],
        [
            'slug' => 'fabrica-abandonada', 'name' => 'FÁBRICA ABANDONADA', 'mode' => 'DOMINAÇÃO', 'players' => '6v6',
            'image' => 'maps/fabrica-abandonada.jpg', 'icon' => 'mk-base', 'selected' => false,
            'size' => 'PEQUENO', 'biome' => 'Industrial', 'weather' => 'Neblina',
            'best' => '03:47', 'best_by' => 'HUNTER_07', 'your_best' => '03:47',
            'desc' => 'Galpões de metalurgia tomados por ferrugem e neblina. Combate rápido entre esteiras, fornos e passarelas suspensas.',
        ],
        [
            'slug' => 'deserto-seco', 'name' => 'DESERTO SECO', 'mode' => 'ZONA DE CONFLITO', 'players' => '6v6',
            'image' => 'maps/deserto-seco.jpg', 'icon' => 'emblem-hex', 'selected' => false,
            'size' => 'GRANDE', 'biome' => 'Deserto', 'weather' => 'Tempestade de areia',
            'best' => '06:59', 'best_by' => 'SAND_KING', 'your_best' => '09:12',
            'desc' => 'Aldeia de barro cercada por dunas e rochas. Sem cobertura no descampado, a tempestade de areia é o único avanço seguro.',
        ],
        [
            'slug' => 'favela', 'name' => 'FAVELA', 'mode' => 'MATA-MATA EM EQUIPE', 'players' => '6v6',
            'image' => 'maps/favela.jpg', 'icon' => 'emblem-hex', 'selected' => false,
            'size' => 'MÉDIO', 'biome' => 'Urbano denso', 'weather' => 'Limpo',
            'best' => '04:40', 'best_by' => 'LAJE_MAX', 'your_best' => '05:05',
            'desc' => 'Encosta densa de becos, lajes e escadarias. Quem controla os telhados controla a rota de extração.',
        ],
        [
            'slug' => 'complexo-militar', 'name' => 'COMPLEXO MILITAR', 'mode' => 'DOMINAÇÃO', 'players' => '6v6',
            'image' => 'maps/complexo-militar.jpg', 'icon' => 'mk-base', 'selected' => false,
            'size' => 'GRANDE', 'biome' => 'Base militar', 'weather' => 'Nublado',
            'best' => '08:21', 'best_by' => 'ECHO_ZERO', 'your_best' => '10:34',
            'desc' => 'Instalação de comando com radar, bunkers e pista de pouso. Objetivos distantes exigem rotação constante da equipe.',
        ],
    ],

    'modes' => ['MATA-MATA EM EQUIPE', 'DOMINAÇÃO', 'ZONA DE CONFLITO'],

    /* modo -> símbolo local do hexágono (definido na view; ids prefixados mp-) */
    'mode_icons' => [
        'MATA-MATA EM EQUIPE' => 'mp-skull',
        'DOMINAÇÃO'           => 'mp-shield',
        'ZONA DE CONFLITO'    => 'mp-target',
    ],
];
