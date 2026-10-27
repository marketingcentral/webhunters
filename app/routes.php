<?php declare(strict_types=1);

/**
 * Route table (SPEC §2). Format: [methods, pattern, Controller::class, 'action'].
 * `{param}` matches [a-z0-9-]{1,40}; `[/{param}]` is an optional trailing segment.
 * Anything not listed here is a 404 (Errors@notFound).
 */

use App\Controllers\AchievementsController;
use App\Controllers\AuthController;
use App\Controllers\BestiaryController;
use App\Controllers\BaseController;
use App\Controllers\ChallengesController;
use App\Controllers\FactionsController;
use App\Controllers\FriendsController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\LandingController;
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

// Painel administrativo: mesmos nomes de classe do jogo em outro namespace, daí os apelidos.
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\Admin\PlayersController as AdminPlayersController;
use App\Controllers\Admin\ServersController as AdminServersController;
use App\Controllers\Admin\BansController as AdminBansController;
use App\Controllers\Admin\ReportsController as AdminReportsController;
use App\Controllers\Admin\MissionsController as AdminMissionsController;
use App\Controllers\Admin\AchievementsController as AdminAchievementsController;
use App\Controllers\Admin\MapsController as AdminMapsController;
use App\Controllers\Admin\MapPointsController as AdminMapPointsController;
use App\Controllers\Admin\MapEditorController as AdminMapEditorController;
use App\Controllers\Admin\ItemsController as AdminItemsController;
use App\Controllers\Admin\EventsController as AdminEventsController;
use App\Controllers\Admin\ShopController as AdminShopController;
use App\Controllers\Admin\TransactionsController as AdminTransactionsController;
use App\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Controllers\Admin\LogsController as AdminLogsController;
use App\Controllers\Admin\UploadController as AdminUploadController;
use App\Controllers\Admin\ApiController as AdminApiController;

// API de gestão (/api/v1). Mesmo nome de classe do painel em outro namespace, daí o apelido.
use App\Controllers\Api\ApiController as ApiV1Controller;

return [
    ['GET',           '/',                        LandingController::class,      'index'],
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
    // Conforto de leitura, gravado na conta: o jogador afasta a arte para o texto da HUD
    // aparecer melhor. O padrão continua sendo o que o painel definiu para aquele mapa.
    ['POST',          '/mapa/opacidade',          MapController::class,          'opacidade'],
    ['GET',           '/conquistas[/{categoria}]', AchievementsController::class, 'index'],
    ['GET',           '/bestiario[/{grupo}]',     BestiaryController::class,     'index'],
    ['POST',          '/bestiario/registrar',     BestiaryController::class,     'registrar'],
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

    /**
     * PAINEL ADMINISTRATIVO (patch v6)
     *
     * Só entra aqui rota com controller no disco: uma linha apontando para classe que ainda
     * não existe vira LogicException do Router, ou seja, 500 — e um 500 é pior que um 404.
     *
     * A sidebar (app/Data/admin_nav.php) já aponta para as telas previstas; cada agente
     * acrescenta a SUA linha aqui junto com o controller. Previstas, nesta ordem:
     *
     *   ['GET',           '/admin/jogadores[/{id}]',  Admin\PlayersController::class,   'index']
     *   ['GET',           '/admin/missoes',           Admin\MissionsController::class,  'index']
     *   ['GET',           '/admin/conquistas',        Admin\AchievementsController::class, 'index']
     *   ['GET',           '/admin/mapas',             Admin\MapsController::class,      'index']
     *   ['GET',           '/admin/itens',             Admin\ItemsController::class,     'index']
     *   ['GET',           '/admin/loja',              Admin\ShopController::class,      'index']
     *   ['GET',           '/admin/eventos',           Admin\EventsController::class,    'index']
     *   ['GET',           '/admin/economia',          Admin\EconomyController::class,   'index']
     *   ['GET',           '/admin/servidores',        Admin\ServersController::class,   'index']
     *   ['GET',           '/admin/logs',              Admin\LogsController::class,      'index']
     *   [['GET','POST'],  '/admin/configuracoes',     Admin\SettingsController::class,  'index']
     */
    [['GET', 'POST'], '/admin/login',             AdminAuthController::class,      'login'],
    ['POST',          '/admin/sair',              AdminAuthController::class,      'logout'],
    ['GET',           '/admin',                   AdminDashboardController::class, 'index'],
    ['GET',           '/admin/jogadores',    AdminPlayersController::class, 'index'],
    ['GET',           '/admin/banimentos',   AdminBansController::class, 'index'],
    ['GET',           '/admin/relatorios',   AdminReportsController::class, 'index'],
    ['GET',           '/admin/transacoes',   AdminTransactionsController::class, 'index'],
    ['GET',           '/admin/servidores',      AdminServersController::class, 'index'],
    ['GET',           '/admin/missoes',         AdminMissionsController::class, 'index'],
    ['GET',           '/admin/conquistas',      AdminAchievementsController::class, 'index'],
    ['GET',           '/admin/mapas',           AdminMapsController::class, 'index'],
    // Submenu de Mapas. 'pontos' é o catálogo de ícones pré-fabricados; 'editor' é a tela
    // que arrasta esses ícones para cima da arte. As duas mexem em map_markers/map_point_types,
    // mas são tarefas distintas o bastante para não caberem na listagem de mapas.
    ['GET',           '/admin/mapas/pontos',    AdminMapPointsController::class, 'index'],
    ['GET',           '/admin/mapas/editor',    AdminMapEditorController::class, 'index'],
    ['GET',           '/admin/itens',           AdminItemsController::class, 'index'],
    ['GET',           '/admin/eventos',         AdminEventsController::class, 'index'],
    ['GET',           '/admin/loja',            AdminShopController::class, 'index'],
    ['GET',           '/admin/configuracoes',   AdminSettingsController::class, 'index'],
    ['GET',           '/admin/logs',            AdminLogsController::class, 'index'],

    // Gravação de cada módulo. Registradas de uma vez para nenhum módulo precisar mexer
    // nesta tabela depois; o controller de cada tela implementa salvar()/excluir().
    ['POST',          '/admin/jogadores/salvar',      AdminPlayersController::class, 'salvar'],
    ['POST',          '/admin/jogadores/excluir',     AdminPlayersController::class, 'excluir'],
    ['POST',          '/admin/servidores/salvar',     AdminServersController::class, 'salvar'],
    ['POST',          '/admin/servidores/excluir',    AdminServersController::class, 'excluir'],
    ['POST',          '/admin/banimentos/salvar',     AdminBansController::class, 'salvar'],
    ['POST',          '/admin/banimentos/excluir',    AdminBansController::class, 'excluir'],
    ['POST',          '/admin/relatorios/salvar',     AdminReportsController::class, 'salvar'],
    ['POST',          '/admin/relatorios/excluir',    AdminReportsController::class, 'excluir'],
    ['POST',          '/admin/missoes/salvar',        AdminMissionsController::class, 'salvar'],
    ['POST',          '/admin/missoes/excluir',       AdminMissionsController::class, 'excluir'],
    ['POST',          '/admin/conquistas/salvar',     AdminAchievementsController::class, 'salvar'],
    ['POST',          '/admin/conquistas/excluir',    AdminAchievementsController::class, 'excluir'],
    ['POST',          '/admin/mapas/salvar',          AdminMapsController::class, 'salvar'],
    ['POST',          '/admin/mapas/excluir',         AdminMapsController::class, 'excluir'],
    ['POST',          '/admin/mapas/pontos/salvar',   AdminMapPointsController::class, 'salvar'],
    ['POST',          '/admin/mapas/pontos/excluir',  AdminMapPointsController::class, 'excluir'],
    ['POST',          '/admin/mapas/editor/salvar',   AdminMapEditorController::class, 'salvar'],
    ['POST',          '/admin/itens/salvar',          AdminItemsController::class, 'salvar'],
    ['POST',          '/admin/itens/excluir',         AdminItemsController::class, 'excluir'],
    ['POST',          '/admin/eventos/salvar',        AdminEventsController::class, 'salvar'],
    ['POST',          '/admin/eventos/excluir',       AdminEventsController::class, 'excluir'],
    ['POST',          '/admin/loja/salvar',           AdminShopController::class, 'salvar'],
    ['POST',          '/admin/loja/excluir',          AdminShopController::class, 'excluir'],
    ['POST',          '/admin/transacoes/salvar',     AdminTransactionsController::class, 'salvar'],
    ['POST',          '/admin/transacoes/excluir',    AdminTransactionsController::class, 'excluir'],
    ['POST',          '/admin/configuracoes/salvar',  AdminSettingsController::class, 'salvar'],
    ['POST',          '/admin/configuracoes/excluir', AdminSettingsController::class, 'excluir'],
    ['POST',          '/admin/logs/salvar',           AdminLogsController::class, 'salvar'],
    ['POST',          '/admin/logs/excluir',          AdminLogsController::class, 'excluir'],

    // Envio de imagem dos campos de caminho (missões, mapas, eventos). Uma rota só para todas
    // as telas: a pasta de destino viaja no campo `entidade`, validado contra lista fechada no
    // controller. Responde JSON, inclusive nas recusas — é chamada por fetch/XHR, não por
    // formulário, para o operador não perder o que já digitou ao subir uma foto.
    ['POST',          '/admin/upload',                AdminUploadController::class, 'enviar'],

    // Chaves da API, log de requisições e a referência dos endpoints. Esta tela é do PAINEL:
    // exige sessão de administrador e CSRF, ao contrário de /api/v1 logo abaixo.
    // `revogar`, e não `excluir`: a chave nunca sai da tabela — o log de requisições aponta
    // para ela, e uma chave que some leva junto a explicação do que ela fez enquanto existiu.
    ['GET',           '/admin/api',                   AdminApiController::class, 'index'],
    ['POST',          '/admin/api/criar',             AdminApiController::class, 'criar'],
    ['POST',          '/admin/api/revogar',           AdminApiController::class, 'revogar'],

    /**
     * ======================================================================================
     * API DE GESTÃO — /api/v1 (patch v8)
     * ======================================================================================
     *
     * QUATRO LINHAS PARA A API INTEIRA, e não uma por endpoint. O motivo é o roteador: ele
     * casa caminho por caminho e o `{param}` dele aceita só [a-z0-9-], o que não cobre nem
     * um corpo JSON nem um caminho de profundidade variável. Declarar aqui os ~60 endpoints
     * criaria uma segunda tabela de rotas para manter em dia com a primeira — que é
     * App\Api\Recurso::rotas(), a mesma lista de onde sai a referência da tela /admin/api.
     * Aqui só se diz "tudo sob /api/v1 é do despachante"; ele decide o resto e responde 404
     * ou 405 em JSON, que é o que um cliente de API sabe ler (o 404 do roteador é HTML).
     *
     * Os dois padrões cobrem de 1 a 4 segmentos depois de /api/v1 — o mais fundo em uso é
     * /api/v1/maps/{id}/markers/{id}.
     *
     * ESTA PORTA NÃO TEM SESSÃO NEM CSRF, e isso é a decisão, não um esquecimento:
     *   - a sessão nem chega a ser aberta (ver a guarda no fim de app/bootstrap.php): sem ela,
     *     uma API chamada em laço não deixa um arquivo de sessão por requisição no disco;
     *   - CSRF protege NAVEGADOR autenticado por COOKIE contra um site de terceiros. Aqui a
     *     credencial é o cabeçalho Authorization, que nenhum site consegue fazer o navegador
     *     de outra pessoa enviar — o ataque que o token impede não existe nesta porta, e
     *     exigi-lo só impediria o cliente legítimo, que não tem de onde obtê-lo.
     */
    [['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/api/v1',                       ApiV1Controller::class, 'dispatch'],
    [['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/api/v1/{a}[/{b}]',             ApiV1Controller::class, 'dispatch'],
    [['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/api/v1/{a}/{b}/{c}[/{d}]',     ApiV1Controller::class, 'dispatch'],
];
