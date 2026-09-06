<?php declare(strict_types=1);

/**
 * Application bootstrap: autoload, config, helpers, error handling, session.
 * Required once by index.php (and only by index.php).
 */

use App\Core\Config;
use App\Core\Security;

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('PHP 8.2+ é necessário.');
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
ini_set('expose_php', '0');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Sao_Paulo');
setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil.1252', 'C');

/** PSR-4-ish autoloader: App\Core\Router -> app/Core/Router.php */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::boot(dirname(__DIR__));
require __DIR__ . '/Core/helpers.php';

/** Promote warnings/notices to exceptions so nothing half-renders silently. */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

Security::startSession();
