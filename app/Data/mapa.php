<?php declare(strict_types=1);

/**
 * Tela MAPA DO MUNDO (referência 08-mapas.png) — cromo e fallback sem banco.
 *
 * PRD v3 §23: o mapa responde "o que está acontecendo neste mundo persistente agora?".
 * Regiões e marcadores reais vêm do banco (`maps` kind=region, `map_markers`); o que fica
 * aqui são rótulos, legenda, camadas (filtros), controles e o fallback quando não há banco.
 *
 * Posições dos marcadores em % da área do mapa (x = % da largura, y = % da altura),
 * medidas sobre a referência 1672x941 (área do mapa: x 318..1288, y 83..883).
 *
 * marker.glyph  -> id do glifo desenhado em app/Views/pages/mapa.php ($GLYPHS)
 * marker.color  -> lime | red | white | purple | cyan | amber | muted
 * marker.label  -> false = sem rótulo | 'below' | 'right'
 * marker.kind   -> chave de map_markers.kind (define a camada do filtro — ver MapController::LAYER_OF)
 */
return [
    'title'         => 'MAPA DO MUNDO',
    'regions_title' => 'REGIÕES',
    'map_image'     => 'bg/map-island-hud.jpg',
    'map_alt'       => 'Mapa do mundo — ilha da Floresta Verde vista de cima',
    'level_label'   => 'NÍVEL RECOMENDADO:',
    'regions' => [
        ['slug' => 'floresta-verde',     'name' => 'FLORESTA VERDE',     'level' => '1-10',  'icon' => 'pine',      'locked' => false, 'image' => 'regions/region-floresta-verde.jpg',
            'desc' => 'Uma vasta floresta temperada onde a Veyra refez rios e trilhas. Ato I da campanha: uma estação militar silenciou e há interferência humana no sinal.',
            'info' => [
                ['label' => 'NÍVEL RECOMENDADO',      'value' => '1-10', 'cls' => 'lime'],
                ['label' => 'CAPACIDADE OPERACIONAL', 'value' => '20', 'tip' => 'Até 12 Hunters e 8 Mercenários. Sobreviventes ocupam vagas existentes.'],
                ['label' => 'CLIMA ATUAL',            'value' => 'Variável', 'icon' => 'partly_cloudy_day'],
                ['label' => 'MUNDO',                  'value' => 'PERSISTENTE', 'cls' => 'lime'],
            ],
            'extractions' => [
                ['name' => 'PLATAFORMA NORTE',              'type' => 'governamental', 'status' => 'ATIVA',       'state' => 'active'],
                ['name' => 'ROTA SUL',                      'type' => 'publica',       'status' => 'ATIVA',       'state' => 'active'],
                ['name' => 'TRANSPORTE AEGIS · PONTE LESTE','type' => 'aegis',         'status' => 'CONDICIONAL', 'state' => 'conditional'],
                ['name' => 'ROTA FRONTIER · RIO OESTE',     'type' => 'frontier',      'status' => 'INATIVA',     'state' => 'inactive'],
            ],
            'event' => ['name' => 'ENTREGA AÉREA', 'ends_label' => 'TERMINA EM', 'ends_in' => '12:34'],
            'state' => ['persistent' => true, 'capacity' => ['total' => 20, 'hunters' => 12, 'mercenaries' => 8], 'infestation' => 42, 'veyra_activity' => 'MODERADA', 'human_activity' => 'ELEVADA', 'weather' => 'Variável', 'sector_state' => 'ATIVO'],
        ],
        ['slug' => 'pantano-cinzento',   'name' => 'PÂNTANO CINZENTO',   'level' => '10-20', 'icon' => 'swamp',     'locked' => true, 'image' => 'regions/region-pantano-cinzento.jpg',
            'desc' => 'Águas paradas, esporos e túneis. As colônias daqui compartilham respostas: o que funcionou uma vez não funciona duas.',
            'info' => [
                ['label' => 'NÍVEL RECOMENDADO',      'value' => '10-20', 'cls' => 'lime'],
                ['label' => 'CAPACIDADE OPERACIONAL', 'value' => '20', 'tip' => 'Até 12 Hunters e 8 Mercenários.'],
                ['label' => 'CLIMA ATUAL',            'value' => 'Neblina', 'icon' => 'foggy'],
                ['label' => 'MUNDO',                  'value' => 'PERSISTENTE', 'cls' => 'lime'],
            ],
            'extractions' => [
                ['name' => 'PLATAFORMA NORTE',  'type' => 'governamental', 'status' => 'ATIVA',       'state' => 'active'],
                ['name' => 'BALSA DO PÂNTANO',  'type' => 'frontier',      'status' => 'CONDICIONAL', 'state' => 'conditional'],
                ['name' => 'ROTA SUL',          'type' => 'publica',       'status' => 'INATIVA',     'state' => 'inactive'],
            ],
            'event' => null,
            'state' => ['persistent' => true, 'capacity' => ['total' => 20, 'hunters' => 12, 'mercenaries' => 8], 'infestation' => 58, 'veyra_activity' => 'ELEVADA', 'human_activity' => 'MODERADA', 'weather' => 'Neblina', 'sector_state' => 'ATIVO'],
        ],
        ['slug' => 'montanhas-gelidas',  'name' => 'MONTANHAS GÉLIDAS',  'level' => '20-30', 'icon' => 'mountain',  'locked' => true, 'image' => 'regions/region-montanhas-gelidas.jpg',
            'desc' => 'Picos congelados e uma instalação Axiom sob a neve. Ato II: os Arquivos Vesper exigem um contrato de facção para avançar.',
            'info' => [
                ['label' => 'NÍVEL RECOMENDADO',      'value' => '20-30', 'cls' => 'lime'],
                ['label' => 'CAPACIDADE OPERACIONAL', 'value' => '20', 'tip' => 'Até 12 Hunters e 8 Mercenários.'],
                ['label' => 'CLIMA ATUAL',            'value' => 'Nevasca', 'icon' => 'weather_snowy'],
                ['label' => 'MUNDO',                  'value' => 'PERSISTENTE', 'cls' => 'lime'],
            ],
            'extractions' => [
                ['name' => 'TELEFÉRICO DO PICO', 'type' => 'aegis',         'status' => 'ATIVA',       'state' => 'active'],
                ['name' => 'HELIPONTO LESTE',    'type' => 'governamental', 'status' => 'CONDICIONAL', 'state' => 'conditional'],
            ],
            'event' => null,
            'state' => ['persistent' => true, 'capacity' => ['total' => 20, 'hunters' => 12, 'mercenaries' => 8], 'infestation' => 35, 'veyra_activity' => 'BAIXA', 'human_activity' => 'ELEVADA', 'weather' => 'Nevasca', 'sector_state' => 'ATIVO'],
        ],
        ['slug' => 'deserto-abandonado', 'name' => 'DESERTO ABANDONADO', 'level' => '30-40', 'icon' => 'cactus',    'locked' => true, 'image' => 'regions/region-deserto-abandonado.jpg',
            'desc' => 'Dunas, poços e combustível. Ato III: Aegis e Frontier travam uma guerra econômica e cada facção tem só metade da verdade.',
            'info' => [
                ['label' => 'NÍVEL RECOMENDADO',      'value' => '30-40', 'cls' => 'lime'],
                ['label' => 'CAPACIDADE OPERACIONAL', 'value' => '20', 'tip' => 'Até 12 Hunters e 8 Mercenários.'],
                ['label' => 'CLIMA ATUAL',            'value' => 'Tempestade', 'icon' => 'air'],
                ['label' => 'MUNDO',                  'value' => 'PERSISTENTE', 'cls' => 'lime'],
            ],
            'extractions' => [
                ['name' => 'OÁSIS DO NORTE',   'type' => 'publica', 'status' => 'ATIVA',   'state' => 'active'],
                ['name' => 'COMBOIO DE CARGA', 'type' => 'aegis',   'status' => 'INATIVA', 'state' => 'inactive'],
            ],
            'event' => null,
            'state' => ['persistent' => true, 'capacity' => ['total' => 20, 'hunters' => 12, 'mercenaries' => 8], 'infestation' => 27, 'veyra_activity' => 'BAIXA', 'human_activity' => 'CRÍTICA', 'weather' => 'Tempestade', 'sector_state' => 'DISPUTADO'],
        ],
        ['slug' => 'zona-industrial',    'name' => 'ZONA INDUSTRIAL',    'level' => '40+',   'icon' => 'factory',   'locked' => true, 'image' => 'regions/region-zona-industrial.jpg',
            'desc' => 'Fábricas, depósitos e o Núcleo Negro. Ato IV: Operação BLACK VEIL, o maior Surto da campanha.',
            'info' => [
                ['label' => 'NÍVEL RECOMENDADO',      'value' => '40+', 'cls' => 'lime'],
                ['label' => 'CAPACIDADE OPERACIONAL', 'value' => '20', 'tip' => 'Até 12 Hunters e 8 Mercenários.'],
                ['label' => 'CLIMA ATUAL',            'value' => 'Chuva ácida', 'icon' => 'rainy'],
                ['label' => 'MUNDO',                  'value' => 'PERSISTENTE', 'cls' => 'lime'],
            ],
            'extractions' => [
                ['name' => 'TÚNEL DE CARGA',    'type' => 'frontier',      'status' => 'CONDICIONAL', 'state' => 'conditional'],
                ['name' => 'PÁTIO FERROVIÁRIO', 'type' => 'governamental', 'status' => 'INATIVA',     'state' => 'inactive'],
            ],
            'event' => null,
            'state' => ['persistent' => true, 'capacity' => ['total' => 20, 'hunters' => 12, 'mercenaries' => 8], 'infestation' => 88, 'veyra_activity' => 'CRÍTICA', 'human_activity' => 'ELEVADA', 'weather' => 'Chuva ácida', 'sector_state' => 'SURTO'],
        ],
    ],

    /* --- camadas (filtros) — chips, sem painel novo (PRD §23.9) ------------- */
    'filters_title' => 'CAMADAS',
    'filters' => [
        ['slug' => 'todos',      'label' => 'TODOS'],
        ['slug' => 'hunter',     'label' => 'HUNTER'],
        ['slug' => 'faccoes',    'label' => 'FACÇÕES'],
        ['slug' => 'veyra',      'label' => 'VEYRA'],
        ['slug' => 'bases',      'label' => 'BASES'],
        ['slug' => 'extracoes',  'label' => 'EXTRAÇÕES'],
        ['slug' => 'eventos',    'label' => 'EVENTOS'],
    ],

    /* --- legenda (PRD §23.4–§23.8) ------------------------------------------- */
    'legend_title' => 'LEGENDA',
    'legend' => [
        // duas colunas na view: rótulos curtos
        ['glyph' => 'extraction',   'label' => 'EXTRAÇÃO',        'color' => 'lime'],
        ['glyph' => 'gov-base',     'label' => 'BASE HUNTER',     'color' => 'lime'],
        ['glyph' => 'base',         'label' => 'POSTO AVANÇADO',  'color' => 'lime'],
        ['glyph' => 'aegis',        'label' => 'AEGIS',           'color' => 'cyan'],
        ['glyph' => 'frontier',     'label' => 'FRONTIER',        'color' => 'amber'],
        ['glyph' => 'safe-zone',    'label' => 'SAFE ZONE',       'color' => 'lime'],
        ['glyph' => 'field-base',   'label' => 'BASE DE CAMPO',   'color' => 'white'],
        ['glyph' => 'nest',         'label' => 'NINHO VEYRA',     'color' => 'red'],
        ['glyph' => 'contaminated', 'label' => 'CONTAMINAÇÃO',    'color' => 'purple'],
        ['glyph' => 'human',        'label' => 'ATIV. HUMANA',    'color' => 'amber'],
        ['glyph' => 'convoy',       'label' => 'LOGÍSTICA',       'color' => 'cyan'],
        ['glyph' => 'event',        'label' => 'EVENTO',          'color' => 'purple'],
        ['glyph' => 'loot',         'label' => 'RECURSOS',        'color' => 'white'],
        ['glyph' => 'risk',         'label' => 'ZONA DE RISCO',   'color' => 'red'],
    ],
    'legend_toggle' => ['label' => 'OCULTAR LEGENDA', 'label_off' => 'MOSTRAR LEGENDA', 'key' => 'X'],
    'controls' => [
        ['action' => 'zoom-in',  'icon' => 'add',         'label' => 'Aproximar o mapa'],
        ['action' => 'zoom-out', 'icon' => 'remove',      'label' => 'Afastar o mapa'],
        ['action' => 'center',   'icon' => 'my_location', 'label' => 'Centralizar o mapa'],
    ],
    'compass' => ['n' => 'N', 'label' => 'Rosa dos ventos — norte para cima'],

    /* --- marcadores (fallback sem banco; os reais vêm de map_markers) ------- */
    'markers' => [
        'floresta-verde' => [
            ['id' => 'acampamento-lenhador',   'kind' => 'camp',       'glyph' => 'camp',       'color' => 'lime',   'name' => 'ACAMPAMENTO LENHADOR',      'x' => 35.8, 'y' => 11.0, 'label' => 'below'],
            ['id' => 'ninho-norte',            'kind' => 'nest',       'glyph' => 'nest',       'color' => 'red',    'name' => 'NINHO · BURROWERS',         'x' => 67.7, 'y' => 11.9, 'label' => 'right', 'narrow' => true, 'pulse' => true,
                'risk' => ['x' => 68.6, 'y' => 14.2, 'rx' => 8.4]],
            ['id' => 'saque-norte',            'kind' => 'loot',       'glyph' => 'loot',       'color' => 'white',  'name' => 'ÁREA DE RECURSOS',          'x' => 51.8, 'y' => 20.5, 'label' => false],
            ['id' => 'posto-avancado-norte',   'kind' => 'base',       'glyph' => 'base',       'color' => 'lime',   'name' => 'POSTO AVANÇADO NORTE',      'x' => 15.5, 'y' => 31.1, 'label' => 'below'],
            ['id' => 'evento-entrega-aerea',   'kind' => 'event',      'glyph' => 'event',      'color' => 'purple', 'name' => 'EVENTO DE MUNDO',           'x' => 33.0, 'y' => 31.0, 'label' => false, 'pulse' => true],
            ['id' => 'base-vigia',             'kind' => 'gov_base',   'glyph' => 'gov-base',   'color' => 'lime',   'name' => 'BASE VIGIA · HUNTER COMMAND','x' => 45.4, 'y' => 41.5, 'label' => 'right', 'status' => 'OPERACIONAL'],
            ['id' => 'estacao-de-radio',       'kind' => 'radio',      'glyph' => 'radio',      'color' => 'white',  'name' => 'ESTAÇÃO DE RÁDIO',          'x' => 76.2, 'y' => 39.4, 'label' => 'below'],
            ['id' => 'base-alfa-aegis',        'kind' => 'aegis',      'glyph' => 'aegis',      'color' => 'cyan',   'name' => 'BASE ALFA · AEGIS',         'x' => 80.0, 'y' => 56.0, 'label' => 'below', 'faction' => 'aegis'],
            ['id' => 'acamp-frontier',         'kind' => 'frontier',   'glyph' => 'frontier',   'color' => 'amber',  'name' => 'ACAMPAMENTO DOS CAÇADORES · FRONTIER', 'x' => 15.5, 'y' => 43.0, 'label' => 'below', 'faction' => 'frontier'],
            ['id' => 'tiros-detectados',       'kind' => 'human_activity', 'glyph' => 'human',  'color' => 'amber',  'name' => 'TIROS DETECTADOS',          'x' => 52.0, 'y' => 46.0, 'label' => 'below', 'narrow' => true, 'ttl_min' => 25],
            ['id' => 'vila-abandonada',        'kind' => 'village',    'glyph' => 'village',    'color' => 'white',  'name' => 'VILA ABANDONADA',           'x' => 46.3, 'y' => 68.1, 'label' => 'below'],
            ['id' => 'area-contaminada',       'kind' => 'contaminated','glyph' => 'contaminated','color' => 'purple','name' => 'ÁREA CONTAMINADA · SPORECASTERS', 'x' => 63.0, 'y' => 69.0, 'label' => false],
            ['id' => 'plataforma-de-extracao', 'kind' => 'extraction', 'glyph' => 'extraction', 'color' => 'lime',   'name' => 'PLATAFORMA NORTE',          'x' => 29.2, 'y' => 78.5, 'label' => 'below'],
            ['id' => 'comboio-aegis',          'kind' => 'convoy',     'glyph' => 'convoy',     'color' => 'cyan',   'name' => 'COMBOIO AEGIS · JANELA LOGÍSTICA', 'x' => 74.0, 'y' => 42.0, 'label' => 'below'],
            ['id' => 'base-de-campo',          'kind' => 'field_base', 'glyph' => 'field-base', 'color' => 'muted',  'name' => 'BASE DE CAMPO DESCONHECIDA','x' => 56.0, 'y' => 82.0, 'label' => 'below'],
        ],
    ],

    /* --- coluna direita ------------------------------------------------------ */
    'info_title'         => 'INFORMAÇÕES',
    'state_title'        => 'ESTADO DO SETOR',
    'bases_title'        => 'BASES E INSTALAÇÕES',
    'bases_note'         => 'Estado operacional, sem contagem de vagas.',
    'faction_title'      => 'INSTALAÇÕES DE FACÇÃO',
    'extractions_title'  => 'EXTRAÇÕES DISPONÍVEIS',
    'extractions_blocked_title' => 'EXTRAÇÕES · BLOQUEADAS',
    'event_title'        => 'EVENTO ATIVO',
    'locked_note'        => 'REGIÃO TRAVADA',
    'labels' => [
        'infestation' => 'INFESTAÇÃO VEYRA',
        'veyra'       => 'ATIVIDADE VEYRA',
        'human'       => 'ATIVIDADE HUMANA',
        'weather'     => 'CLIMA',
        'sector'      => 'SETOR',
    ],
    'cta_route'  => ['label' => 'DEFINIR ROTA', 'key' => 'F', 'href' => 'jogar'],
    'cta_mark'   => ['label' => 'MARCAR PONTO', 'label_on' => 'PONTO MARCADO', 'icon' => 'star'],
];
