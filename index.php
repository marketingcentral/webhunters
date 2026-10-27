<?php declare(strict_types=1);

/**
 * Extract Hunters - front controller. Every non-file request is rewritten here (.htaccess).
 */

use App\Controllers\ErrorsController;
use App\Core\AccountGuard;
use App\Core\HttpException;
use App\Core\LoginGuard;
use App\Core\Maintenance;
use App\Core\Request;
use App\Core\Router;

require __DIR__ . '/app/bootstrap.php';

$request = Request::fromGlobals();

try {
    // Conta banida/suspensa não continua dentro do jogo nem com a sessão já aberta.
    // Aqui é o ÚNICO ponto por onde toda requisição passa antes de qualquer controller —
    // ver o porquê (e o custo) em App\Core\AccountGuard.
    $bloqueio = AccountGuard::vigiar($request);

    // Visitante anônimo não recebe tela nem rota de dado do jogo: /hud/dados, /perfil e
    // /inventario respondiam com o jogador de MENOR id do banco. Ver App\Core\LoginGuard.
    // Vem depois de AccountGuard de propósito: quem acabou de ser derrubado por banimento
    // já está sem sessão aqui, e a resposta que vale é a DELE (o motivo do banimento).
    $bloqueio ??= LoginGuard::vigiar($request);

    // Modo manutenção ligado em /admin/configuracoes fecha o jogo (menos /admin e /login).
    Maintenance::vigiar($request);

    $router = new Router();
    $router->load(require __DIR__ . '/app/routes.php');
    $response = $bloqueio ?? $router->dispatch($request);
} catch (HttpException $e) {
    $response = (new ErrorsController())->fromException($e, $request);
} catch (Throwable $e) {
    error_log(sprintf('[extracthunters] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $response = (new ErrorsController())->serverError($e, $request);
}

$response->send();
