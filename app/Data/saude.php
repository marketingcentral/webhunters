<?php declare(strict_types=1);

/**
 * Tela SAÚDE (referência 06-saude.png).
 *
 * Estrutura entregue ao controller (HealthController acrescenta `active_tab`):
 *   overview_title, body_image, vitals[], body_title, body[], body_action
 *   tabs[], healing_title, healing[], exams_title, exams[], conditions_title, conditions[]
 *   status_title, status_groups[]
 *   details_title, details{health,regen}, history_title, history[], history_action
 */
return [
    'overview_title' => 'VISÃO GERAL',
    /* PNG já recortado, com alpha: entra inteiro na moldura. O antigo
       ui/body-wireframe.png é 686x1317 com fundo preto e precisava de offsets
       negativos que cortavam o braço e a perna esquerdos. */
    'body_image'     => 'ui/body-wireframe-clean.png',
    'body_image_alt' => 'Silhueta do operador com pontos de dano',

    /* ---- VISÃO GERAL: 5 sinais vitais ---------------------------------- */
    'vitals' => [
        ['key' => 'health',      'label' => 'SAÚDE',       'icon' => 'add',            'value' => 100,  'max' => 100,  'unit' => '',   'status' => 'Totalmente saudável', 'cls' => 'lime', 'pct' => 100.0],
        ['key' => 'energy',      'label' => 'ENERGIA',     'icon' => 'bolt',           'value' => 78,   'max' => 100,  'unit' => '',   'status' => 'Boa disposição',      'cls' => 'lime', 'pct' => 78.0],
        ['key' => 'hydration',   'label' => 'HIDRATAÇÃO',  'icon' => 'water_drop',     'value' => 56,   'max' => 100,  'unit' => '',   'status' => 'Hidratado',           'cls' => 'cyan', 'pct' => 56.0],
        ['key' => 'hunger',      'label' => 'FOME',        'icon' => 'local_dining',   'value' => 62,   'max' => 100,  'unit' => '',   'status' => 'Levemente faminto',   'cls' => 'amber', 'pct' => 62.0],
        ['key' => 'temperature', 'label' => 'TEMPERATURA', 'icon' => 'device_thermostat',     'value' => 36.6, 'max' => null, 'unit' => '°C', 'status' => 'Normal',              'cls' => 'white', 'pct' => 0.0, 'dec' => 1],
    ],

    /* ---- ESTADO CORPORAL: 3 partes centrais + 4 membros ---------------- */
    'body_title' => 'ESTADO CORPORAL',
    'body' => [
        ['part' => 'head',      'label' => 'CABEÇA',     'cur' => 35, 'max' => 35, 'icon' => 'head',    'col' => 'a'],
        ['part' => 'thorax',    'label' => 'TÓRAX',      'cur' => 85, 'max' => 85, 'icon' => 'thorax',  'col' => 'a'],
        ['part' => 'stomach',   'label' => 'ESTÔMAGO',   'cur' => 70, 'max' => 70, 'icon' => 'stomach', 'col' => 'a'],
        ['part' => 'arm-left',  'label' => 'BRAÇO ESQ.', 'cur' => 60, 'max' => 60, 'icon' => null, 'col' => 'b'],
        ['part' => 'arm-right', 'label' => 'BRAÇO DIR.', 'cur' => 60, 'max' => 60, 'icon' => null, 'col' => 'b'],
        ['part' => 'leg-left',  'label' => 'PERNA ESQ.', 'cur' => 65, 'max' => 65, 'icon' => null, 'col' => 'b'],
        ['part' => 'leg-right', 'label' => 'PERNA DIR.', 'cur' => 65, 'max' => 65, 'icon' => null, 'col' => 'b'],
    ],
    'body_action' => ['label' => 'VER DETALHES DO CORPO', 'key' => 'X', 'href' => 'saude', 'query' => ['aba' => 'status']],

    /* ---- abas do painel central ---------------------------------------- */
    'tabs' => [
        ['slug' => 'tratamento', 'label' => 'TRATAMENTO'],
        ['slug' => 'status',     'label' => 'STATUS'],
    ],

    /* ---- ITENS DE CURA ------------------------------------------------- */
    'healing_title' => 'ITENS DE CURA',
    'healing' => [
        ['slug' => 'kit-medico',    'name' => 'KIT MÉDICO',    'image' => 'items/medkit.png',       'count' => 3, 'total' => 3, 'desc' => 'Restaura até 100 de saúde.',              'cta' => 'USAR', 'heal' => 100, 'time' => 4.0],
        ['slug' => 'bandagem',      'name' => 'BANDAGEM',      'image' => 'items/bandage.png',      'count' => 3, 'total' => 3, 'desc' => 'Restaura até 50 de saúde.',               'cta' => 'USAR', 'heal' => 50,  'time' => 2.5],
        ['slug' => 'analgesico',    'name' => 'ANALGÉSICO',    'image' => 'items/analgesic.png',    'count' => 3, 'total' => 3, 'desc' => 'Remove dor e restaura 25 de saúde.',      'cta' => 'USAR', 'heal' => 25,  'time' => 1.5],
        ['slug' => 'kit-cirurgico', 'name' => 'KIT CIRÚRGICO', 'image' => 'items/surgical-kit.png', 'count' => 1, 'total' => 2, 'desc' => 'Restaura até 200 de saúde. Uso demorado.', 'cta' => 'USAR', 'heal' => 200, 'time' => 9.0],
    ],

    /* ---- EXAMES MÉDICOS ------------------------------------------------ */
    'exams_title' => 'EXAMES MÉDICOS',
    'exams' => [
        ['slug' => 'analise-basica', 'name' => 'ANÁLISE BÁSICA', 'image' => 'items/clipboard.png',  'desc' => 'Verifique seu estado físico geral.',     'cta' => 'REALIZAR', 'result' => 'Estado físico estável'],
        ['slug' => 'raio-x',         'name' => 'RAIO-X',         'image' => 'items/xray.png',       'desc' => 'Verifica fraturas internas.',            'cta' => 'REALIZAR', 'result' => 'Nenhuma fratura detectada'],
        ['slug' => 'hemograma',      'name' => 'HEMOGRAMA',      'image' => 'items/blood-test.png', 'desc' => 'Verifica infecções e níveis de sangue.', 'cta' => 'REALIZAR', 'result' => 'Sangue dentro do normal'],
    ],
    'exam_running' => 'ANALISANDO',

    /* ---- DOENÇAS E CONDIÇÕES ------------------------------------------- */
    'conditions_title' => 'DOENÇAS E CONDIÇÕES',
    'conditions' => [
        ['icon' => 'verified_user', 'title' => 'NENHUMA',           'desc' => 'Você não possui doenças ou condições ativas.',       'level' => 'ok'],
        ['icon' => 'warning', 'title' => 'RISCO DE INFECÇÃO', 'desc' => 'Ferimentos abertos podem infectar se não tratados.', 'level' => 'warn'],
    ],

    /* ---- aba STATUS ---------------------------------------------------- */
    'status_title' => 'STATUS',
    'status_groups' => [
        [
            'title' => 'SINAIS VITAIS',
            'kind'  => 'tiles',
            'items' => [
                ['label' => 'BATIMENTOS',  'value' => 72,   'unit' => ' bpm',  'icon' => 'ecg_heart', 'cls' => 'lime',  'caption' => 'Em repouso'],
                ['label' => 'SATURAÇÃO',   'value' => 98,   'unit' => '%',     'icon' => 'pulmonology',   'cls' => 'lime',  'caption' => 'Oxigenação boa'],
                ['label' => 'PRESSÃO',     'value' => '12/8', 'unit' => '',    'icon' => 'speed',         'cls' => 'lime',  'caption' => 'Normotenso'],
                ['label' => 'TEMPERATURA', 'value' => 36.6, 'unit' => '°C',   'icon' => 'thermostat',    'cls' => 'white', 'caption' => 'Sem febre', 'dec' => 1],
            ],
        ],
        [
            'title' => 'RESISTÊNCIAS',
            'kind'  => 'bars',
            'items' => [
                ['label' => 'TOXINAS',     'value' => 0,  'max' => 100, 'cls' => 'lime',  'caption' => 'Nenhum agente ativo'],
                ['label' => 'RADIAÇÃO',    'value' => 4,  'max' => 100, 'cls' => 'lime',  'caption' => 'Exposição desprezível'],
                ['label' => 'INFECÇÃO',    'value' => 12, 'max' => 100, 'cls' => 'amber', 'caption' => 'Ferimentos recentes'],
                ['label' => 'SANGRAMENTO', 'value' => 0,  'max' => 100, 'cls' => 'lime',  'caption' => 'Contido'],
                ['label' => 'FADIGA',      'value' => 22, 'max' => 100, 'cls' => 'cyan',  'caption' => 'Leve cansaço'],
            ],
        ],
        [
            'title' => 'EFEITOS ATIVOS',
            'kind'  => 'rows',
            'items' => [
                ['icon' => 'local_dining', 'title' => 'BEM ALIMENTADO', 'desc' => 'Regeneração natural +0,3/s.',      'meta' => '12:40', 'cls' => 'lime',  'left' => 760],
                ['icon' => 'water_drop',  'title' => 'HIDRATADO',      'desc' => 'Consumo de energia -8%.',          'meta' => '08:15', 'cls' => 'cyan',  'left' => 495],
                ['icon' => 'bolt',        'title' => 'FADIGA LEVE',    'desc' => 'Estamina máxima -4%.',             'meta' => '03:20', 'cls' => 'amber', 'left' => 200],
            ],
        ],
    ],

    /* ---- DETALHES ------------------------------------------------------ */
    'details_title' => 'DETALHES',
    'details' => [
        'health' => [
            'label' => 'SAÚDE',
            'value' => 100,
            'max'   => 100,
            'icon'  => 'add',
            'desc_lines' => [
                'Sua saúde representa seu estado físico geral.',
                'Quando chegar a 0, você será incapacitado.',
            ],
        ],
        'regen' => [
            'label' => 'REGENERAÇÃO',
            'value' => 0.8,
            'unit'  => '/s',
            'icon'  => 'ecg_heart',
            'desc'  => 'Regeneração natural de saúde.',
        ],
    ],

    /* ---- ÚLTIMOS TRATAMENTOS ------------------------------------------- */
    'history_title' => 'ÚLTIMOS TRATAMENTOS',
    'history' => [
        ['name' => 'KIT MÉDICO', 'image' => 'items/medkit.png',    'time' => '18:24', 'effect' => '+100 SAÚDE', 'value' => 100],
        ['name' => 'BANDAGEM',   'image' => 'items/bandage.png',   'time' => '09:47', 'effect' => '+50 SAÚDE',  'value' => 50],
        ['name' => 'ANALGÉSICO', 'image' => 'items/analgesic.png', 'time' => '09:30', 'effect' => '+25 SAÚDE',  'value' => 25],
    ],
    'history_action' => ['label' => 'HISTÓRICO COMPLETO', 'key' => 'H', 'href' => 'perfil'],
];
