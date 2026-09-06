<?php declare(strict_types=1);

/**
 * Route table (SPEC §2). Format: [methods, pattern, Controller::class, 'action'].
 * `{param}` matches [a-z0-9-]{1,40}; `[/{param}]` is an optional trailing segment.
 * Anything not listed here is a 404 (Errors@notFound).
 */

use App\Controllers\AchievementsController;
use App\Controllers\AuthController;
use App\Controllers\BaseController;
use App\Controllers\ChallengesController;
use App\Controllers\FactionsController;
use App\Controllers\FriendsController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\HudController;
use App\Controllers\InventoryController;
use App\Controllers\LobbyController;
use App\Controllers\MapController;
use App\Controllers\MessagesController;
use App\Controllers\MissionsController;
use App\Controllers\NewsController;
use App\Controllers\PlayController;
use App\Controllers\ProfileController;
use App\Controllers\SafeZoneController;
use App\Controllers\SettingsController;
use App\Controllers\ShopController;
use App\Controllers\SkillsController;
use App\Controllers\SupportController;

return [
    ['GET',           '/',                        AuthController::class,         'root'],
    [['GET', 'POST'], '/login',                   AuthController::class,         'login'],
    [['GET', 'POST'], '/criar-conta',             AuthController::class,         'register'],
    [['GET', 'POST'], '/recuperar-senha',         AuthController::class,         'recover'],
    ['POST',          '/sair',                    AuthController::class,         'logout'],

    ['GET',           '/inicio',                  HomeController::class,         'index'],
    ['GET',           '/jogar',                   PlayController::class,         'index'],
    ['GET',           '/mapas',                   PlayController::class,         'maps'],
    ['GET',           '/lobby',                   LobbyController::class,        'index'],
    ['POST',          '/lobby/chat',              LobbyController::class,        'chat'],
    [['GET', 'POST'], '/configuracoes[/{aba}]',   SettingsController::class,     'index'],
    // HUD in-game (overlay transparente carregado pelo WBP_HuntersWeb na Unreal)
    ['GET',           '/hud',                     HudController::class,          'index'],
    ['GET',           '/hud/dados',               HudController::class,          'data'],
    ['POST',          '/hud/municao',             HudController::class,          'ammo'],
    ['GET',           '/inventario',              InventoryController::class,    'index'],
    ['GET',           '/inventario/dados',        InventoryController::class,    'tree'],
    ['POST',          '/inventario/mover',        InventoryController::class,    'move'],
    ['POST',          '/inventario/equipar',      InventoryController::class,    'equip'],
    ['POST',          '/inventario/acessorio',    InventoryController::class,    'attach'],
    ['GET',           '/saude',                   HealthController::class,       'index'],
    ['POST',          '/habilidades/aprender',    SkillsController::class,        'learn'],
    ['POST',          '/habilidades/redefinir',   SkillsController::class,        'reset'],
    ['GET',           '/habilidades[/{categoria}]', SkillsController::class,     'index'],
    ['GET',           '/mapa[/{regiao}]',         MapController::class,          'index'],
    ['GET',           '/conquistas[/{categoria}]', AchievementsController::class, 'index'],
    ['GET',           '/missoes',                 MissionsController::class,     'index'],
    ['POST',          '/desafios/resgatar',       ChallengesController::class,   'claim'],
    ['GET',           '/desafios[/{aba}]',        ChallengesController::class,   'index'],
    ['GET',           '/noticias',                NewsController::class,         'index'],
    ['GET',           '/noticias/{slug}',         NewsController::class,         'show'],
    ['GET',           '/loja',                    ShopController::class,         'index'],
    ['POST',          '/loja/comprar',            ShopController::class,         'buy'],
    ['GET',           '/amigos[/{aba}]',          FriendsController::class,      'index'],
    ['POST',          '/amigos',                  FriendsController::class,      'store'],
    ['GET',           '/mensagens[/{conversa}]',  MessagesController::class,     'index'],
    ['POST',          '/mensagens/responder',     MessagesController::class,     'reply'],
    ['POST',          '/mensagens/lida',          MessagesController::class,     'read'],
    ['GET',           '/perfil',                  ProfileController::class,      'index'],
    // PRD v3 — facções (contrato Aegis/Frontier) e Base de Campo. Fora da topbar de propósito.
    ['GET',           '/faccoes',                 FactionsController::class,     'index'],
    ['POST',          '/faccoes/assinar',         FactionsController::class,     'sign'],
    ['POST',          '/faccoes/romper',          FactionsController::class,     'release'],
    ['GET',           '/base',                    BaseController::class,         'index'],
    // SAFE ZONE — papéis civis entre operações (patch v4). POST muda estado: passa por CSRF.
    ['POST',          '/safe-zone/assumir',       SafeZoneController::class,     'assume'],
    ['POST',          '/safe-zone/encerrar',      SafeZoneController::class,     'leave'],
    ['GET',           '/safe-zone[/{zona}]',      SafeZoneController::class,     'index'],
    [['GET', 'POST'], '/suporte',                 SupportController::class,      'index'],
    ['GET',           '/relatorio-de-bug/{code}', SupportController::class,      'bugDetail'],
    [['GET', 'POST'], '/relatorio-de-bug',        SupportController::class,      'bug'],
];
