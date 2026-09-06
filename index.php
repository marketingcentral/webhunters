<?php declare(strict_types=1);

/**
 * Extract Hunters - front controller. Every non-file request is rewritten here (.htaccess).
 */

use App\Controllers\ErrorsController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;

require __DIR__ . '/app/bootstrap.php';

$request = Request::fromGlobals();

try {
    $router = new Router();
    $router->load(require __DIR__ . '/app/routes.php');
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = (new ErrorsController())->fromException($e, $request);
} catch (Throwable $e) {
    error_log(sprintf('[extracthunters] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $response = (new ErrorsController())->serverError($e, $request);
}

$response->send();
