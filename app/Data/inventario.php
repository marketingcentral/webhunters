<?php declare(strict_types=1);

/**
 * Tela INVENTÁRIO (referência 10-Inventario-game.png).
 *
 * Cores dos contadores (chave `cls`, casa com component('slot', ['color' => ...])):
 *   ''      = lime  (capacidade / consumíveis "verdes": água, corda, munição)
 *   'white' = branco (munição de arma, carregadores, galão, durabilidade de equipamento)
 *   'amber' = laranja (consumíveis: analgésico, bandagem, comida, café)
 *   'red'   = vermelho (kit médico)
 * Imagens em assets/img/items (PNG com alfa) e assets/img/ui/body-wireframe-clean.png
 * (o alfa desse PNG foi refeito por luminância a partir do render original: o
 *  ui/body-wireframe.png antigo tem o canal alfa furado e sai erodido na tela).
 */
return [
    'equipment_title' => 'EQUIPAMENTO',
    'equipment' => [
        'gear' => [
            ['slot' => 'CAPACETE',   'name' => 'FAST MT',  'image' => 'items/helmet-fast.png',       'count' => '45/56',   'cls' => 'white', 'cur' => 45,  'max' => 56],
            ['slot' => 'MÁSCARA',    'name' => 'GP-7',     'image' => 'items/gasmask-gp7.png',       'count' => '100/100', 'cls' => 'white', 'cur' => 100, 'max' => 100],
            ['slot' => 'ÓCULOS',     'name' => 'Aviator',  'image' => 'items/glasses-aviator.png',   'count' => null,      'cls' => 'white'],
            ['slot' => 'COLETE',     'name' => 'VEST-83',  'image' => 'items/vest-83.png',           'count' => '80/80',   'cls' => 'white', 'cur' => 80,  'max' => 80],
            ['slot' => 'ROUPA',      'name' => 'TÁTICA',   'image' => 'items/clothing-tactical.png', 'count' => null,      'cls' => 'white'],
            ['slot' => 'BRAÇADEIRA', 'name' => 'USEC',     'image' => 'items/armband-usec.png',      'count' => null,      'cls' => 'white', 'slot_class' => 'eq-slot--emblem'],
        ],
        'weapons' => [
            [
                'slot' => 'ARMA PRIMÁRIA', 'name' => 'AK-103', 'caliber' => '7.62x39', 'image' => 'items/ak103.png',
                'key' => '02', 'mag' => 30, 'reserve' => 120, 'durability' => null,
                'attachments' => ['items/multitool.png', 'items/tools-wrench.png', 'items/ak-mag.png', 'items/knife-bayonet.png'],
            ],
            [
                'slot' => 'ARMA SECUNDÁRIA', 'name' => 'M4A1', 'caliber' => '5.56x45 NATO', 'image' => 'items/m4a1.png',
                'key' => '03', 'mag' => 30, 'reserve' => 90, 'durability' => null,
                'attachments' => ['items/tools-wrench.png', 'items/knife-bayonet.png', 'items/m4-mag.png', 'items/multitool.png', 'items/flashbang.png'],
            ],
        ],
        'sidearms' => [
            ['slot' => 'PISTOL', 'name' => 'GLOCK 17', 'caliber' => '9x19mm', 'image' => 'items/glock17.png',       'durability' => 94, 'mag' => 17, 'reserve' => 51],
            ['slot' => 'FACA',   'name' => null,       'caliber' => null,     'image' => 'items/knife-bayonet.png', 'durability' => 85, 'mag' => null, 'reserve' => null],
        ],
    ],

    'inventory_title' => 'INVENTÁRIO',
    'containers' => [
        [
            'slug' => 'rig', 'title' => 'COLETE TÁTICO', 'name' => 'RIG-03', 'image' => 'items/tactical-rig.png',
            'used' => 8, 'capacity' => 23, 'count' => '8/23', 'name_right' => false,
            'items' => [
                ['name' => 'AK-103',     'image' => 'items/ak-mag.png',       'count' => '30/30',   'cls' => 'white'],
                ['name' => 'AK-103',     'image' => 'items/ak-mag.png',       'count' => '30/30',   'cls' => 'white'],
                ['name' => 'AK-103',     'image' => 'items/ak-mag.png',       'count' => '30/30',   'cls' => 'white'],
                ['name' => 'M67',        'image' => 'items/m67-grenade.png',  'count' => null,      'cls' => 'white', 'half' => true],
                ['name' => 'FLASH',      'image' => 'items/flashbang.png',    'count' => '2/5',     'cls' => 'white', 'half' => true],
                ['name' => 'ANALGÉSICO', 'image' => 'items/analgesic.png',    'count' => '3/3',     'cls' => 'amber'],
                ['name' => 'BANDAGEM',   'image' => 'items/bandage.png',      'count' => '3/3',     'cls' => 'amber'],
                ['name' => 'KIT MÉDICO', 'image' => 'items/medkit.png',       'count' => '120/300', 'cls' => 'red'],
                ['name' => 'ÁGUA',       'image' => 'items/water-bottle.png', 'count' => '60/60',   'cls' => ''],
            ],
        ],
        [
            'slug' => 'pockets', 'title' => 'BOLSOS', 'name' => null, 'image' => null,
            'used' => 4, 'capacity' => 4, 'count' => null,
            'items' => [
                ['name' => 'RÁDIO',     'image' => 'items/radio.png',     'count' => null, 'cls' => 'white'],
                ['name' => 'MULTITOOL', 'image' => 'items/multitool.png', 'count' => null, 'cls' => 'white'],
                ['name' => 'MUNIÇÃO',   'image' => 'items/ammo-box.png',  'count' => '60', 'cls' => ''],
                // compass-clean: o compass.png original traz uma caixa verde opaca no lugar do alfa
                ['name' => 'BÚSSOLA',   'image' => 'items/compass-clean.png', 'count' => null, 'cls' => 'white'],
            ],
        ],
        [
            'slug' => 'backpack', 'title' => 'MOCHILA', 'name' => 'TRILHA 45L', 'image' => 'items/backpack-45l.png',
            'used' => 10, 'capacity' => 45, 'count' => null, 'name_right' => true,
            'items' => [
                ['name' => 'CORDA',           'image' => 'items/rope.png',          'count' => '5/5',   'cls' => ''],
                ['name' => 'FERRAMENTAS',     'image' => 'items/tools-wrench.png',  'count' => null,    'cls' => 'white'],
                ['name' => 'FERRAMENTAS',     'image' => 'items/multitool.png',     'count' => null,    'cls' => 'white'],
                ['name' => 'GALÃO',           'image' => 'items/jerrycan.png',      'count' => '20/20', 'cls' => 'white', 'tall' => true],
                ['name' => 'LATA',            'image' => 'items/canned-food.png',   'count' => '3/3',   'cls' => 'amber'],
                ['name' => 'CARNE SECA',      'image' => 'items/dried-meat.png',    'count' => '3/3',   'cls' => 'amber'],
                ['name' => 'CHAVE INGLESA',   'image' => 'items/tools-wrench.png',  'count' => null,    'cls' => 'white'],
                ['name' => 'CAFÉ',            'image' => 'items/coffee.png',        'count' => '3/3',   'cls' => 'amber'],
                ['name' => 'BARRA DE CEREAL', 'image' => 'items/cereal-bar.png',    'count' => '2/2',   'cls' => 'amber'],
                ['name' => 'SACO DE DORMIR',  'image' => 'items/sleeping-bag.png',  'count' => null,    'cls' => 'white', 'wide' => true],
            ],
        ],
    ],

    'quick_title' => 'SLOT RÁPIDO',
    'quick' => [
        ['key' => '1', 'name' => 'AK-103',     'image' => 'items/ak103.png',        'count' => null,  'cls' => 'white'],
        ['key' => '2', 'name' => 'GLOCK 17',   'image' => 'items/glock17.png',      'count' => null,  'cls' => 'white'],
        ['key' => '3', 'name' => 'FACA',       'image' => 'items/knife-bayonet.png','count' => null,  'cls' => 'white'],
        ['key' => '4', 'name' => 'KIT MÉDICO', 'image' => 'items/medkit.png',       'count' => '3/3', 'cls' => 'amber'],
        ['key' => '5', 'name' => 'BANDAGEM',   'image' => 'items/bandage.png',      'count' => '3/3', 'cls' => 'amber'],
        ['key' => '6', 'name' => 'M67',        'image' => 'items/m67-grenade.png',  'count' => '2/2', 'cls' => 'amber'],
        ['key' => '7', 'name' => 'ÁGUA',       'image' => 'items/water-bottle.png', 'count' => '2/2', 'cls' => 'amber', 'active' => true],
        ['key' => '9', 'name' => 'MUNIÇÃO',    'image' => 'items/ammo-box.png',     'count' => null,  'cls' => 'white'],
        ['key' => '0', 'name' => null,         'image' => null,                     'count' => null,  'cls' => 'white'],
    ],

    'weight' => ['label' => 'PESO', 'current' => 36.5, 'max' => 60, 'unit' => 'kg'],

    'survival_title' => 'SOBREVIVÊNCIA',
    'survival' => [
        ['key' => 'energy',      'label' => 'ENERGIA',     'icon' => 'park',       'value' => 78,   'max' => 100,  'cls' => 'lime',  'unit' => '',   'dec' => 0],
        ['key' => 'hydration',   'label' => 'HIDRATAÇÃO',  'icon' => 'water_drop', 'value' => 56,   'max' => 100,  'cls' => 'cyan',  'unit' => '',   'dec' => 0],
        ['key' => 'hunger',      'label' => 'FOME',        'icon' => 'restaurant', 'value' => 62,   'max' => 100,  'cls' => 'amber', 'unit' => '',   'dec' => 0],
        ['key' => 'temperature', 'label' => 'TEMPERATURA', 'icon' => 'device_thermostat', 'value' => 36.6, 'max' => null, 'cls' => 'red',   'unit' => '°C', 'dec' => 1],
    ],

    'stats_title' => 'ESTATÍSTICAS',
    'stats' => [
        ['icon' => 'favorite',          'label' => 'SAÚDE',       'value' => '100', 'suffix' => '/100', 'col' => 'l'],
        ['icon' => 'accessibility_new', 'label' => 'RESISTÊNCIA', 'value' => '100', 'suffix' => '/100', 'col' => 'r'],
        ['icon' => 'speed',      'label' => 'VELOCIDADE',    'value' => 36.5,   'dec' => 1, 'suffix' => ' kg',  'col' => 'l'],
        ['icon' => 'radar',      'label' => 'DETECÇÃO',       'value' => '85',   'suffix' => ' %',   'col' => 'r'],
        ['icon' => 'volume_up',  'label' => 'NÍVEL DE RUÍDO', 'value' => '32',   'suffix' => ' dB',  'col' => 'l'],
    ],

    'condition_title' => 'CONDIÇÃO',
    'condition_image' => 'ui/body-wireframe-clean.png',
    'condition' => [
        ['part' => 'head',      'label' => 'CABEÇA',     'cur' => 35, 'max' => 35, 'side' => 'right', 'row' => 0],
        ['part' => 'thorax',    'label' => 'TÓRAX',      'cur' => 65, 'max' => 65, 'side' => 'right', 'row' => 1],
        ['part' => 'arm-left',  'label' => 'BRAÇO ESQ.', 'cur' => 60, 'max' => 80, 'side' => 'left',  'row' => 2],
        ['part' => 'arm-right', 'label' => 'BRAÇO DIR.', 'cur' => 60, 'max' => 80, 'side' => 'right', 'row' => 2],
        ['part' => 'leg-left',  'label' => 'PERNA ESQ.', 'cur' => 65, 'max' => 65, 'side' => 'left',  'row' => 3],
        ['part' => 'leg-right', 'label' => 'PERNA DIR.', 'cur' => 65, 'max' => 65, 'side' => 'right', 'row' => 3],
    ],
];
