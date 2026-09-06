<?php declare(strict_types=1);

/**
 * Tela SAFE ZONE (/safe-zone) — só cromo: títulos, rótulos e microcopy.
 *
 * As zonas (`safe_zones`), o papel ativo (`player_safe_roles`) e o veredito de cada papel vêm
 * do SafeZoneController; o catálogo de papéis é regra e vive em GameRules::SAFE_ROLES.
 */
return [
    'title'     => 'SAFE ZONE',
    'title_tag' => 'PERÍMETRO PROTEGIDO',
    'subtitle'  => 'Entre duas operações, o mundo continua girando dentro do muro. Aqui você guarda a arma e assume um papel: quem vende, quem conserta, quem ensina, quem trata, quem transporta, quem analisa.',
    'rule'      => 'Um papel por vez. O turno dura 45 minutos e pode ser encerrado a qualquer momento.',

    'zones_title'   => 'ZONAS CONHECIDAS',
    'roles_title'   => 'PAPÉIS DISPONÍVEIS',
    'shift_title'   => 'SEU TURNO',
    'zone_title'    => 'ZONA SELECIONADA',
    'services_title'=> 'ESTRUTURAS',
    'rules_title'   => 'REGRAS DA ZONA',

    'labels' => [
        'status'     => 'SITUAÇÃO',
        'occupancy'  => 'MOVIMENTO',
        'capacity'   => 'CAPACIDADE CIVIL',
        'protection' => 'PROTEÇÃO',
        'region'     => 'REGIÃO',
        'access'     => 'SEU ACESSO',
        'base'       => 'BASE',
        'requires'   => 'REQUISITO',
        'gain'       => 'O QUE RENDE',
        'served'     => 'ATENDIMENTOS',
        'ends_in'    => 'ENCERRA EM',
        'since'      => 'ABERTO ÀS',
    ],

    'cta' => [
        'assume'  => 'ASSUMIR PAPEL',
        'switch'  => 'TROCAR PARA ESTE PAPEL',
        'active'  => 'EM TURNO',
        'leave'   => 'ENCERRAR TURNO',
        'blocked' => 'INDISPONÍVEL',
        'operate' => 'VOLTAR À OPERAÇÃO',
    ],

    /* Estado vazio honesto: sem o patch v4 no banco, a tela diz isso em vez de inventar zonas. */
    'empty' => [
        'title' => 'NENHUMA SAFE ZONE MAPEADA',
        'desc'  => 'Este servidor ainda não tem perímetros civis registrados. Os papéis abaixo existem, mas só podem ser assumidos dentro de uma zona.',
    ],

    'survivor_notice' => 'Sem bandeira, nenhuma base do governo abre o portão. A ZONA FRANCA é a única que aceita quem está fora das cadeias de comando — e ninguém garante a trégua lá dentro.',
    'mercenary_notice'=> 'Sob contrato mercenário você entra nas zonas do governo como visitante: os papéis do Hunter Command ficam fora do seu alcance enquanto a Licença Cinza estiver ativa.',

    'back' => ['label' => 'IR PARA O MAPA', 'href' => 'mapa'],
];
