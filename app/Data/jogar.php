<?php declare(strict_types=1);

/**
 * Tela OPERAÇÃO (era "JOGAR SOLO / MULTIPLAYER"). PRD v3 §17.
 *
 * Só CROMO mora aqui: títulos, rótulos, microcopy e a forma dos blocos. Estado do jogador
 * (Hunter/Mercenário/Sobrevivente), regiões, bases governamentais e capacidade vêm do banco
 * pelo PlayController. Regiões operacionais = maps kind=match (têm o level da Unreal).
 */
return [
    'title'    => 'OPERAÇÃO',
    'subtitle' => 'MUNDO PERSISTENTE',
    'intro'    => 'Entre em uma região persistente como Hunter ou Mercenário.',

    /* ---- modos operacionais (coluna esquerda) --------------------------------------
       Não são "modos de jogo": são a bandeira sob a qual o MESMO personagem entra. O
       controller preenche `desc` do Mercenário com o contrato ativo e só inclui o
       Sobrevivente quando o estado for esse (nunca selecionável por vontade). */
    'modes_title' => 'STATUS OPERACIONAL',
    'modes' => [
        ['slug' => 'hunter',    'icon' => 'jg-quick', 'icon_type' => 'svg', 'title' => 'HUNTER COMMAND',
         'desc' => 'Mobilize com sua unidade, siga a hierarquia e cumpra ordens oficiais.', 'locked' => false, 'active' => true],
        ['slug' => 'mercenary', 'icon' => 'jg-hunt',  'icon_type' => 'svg', 'title' => 'MERCENÁRIO',
         'desc' => 'Atue para sua facção e acesse operações, rotas e recursos exclusivos.', 'locked' => false, 'active' => false],
        ['slug' => 'factions',  'icon' => 'jg-dom',   'icon_type' => 'svg', 'title' => 'FACÇÕES',
         'desc' => 'Gerencie contrato e reputação com Aegis ou Frontier.', 'locked' => false, 'active' => false, 'href' => 'faccoes'],
    ],
    /* O estado Sobrevivente aparece só quando ativo (PRD §17.2). */
    'survivor_mode' => ['slug' => 'survivor', 'icon' => 'jg-zone', 'icon_type' => 'svg', 'title' => 'SOBREVIVENTE',
        'desc' => 'STATUS ATIVO — sem extração normal. Retorne ao mundo e sobreviva.', 'locked' => false, 'active' => true, 'survivor' => true],

    /* ---- hero central por estado (PRD §17.4–17.6) ------------------------------------ */
    'heroes' => [
        'hunter' => [
            'title' => 'MOBILIZAÇÃO HUNTER',
            'desc'  => 'Selecione uma Base Governamental e entre no setor como membro do Hunter Command.',
            'cta'   => 'MOBILIZAR',
            'image' => 'bg/jogar-banner.jpg',
        ],
        'mercenary' => [
            'title' => 'CONTRATO MERCENÁRIO',
            'desc'  => 'Entre no mesmo mundo sob os interesses da facção contratada.',
            'cta'   => 'ATUAR COMO MERCENÁRIO',
            'image' => 'bg/jogar-banner.jpg',
        ],
        'survivor' => [
            'title' => 'SOBREVIVENTE ATIVO',
            'desc'  => 'Você perdeu a proteção das estruturas organizadas. Não há extração normal disponível. As faixas brancas dizem ao mundo: ninguém responde por você.',
            'cta'   => 'RETORNAR AO MUNDO',
            'image' => 'bg/jogar-banner.jpg',
        ],
        'factions' => [
            'title' => 'FACÇÕES',
            'desc'  => 'Aegis compra força. Frontier compra confiança. Só uma pode ter o seu contrato — e trocar zera a reputação da abandonada.',
            'cta'   => 'GERENCIAR CONTRATO',
            'image' => 'bg/jogar-banner.jpg',
        ],
    ],
    'hero_labels' => [
        'rank' => 'PATENTE', 'role' => 'FUNÇÃO', 'base' => 'BASE', 'squad' => 'ESQUADRÃO', 'region' => 'REGIÃO',
        'activity' => 'ATIVIDADE', 'faction' => 'FACÇÃO', 'rep' => 'REPUTAÇÃO', 'tier' => 'FAIXA', 'contracts' => 'CONTRATOS',
        'known_pos' => 'ÚLTIMA POSIÇÃO', 'since' => 'NO ESTADO HÁ', 'field_base' => 'BASE DE CAMPO', 'condition' => 'CONDIÇÃO',
    ],

    /* ---- regiões operacionais (era SELEÇÃO DE MAPAS) --------------------------------- */
    'maps_title'  => 'REGIÕES OPERACIONAIS',
    'maps_action' => 'VER TODAS AS REGIÕES',
    'map_labels'  => ['infest' => 'INFESTAÇÃO', 'human' => 'ATIVIDADE HUMANA', 'world' => 'MUNDO', 'persistent' => 'PERSISTENTE'],
    /* Símbolo local (defs de pages/jogar.php) no hexágono de cada card; sem modo, cai no skull. */
    'mode_icons' => [
        'MATA-MATA EM EQUIPE' => 'jg-team',
        'DOMINAÇÃO'           => 'jg-hx-shield',
        'ZONA DE CONFLITO'    => 'jg-hx-target',
    ],

    /* Capacidade do setor (PRD §17.9): estrutura, não ocupação exata. */
    'capacity_labels' => ['title' => 'CAPACIDADE DO SETOR', 'structure' => 'ESTRUTURA', 'hunters' => 'HUNTERS', 'mercs' => 'ATÉ %d MERCENÁRIOS'],

    /* ---- preferências (PRD §17.8): sem "filtro de habilidade" ----------------------------- */
    'prefs_title' => 'PREFERÊNCIAS DE MOBILIZAÇÃO',
    'prefs' => [
        ['key' => 'base',      'label' => 'BASE GOVERNAMENTAL',      'type' => 'select', 'value' => 'alpha', 'text' => 'ALPHA', 'options' => ['ALPHA', 'BRAVO', 'CHARLIE']],
        ['key' => 'role',      'label' => 'PREFERÊNCIA DE FUNÇÃO',   'type' => 'select', 'value' => 'ASSAULT', 'text' => 'ASSAULT', 'options' => ['ASSAULT', 'SNIPER', 'MEDICAL', 'RASTREADOR']],
        ['key' => 'fill',      'label' => 'PREENCHIMENTO DE SQUAD',  'type' => 'toggle', 'value' => true,  'text' => 'Ativado'],
        ['key' => 'crossplay', 'label' => 'CROSSPLAY',               'type' => 'toggle', 'value' => true,  'text' => 'Ativado'],
        ['key' => 'region',    'label' => 'REGIÃO DO SERVIDOR',      'type' => 'select', 'value' => 'sa',  'text' => 'América do Sul (32ms)',
         'options' => ['América do Sul (32ms)', 'América do Norte (86ms)', 'Europa (154ms)', 'Ásia (212ms)']],
    ],
    'cta'       => 'MOBILIZAR',
    'eta_label' => 'INSERÇÃO ESTIMADA:',
    'eta'       => '< 30s',

    'squad' => [
        'title'      => 'ESQUADRÃO',
        'count'      => 1,
        'max'        => 4,
        'add_label'  => 'CONVIDAR HUNTER',
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

    /* ---- SIMULAÇÕES DE COMBATE (PRD §17.3): saem do foco, o código fica. Renderizadas no P2. */
    'simulations_title' => 'SIMULAÇÕES DE COMBATE',
    'simulations' => [
        ['slug' => 'mata-mata',        'icon' => 'jg-team', 'icon_type' => 'svg', 'title' => 'MATA-MATA EM EQUIPE', 'desc' => 'Elimine a equipe adversária.',        'locked' => true],
        ['slug' => 'dominacao',        'icon' => 'jg-dom',  'icon_type' => 'svg', 'title' => 'DOMINAÇÃO',           'desc' => 'Capture e defenda os objetivos.',   'locked' => true],
        ['slug' => 'zona-de-conflito', 'icon' => 'jg-zone', 'icon_type' => 'svg', 'title' => 'ZONA DE CONFLITO',    'desc' => 'Controle a zona e ganhe pontos.',   'locked' => true],
        ['slug' => 'caca',             'icon' => 'jg-hunt', 'icon_type' => 'svg', 'title' => 'CAÇA',                'desc' => 'Elimine alvos e complete contratos.', 'locked' => true],
    ],
];
