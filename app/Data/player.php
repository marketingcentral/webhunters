<?php declare(strict_types=1);

/** Jogador mock (SPEC §5). Sobrescrito parcialmente por $_SESSION['player'] após o login. */
return [
    'name'        => 'HUNTER_07',
    'level'       => 23,
    'xp'          => 12456,
    'xp_next'     => 18200,
    'avatar'      => 'portraits/op-assault.jpg',
    'clan'        => 'Resistência',
    'class'       => 'ASSALTO',
    'class_icon'  => 'class-assault',
    'currencies'  => ['credits' => 125500, 'cash' => 2450, 'points' => 1250],
    'friends_online' => 8,
    'unread_mail' => 3,
    'notifications' => 0,
    'season_level' => 45,
    'rank'        => 'CAÇADOR VETERANO',
    'status'      => 'online',
];
