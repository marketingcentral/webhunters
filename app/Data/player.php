<?php declare(strict_types=1);

/** Jogador mock (SPEC §5). Sobrescrito parcialmente por $_SESSION['player'] após o login. */
return [
    'name'        => 'HUNTER_07',
    'level'       => 23,
    'xp'          => 12456,
    'xp_next'     => 18200,
    'avatar'      => 'portraits/op-assault.jpg',
    'clan'        => 'Hunter Command',
    'class'       => 'ASSAULT',
    'class_icon'  => 'class-assault',
    // Mesma forma que OperationalRepo::forUser() devolve. É o fallback sem banco, então fica
    // literal (sem chamar classe): um único personagem, operando como Hunter.
    'operational' => [
        'state'          => 'HUNTER',
        'rank'           => 'RECRUTA',
        'role'           => 'ASSAULT',
        'faction'        => null,
        'reputation'     => ['aegis' => 0, 'frontier' => 0],
        'tiers'          => ['aegis' => 'SEM CONTRATO', 'frontier' => 'SEM CONTRATO'],
        'locked'         => ['aegis' => null, 'frontier' => null],
        'base_pref'      => 'alpha',
        'survivor_since' => null,
    ],
    'currencies'  => ['credits' => 125500, 'cash' => 2450, 'points' => 1250],
    'friends_online' => 8,
    'unread_mail' => 3,
    'notifications' => 0,
    'season_level' => 45,
    'rank'        => 'CAÇADOR VETERANO',
    'status'      => 'online',
];
